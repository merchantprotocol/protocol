<?php
/**
 * NOTICE OF LICENSE
 *
 * MIT License
 *
 * Copyright (c) 2019 Merchant Protocol
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 *
 * @category   merchantprotocol
 * @package    merchantprotocol/protocol
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Crontab;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\StageRunner;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

Class ProtocolStop extends Command {

    use LockableTrait;

    protected static $defaultName = 'stop';
    protected static $defaultDescription = 'Stops running slave modes';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Stops slave mode from running

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('project', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $nodeConfig = null;
        $nodeData = [];

        // Detect slave node mode so stop works from anywhere
        $projectArg = $input->getArgument('project');
        $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        if ($resolved) {
            [$nodeConfig, $nodeData, $activeDir] = $resolved;
            $repo_dir = $activeDir;
        }

        // For non-slave nodes, require a git repo
        if (!$nodeConfig) {
            Git::checkInitializedRepo($output, $repo_dir);
        }

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $arrInput = new ArrayInput(['--dir' => $repo_dir]);
        $nullOutput = new NullOutput();
        $app = $this->getApplication();
        $strategy = $nodeConfig
            ? ($nodeData['deployment']['strategy'] ?? 'branch')
            : Json::read('deployment.strategy', 'branch', $repo_dir);

        $output->writeln('');

        $runner = new StageRunner($output);

        // ── Stage 1: Stopping watchers ──────────────────────────
        $runner->run('Stopping watchers', function() use ($app, $arrInput, $nullOutput) {
            // Stop all watchers regardless of strategy
            try { $app->find('deploy:slave:stop')->run($arrInput, $nullOutput); } catch (\Throwable $e) {}
            try { $app->find('git:slave:stop')->run($arrInput, $nullOutput); } catch (\Throwable $e) {}
            try { $app->find('config:slave:stop')->run($arrInput, $nullOutput); } catch (\Throwable $e) {}
        });

        // ── Stage 2: Unlinking configuration ────────────────────
        $runner->run('Unlinking configuration', function() use ($app, $arrInput, $nullOutput, $repo_dir) {
            if (Json::read('configuration.remote', false, $repo_dir)) {
                $app->find('config:unlink')->run($arrInput, $nullOutput);
            }
        });

        // ── Stage 3: Stopping containers ────────────────────────
        $runner->run('Stopping containers', function() use ($repo_dir) {
            $dirs = DeploymentState::allKnownDirs($repo_dir);
            foreach ($dirs as $dir) {
                $dockerCommand = Docker::getDockerCommand();
                Shell::run("cd " . escapeshellarg($dir) . " && {$dockerCommand} down 2>&1");
            }

            // Also stop blue-green releases if enabled
            if (BlueGreen::isEnabled($repo_dir)) {
                foreach (BlueGreen::listReleases($repo_dir) as $release) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $release);
                    if (is_dir($releaseDir)) {
                        BlueGreen::stopContainers($releaseDir);
                    }
                }
            }
        });

        // ── Stage 4: Removing crontab ───────────────────────────
        $runner->run('Removing crontab entry', function() use ($repo_dir) {
            Crontab::removeCrontabRestart($repo_dir);
        });

        // ── Stage 5: Verifying shutdown ─────────────────────────
        $runner->run('Verifying shutdown', function() use ($repo_dir) {
            // Verify containers are stopped in all known dirs
            $dirs = DeploymentState::allKnownDirs($repo_dir);
            foreach ($dirs as $dir) {
                $composePath = rtrim($dir, '/') . '/docker-compose.yml';
                if (file_exists($composePath)) {
                    $containerNames = Docker::getContainerNamesFromDockerComposeFile($dir);
                    foreach ($containerNames as $name) {
                        if (Docker::isDockerContainerRunning($name)) {
                            throw new \RuntimeException("Container '{$name}' is still running");
                        }
                    }
                }
            }

            // Verify watcher is stopped
            if (DeploymentState::isWatcherRunning($repo_dir)) {
                throw new \RuntimeException('Deployment watcher is still running');
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $environment = Config::read('env', 'unknown');

        $containerNames = [];
        foreach (DeploymentState::allKnownDirs($repo_dir) as $dir) {
            $composePath = rtrim($dir, '/') . '/docker-compose.yml';
            if (file_exists($composePath)) {
                $containerNames = array_merge($containerNames, Docker::getContainerNamesFromDockerComposeFile($dir));
            }
        }
        $stoppedCount = 0;
        foreach ($containerNames as $name) {
            if (!Docker::isDockerContainerRunning($name)) $stoppedCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$stoppedCount}/{$containerTotal} stopped"
            : 'none configured';

        $cronStatus = Crontab::hasCrontabRestart($repo_dir) ? 'still installed' : 'removed';

        $watcherStatus = DeploymentState::isWatcherRunning($repo_dir) ? 'still running' : 'stopped';

        $runner->writeSummary([
            'Environment' => $environment,
            'Containers'  => $containerStatus,
            'Watchers'    => $watcherStatus,
            'Crontab'     => $cronStatus,
        ], 'Shutdown complete.', 'Shutdown completed with issues.');

        return Command::SUCCESS;
    }

}
