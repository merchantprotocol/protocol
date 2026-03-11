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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Crontab;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\StageRunner;
use Gitcd\Helpers\SecurityAudit;
use Gitcd\Helpers\Soc2Check;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ProtocolStart extends Command {

    use LockableTrait;

    protected static $defaultName = 'start';
    protected static $defaultDescription = 'Starts a node so that the repo and docker image stay up to date and are running';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Start up a new node. The repository will be updated and become a slave, updating whenever the remote repo updates. The latest docker image will be pulled down and started up.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
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
        Git::checkInitializedRepo( $output, $repo_dir );

        $helper = $this->getHelper('question');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        // get the correct environment
        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment) {
            $question = new Question('What is the current env we need to configure protocol for globally? This must be set:', 'localhost');
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        }

        $devEnvs = ['localhost', 'local', 'dev', 'development'];
        $isDev = (in_array($environment, $devEnvs) || strpos($environment, 'localhost') !== false);

        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        // Prepare sub-command inputs
        $arrInput = new ArrayInput(['--dir' => $repo_dir]);
        $arrInput1 = new ArrayInput(['--dir' => $repo_dir, 'environment' => $environment]);
        $nullOutput = new NullOutput();
        $app = $this->getApplication();

        $output->writeln('');

        $runner = new StageRunner($output);

        // ── Stage 1: Scanning codebase ──────────────────────────
        $runner->run('Scanning codebase', function() use ($repo_dir, $environment, $strategy) {
            // Validate the repo is initialized
            if (!Git::isInitializedRepo($repo_dir)) {
                throw new \RuntimeException('Not an initialized Protocol project');
            }

            // Validate docker-compose.yml exists
            if (!Docker::isDockerInitialized($repo_dir)) {
                throw new \RuntimeException('No docker-compose.yml found');
            }
        });

        // ── Stage 2: Infrastructure provisioning ────────────────
        $runner->run('Infrastructure provisioning', function() use ($app, $arrInput, $arrInput1, $nullOutput, $repo_dir, $environment, $strategy, $isDev) {

            if ($strategy === 'release') {
                // Release-based deployment mode
                if (Json::read('configuration.remote', false, $repo_dir)) {
                    $app->find('config:init')->run($arrInput1, $nullOutput);
                    $app->find('config:slave')->run($arrInput, $nullOutput);
                    $app->find('config:link')->run($arrInput, $nullOutput);
                }

                // Start the release watcher daemon
                $app->find('deploy:slave')->run($arrInput, $nullOutput);

            } else {
                // Legacy branch-based deployment mode
                if (!$isDev) {
                    $app->find('git:pull')->run($arrInput, $nullOutput);
                    $app->find('git:slave')->run($arrInput, $nullOutput);
                }

                if (Json::read('configuration.remote', false, $repo_dir)) {
                    $app->find('config:init')->run($arrInput1, $nullOutput);

                    if (!$isDev) {
                        $app->find('config:slave')->run($arrInput, $nullOutput);
                    }

                    $app->find('config:link')->run($arrInput, $nullOutput);
                }
            }

            // Add crontab restart command
            Crontab::addCrontabRestart($repo_dir);
        });

        // ── Stage 3: Container build & start ────────────────────
        $runner->run('Container build & start', function() use ($repo_dir) {
            // Shadow mode: start the active version's containers
            if (BlueGreen::isEnabled($repo_dir)) {
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    if (is_dir($releaseDir)) {
                        BlueGreen::startContainers($releaseDir);
                        return;
                    }
                }
                // No active version yet — fall through to standard build
            }

            // Standard single-container mode
            $composePath = rtrim($repo_dir, '/') . '/docker-compose.yml';

            if (!file_exists($composePath)) {
                return; // No docker-compose.yml, nothing to do
            }

            // Pull or build the Docker image
            $usesBuild = false;
            $content = file_get_contents($composePath);
            $usesBuild = (bool) preg_match('/^\s+build:/m', $content);

            if ($usesBuild) {
                Shell::run("docker compose -f " . escapeshellarg($composePath) . " build 2>&1");
            } else {
                $image = Json::read('docker.image', false, $repo_dir);
                if ($image) {
                    Shell::run("docker pull " . escapeshellarg($image) . " 2>&1");
                }
            }

            // Rebuild containers
            $dockerCommand = Docker::getDockerCommand();
            Shell::run("cd " . escapeshellarg($repo_dir) . " && {$dockerCommand} up --build -d 2>&1");

            // Run composer install inside container if needed
            if (file_exists(rtrim($repo_dir, '/') . '/composer.json')) {
                $containerName = Json::read('docker.container_name', '', $repo_dir);
                if ($containerName) {
                    Shell::run("docker exec {$containerName} composer install --no-interaction 2>&1");
                }
            }
        });

        // ── Stage 4: Security audit ─────────────────────────────
        $runner->run('Running security audit', function() use ($repo_dir) {
            $audit = new SecurityAudit($repo_dir);
            $audit->runAll();
            if (!$audit->passed()) {
                $failures = array_filter($audit->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');

        // ── Stage 5: SOC 2 compliance check ─────────────────────
        $runner->run('SOC 2 compliance check', function() use ($repo_dir) {
            $check = new Soc2Check($repo_dir);
            $check->runAll();
            if (!$check->passed()) {
                $failures = array_filter($check->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');

        // ── Stage 6: Health checks ──────────────────────────────
        $runner->run('Health checks', function() use ($repo_dir, $strategy) {
            // Check Docker containers are running
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
            foreach ($containerNames as $name) {
                if (!Docker::isDockerContainerRunning($name)) {
                    throw new \RuntimeException("Container '{$name}' is not running");
                }
            }

            // Check watcher is running
            if ($strategy === 'release') {
                $pid = JsonLock::read('release.slave.pid', null, $repo_dir);
                if ($pid && !Shell::isRunning($pid)) {
                    throw new \RuntimeException('Release watcher is not running');
                }
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $version = JsonLock::read('release.current', null, $repo_dir)
            ?: trim(Shell::run("cd " . escapeshellarg($repo_dir) . " && git describe --tags --always 2>/dev/null") ?: 'unknown');
        $secretsStatus = Secrets::hasKey() ? 'decrypted' : 'no key found';
        $cronStatus = Crontab::hasCrontabRestart($repo_dir) ? 'installed' : 'not installed';

        $watcherType = $strategy === 'release' ? 'release' : 'git';
        $watcherPidKey = $strategy === 'release' ? 'release.slave.pid' : 'git.slave.pid';
        $watcherPid = JsonLock::read($watcherPidKey, null, $repo_dir);
        $watcherStatus = ($watcherPid && Shell::isRunning($watcherPid)) ? 'running' : 'not running';

        $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
        $runningCount = 0;
        foreach ($containerNames as $name) {
            if (Docker::isDockerContainerRunning($name)) $runningCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$runningCount}/{$containerTotal} running"
            : 'none configured';

        $runner->writeSummary([
            'Environment' => $environment,
            'Strategy'    => $strategy . ($version !== 'unknown' ? " ({$version})" : ''),
            'Secrets'     => $secretsStatus,
            'Containers'  => $containerStatus,
            'Watchers'    => "{$watcherType} watcher {$watcherStatus}",
            'Crontab'     => $cronStatus,
        ]);

        return Command::SUCCESS;
    }

}
