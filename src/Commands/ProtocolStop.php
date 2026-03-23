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
use Gitcd\Helpers\ContainerName;
use Gitcd\Helpers\DevCompose;
use Gitcd\Helpers\StageRunner;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\Json;
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

        // If the resolved dir has no docker-compose.yml, try to find the actual
        // running directory from node config (handles strategy switches mid-flight)
        $composePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath) && $nodeConfig) {
            $releasesDir = $nodeData['bluegreen']['releases_dir'] ?? null;
            $fallbackDirs = [];

            // Check branch directory
            $branch = $nodeData['deployment']['branch'] ?? null;
            if ($branch && $releasesDir) {
                $fallbackDirs[] = rtrim($releasesDir, '/') . '/' . $branch;
            }

            // Check current release directory
            $release = $nodeData['release']['current'] ?? null;
            if ($release && $releasesDir) {
                $fallbackDirs[] = rtrim($releasesDir, '/') . '/' . $release;
            }

            // Check repo_dir from node config
            $nodeRepoDir = $nodeData['repo_dir'] ?? null;
            if ($nodeRepoDir) {
                $fallbackDirs[] = rtrim($nodeRepoDir, '/');
            }

            foreach ($fallbackDirs as $dir) {
                if (is_file(rtrim($dir, '/') . '/docker-compose.yml')) {
                    $repo_dir = rtrim($dir, '/') . '/';
                    break;
                }
            }
        }

        $arrInput = new ArrayInput(['--dir' => $repo_dir]);
        $nullOutput = new NullOutput();
        $app = $this->getApplication();
        $strategy = $nodeConfig
            ? ($nodeData['deployment']['strategy'] ?? 'none')
            : Json::read('deployment.strategy', 'none', $repo_dir);

        $output->writeln('');

        $runner = new StageRunner($output);

        $runner->log("repo_dir={$repo_dir}");
        $runner->log("strategy={$strategy}");
        $runner->log("isEnabled=" . (BlueGreen::isEnabled($repo_dir) ? 'true' : 'false'));
        $runner->log("activeVersion=" . (BlueGreen::getActiveVersion($repo_dir) ?: 'null'));

        // ── Stage 1: Stopping watchers ──────────────────────────
        $runner->run('Stopping watchers', function() use ($runner, $app, $arrInput, $nullOutput) {
            // Stop all watchers regardless of strategy
            try { $app->find('deploy:slave:stop')->run($arrInput, $nullOutput); $runner->log("deploy:slave:stop done"); } catch (\Throwable $e) { $runner->log("deploy:slave:stop error: " . $e->getMessage()); }
            try { $app->find('git:slave:stop')->run($arrInput, $nullOutput); $runner->log("git:slave:stop done"); } catch (\Throwable $e) { $runner->log("git:slave:stop error: " . $e->getMessage()); }
            try { $app->find('config:slave:stop')->run($arrInput, $nullOutput); $runner->log("config:slave:stop done"); } catch (\Throwable $e) { $runner->log("config:slave:stop error: " . $e->getMessage()); }
        });

        // ── Stage 2: Unlinking configuration ────────────────────
        $runner->run('Unlinking configuration', function() use ($runner, $app, $arrInput, $nullOutput, $repo_dir) {
            $hasRemote = Json::read('configuration.remote', false, $repo_dir);
            $runner->log("configuration.remote=" . ($hasRemote ?: 'false'));
            if ($hasRemote) {
                $app->find('config:unlink')->run($arrInput, $nullOutput);
                $runner->log("config:unlink done");
            }
        });

        // ── Stage 3: Stopping containers ────────────────────────
        // For release/bluegreen: uses BlueGreen::stopContainers() which passes
        // --env-file .env.deployment so docker compose resolves the patched
        // container name (e.g. ghostagent-v0.1.1 instead of bare ghostagent).
        //
        // For none: uses raw docker compose down in repo_dir directly.
        // For branch: uses raw docker compose down in repo_dir.
        $runner->run('Stopping containers', function() use ($runner, $repo_dir, $strategy) {
            // No deployment strategy — just docker compose down in repo dir
            if ($strategy === 'none') {
                $dockerCommand = Docker::getDockerCommand();
                $runner->log("strategy=none, running {$dockerCommand} down in {$repo_dir}");
                $result = Shell::run("cd " . escapeshellarg($repo_dir) . " && {$dockerCommand} down 2>&1");
                $runner->log("output=" . trim($result));
                $running = trim(Shell::run("docker ps --format '{{.Names}}' 2>/dev/null"));
                $runner->log("docker ps after stop: " . ($running ?: '(none)'));
                return;
            }

            // 1. Stop containers in all dirs tracked by DeploymentState.
            //    For dirs with .env.deployment, use BlueGreen::stopContainers()
            //    so the patched container name is resolved correctly.
            $dirs = DeploymentState::allKnownDirs($repo_dir);
            $runner->log("allKnownDirs returned " . count($dirs) . " dirs: " . implode(', ', $dirs));

            foreach ($dirs as $dir) {
                if (BlueGreen::isReleaseDir($dir, $repo_dir)) {
                    $containerName = ContainerName::resolveFromDir($dir);
                    $runner->log("Stopping release dir {$dir} via BlueGreen::stopContainers (container={$containerName})");
                    $stopped = BlueGreen::stopContainers($dir);
                    $runner->log("  result=" . ($stopped ? 'ok' : 'failed'));
                } else {
                    $dockerCommand = Docker::getDockerCommand();
                    $runner->log("Stopping dir {$dir} via {$dockerCommand} down (not a release dir)");
                    $result = Shell::run("cd " . escapeshellarg($dir) . " && {$dockerCommand} down 2>&1");
                    $runner->log("  output=" . trim($result));
                }
            }

            // 2. Also iterate ALL release directories on disk.
            //    This catches orphaned releases not tracked in deploy state,
            //    and ensures we don't miss anything.
            if (BlueGreen::isEnabled($repo_dir)) {
                $releases = BlueGreen::listReleases($repo_dir);
                $runner->log("listReleases returned " . count($releases) . " releases: " . implode(', ', $releases));
                foreach ($releases as $release) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $release);
                    if (is_dir($releaseDir)) {
                        $containerName = ContainerName::resolveFromDir($releaseDir);
                        $runner->log("Stopping release {$release} dir={$releaseDir} container={$containerName}");
                        $stopped = BlueGreen::stopContainers($releaseDir);
                        $runner->log("  result=" . ($stopped ? 'ok' : 'failed'));
                    } else {
                        $runner->log("Skipping release {$release} — dir does not exist: {$releaseDir}");
                    }
                }
            } else {
                $runner->log("BlueGreen not enabled, skipping release dir scan");
            }

            // 3. Nuclear option: check docker ps for any containers matching
            //    the project name pattern and force-stop them.
            $running = trim(Shell::run("docker ps --format '{{.Names}}' 2>/dev/null"));
            $runner->log("docker ps after stop: " . ($running ?: '(none)'));
        });

        // ── Dev compose services ─────────────────────────────────
        // Only for "none" strategy (local dev). Check for dev compose files
        // and offer to stop their containers too.
        if ($strategy === 'none') {
            $devComposePath = DevCompose::find($repo_dir);
            if ($devComposePath) {
                $runningDev = DevCompose::getRunningContainers($devComposePath);
                if (!empty($runningDev)) {
                    $shouldStop = DevCompose::shouldAct($repo_dir, 'Stop', $input, $output, $devComposePath);
                    if ($shouldStop) {
                        $runner->run('Stopping dev services', function() use ($runner, $repo_dir, $devComposePath) {
                            $result = DevCompose::stop($repo_dir, $devComposePath);
                            $runner->log("output=" . trim($result));
                        });
                    }
                }
            }
        }

        // ── Stage 4: Removing crontab ───────────────────────────
        // Skip for local dev — no crontab was installed
        if ($strategy !== 'none') {
            $runner->run('Removing crontab entry', function() use ($runner, $repo_dir) {
                Crontab::removeCrontabRestart($repo_dir);
                $runner->log("Crontab entry removed");
            });
        }

        // ── Stage 5: Verifying shutdown ─────────────────────────
        $runner->run('Verifying shutdown', function() use ($runner, $repo_dir, $strategy) {
            // Verify all known containers are stopped
            $allNames = ContainerName::resolveAll($repo_dir);
            foreach ($allNames as $name) {
                $isRunning = Docker::isDockerContainerRunning($name);
                $runner->log("Verify container={$name} running={$isRunning}");
                if ($isRunning) {
                    throw new \RuntimeException("Container '{$name}' is still running");
                }
            }

            // Verify watcher is stopped (skip for local dev — no watchers)
            if ($strategy !== 'none') {
                $watcherRunning = DeploymentState::isWatcherRunning($repo_dir);
                $runner->log("Watcher running={$watcherRunning}");
                if ($watcherRunning) {
                    throw new \RuntimeException('Deployment watcher is still running');
                }
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $environment = Config::read('env', 'unknown');

        $containerNames = ContainerName::resolveAll($repo_dir);
        foreach ($containerNames as $name) {
            $runner->log("Summary: container={$name} running=" . (Docker::isDockerContainerRunning($name) ? 'yes' : 'no'));
        }

        $stoppedCount = 0;
        foreach ($containerNames as $name) {
            if (!Docker::isDockerContainerRunning($name)) $stoppedCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$stoppedCount}/{$containerTotal} stopped"
            : 'none configured';

        $runner->log("Summary totals: {$stoppedCount}/{$containerTotal} stopped, names=" . implode(',', $containerNames));

        $summaryInfo = [
            'Environment' => $environment,
            'Containers'  => $containerStatus,
        ];

        if ($strategy !== 'none') {
            $summaryInfo['Watchers'] = DeploymentState::isWatcherRunning($repo_dir) ? 'still running' : 'stopped';
            $summaryInfo['Crontab'] = Crontab::hasCrontabRestart($repo_dir) ? 'still installed' : 'removed';
        }

        $runner->writeSummary($summaryInfo, 'Shutdown complete.', 'Shutdown completed with issues.');

        return Command::SUCCESS;
    }

}
