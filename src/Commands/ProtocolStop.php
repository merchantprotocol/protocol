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
        $runner->run('Stopping watchers', function() use ($app, $arrInput, $nullOutput, $strategy) {
            if ($strategy === 'release') {
                $app->find('deploy:slave:stop')->run($arrInput, $nullOutput);
            } else {
                $app->find('git:slave:stop')->run($arrInput, $nullOutput);
            }

            // Always stop config watcher
            $app->find('config:slave:stop')->run($arrInput, $nullOutput);
        });

        // ── Stage 2: Unlinking configuration ────────────────────
        $runner->run('Unlinking configuration', function() use ($app, $arrInput, $nullOutput, $repo_dir) {
            if (Json::read('configuration.remote', false, $repo_dir)) {
                $app->find('config:unlink')->run($arrInput, $nullOutput);
            }
        });

        // ── Stage 3: Stopping containers ────────────────────────
        $runner->run('Stopping containers', function() use ($repo_dir) {
            // Shadow mode: stop containers in all release directories
            if (BlueGreen::isEnabled($repo_dir)) {
                foreach (BlueGreen::listReleases($repo_dir) as $release) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $release);
                    if (is_dir($releaseDir)) {
                        BlueGreen::stopContainers($releaseDir);
                    }
                }
                return;
            }

            // Standard mode
            $composePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
            if (!file_exists($composePath)) return;

            $dockerCommand = Docker::getDockerCommand();
            Shell::run("cd " . escapeshellarg($repo_dir) . " && {$dockerCommand} down 2>&1");
        });

        // ── Stage 4: Removing crontab ───────────────────────────
        $runner->run('Removing crontab entry', function() use ($repo_dir) {
            Crontab::removeCrontabRestart($repo_dir);
        });

        // ── Stage 5: Verifying shutdown ─────────────────────────
        $runner->run('Verifying shutdown', function() use ($repo_dir, $strategy) {
            // Verify containers are stopped
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
            foreach ($containerNames as $name) {
                if (Docker::isDockerContainerRunning($name)) {
                    throw new \RuntimeException("Container '{$name}' is still running");
                }
            }

            // Verify watchers are stopped
            if ($strategy === 'release') {
                $pid = JsonLock::read('release.slave.pid', null, $repo_dir);
                if ($pid && Shell::isRunning($pid)) {
                    throw new \RuntimeException('Release watcher is still running');
                }
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $environment = Config::read('env', 'unknown');

        $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
        $stoppedCount = 0;
        foreach ($containerNames as $name) {
            if (!Docker::isDockerContainerRunning($name)) $stoppedCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$stoppedCount}/{$containerTotal} stopped"
            : 'none configured';

        $cronStatus = Crontab::hasCrontabRestart($repo_dir) ? 'still installed' : 'removed';

        $watcherPidKey = $strategy === 'release' ? 'release.slave.pid' : 'slave.pid';
        $watcherPid = JsonLock::read($watcherPidKey, null, $repo_dir);
        $watcherStatus = (!$watcherPid || !Shell::isRunning($watcherPid)) ? 'stopped' : 'still running';

        $runner->writeSummary([
            'Environment' => $environment,
            'Containers'  => $containerStatus,
            'Watchers'    => $watcherStatus,
            'Crontab'     => $cronStatus,
        ], 'Shutdown complete.', 'Shutdown completed with issues.');

        return Command::SUCCESS;
    }

}
