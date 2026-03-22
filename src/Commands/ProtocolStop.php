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
            ? ($nodeData['deployment']['strategy'] ?? 'branch')
            : Json::read('deployment.strategy', 'branch', $repo_dir);

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
        // --env-file .env.bluegreen so docker compose resolves the patched
        // container name (e.g. ghostagent-v0.1.1 instead of bare ghostagent).
        //
        // For branch: uses raw docker compose down in repo_dir.
        $runner->run('Stopping containers', function() use ($runner, $repo_dir) {
            // 1. Stop containers in all dirs tracked by DeploymentState.
            //    For dirs with .env.bluegreen, use BlueGreen::stopContainers()
            //    so the patched container name is resolved correctly.
            $dirs = DeploymentState::allKnownDirs($repo_dir);
            $runner->log("allKnownDirs returned " . count($dirs) . " dirs: " . implode(', ', $dirs));

            foreach ($dirs as $dir) {
                $envFile = rtrim($dir, '/') . '/.env.bluegreen';
                if (file_exists($envFile)) {
                    $containerName = BlueGreen::getContainerName($dir);
                    $runner->log("Stopping release dir {$dir} via BlueGreen::stopContainers (container={$containerName})");
                    $stopped = BlueGreen::stopContainers($dir);
                    $runner->log("  result=" . ($stopped ? 'ok' : 'failed'));
                } else {
                    $dockerCommand = Docker::getDockerCommand();
                    $runner->log("Stopping dir {$dir} via {$dockerCommand} down (no .env.bluegreen)");
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
                        $containerName = BlueGreen::getContainerName($releaseDir);
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

        // ── Stage 4: Removing crontab ───────────────────────────
        $runner->run('Removing crontab entry', function() use ($runner, $repo_dir) {
            Crontab::removeCrontabRestart($repo_dir);
            $runner->log("Crontab entry removed");
        });

        // ── Stage 5: Verifying shutdown ─────────────────────────
        $runner->run('Verifying shutdown', function() use ($runner, $repo_dir) {
            // Check release dir containers by their patched name from .env.bluegreen
            if (BlueGreen::isEnabled($repo_dir)) {
                $releases = BlueGreen::listReleases($repo_dir);
                foreach ($releases as $release) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $release);
                    $containerName = BlueGreen::getContainerName($releaseDir);
                    if ($containerName) {
                        $isRunning = Docker::isDockerContainerRunning($containerName);
                        $runner->log("Verify release {$release}: container={$containerName} running={$isRunning}");
                        if ($isRunning) {
                            throw new \RuntimeException("Container '{$containerName}' is still running");
                        }
                    }
                }
            }

            // Check containers from compose files in non-release dirs
            $dirs = DeploymentState::allKnownDirs($repo_dir);
            foreach ($dirs as $dir) {
                // Skip release dirs — already checked above by patched name
                $envFile = rtrim($dir, '/') . '/.env.bluegreen';
                if (file_exists($envFile)) {
                    continue;
                }
                $composePath = rtrim($dir, '/') . '/docker-compose.yml';
                if (file_exists($composePath)) {
                    $containerNames = Docker::getContainerNamesFromDockerComposeFile($dir);
                    foreach ($containerNames as $name) {
                        $isRunning = Docker::isDockerContainerRunning($name);
                        $runner->log("Verify dir {$dir}: container={$name} running={$isRunning}");
                        if ($isRunning) {
                            throw new \RuntimeException("Container '{$name}' is still running");
                        }
                    }
                }
            }

            // Verify watcher is stopped
            $watcherRunning = DeploymentState::isWatcherRunning($repo_dir);
            $runner->log("Watcher running={$watcherRunning}");
            if ($watcherRunning) {
                throw new \RuntimeException('Deployment watcher is still running');
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $environment = Config::read('env', 'unknown');

        // Collect container names: use patched names from .env.bluegreen
        // for release dirs, compose file names for branch dirs.
        $containerNames = [];

        // Release dir containers (by patched name)
        if (BlueGreen::isEnabled($repo_dir)) {
            foreach (BlueGreen::listReleases($repo_dir) as $release) {
                $releaseDir = BlueGreen::getReleaseDir($repo_dir, $release);
                $envName = BlueGreen::getContainerName($releaseDir);
                if ($envName) {
                    $containerNames[] = $envName;
                    $runner->log("Summary: release {$release} container={$envName} running=" . (Docker::isDockerContainerRunning($envName) ? 'yes' : 'no'));
                }
            }
        }

        // Non-release dirs (branch strategy)
        foreach (DeploymentState::allKnownDirs($repo_dir) as $dir) {
            if (file_exists(rtrim($dir, '/') . '/.env.bluegreen')) {
                continue; // Already handled above
            }
            $composePath = rtrim($dir, '/') . '/docker-compose.yml';
            if (file_exists($composePath)) {
                $names = Docker::getContainerNamesFromDockerComposeFile($dir);
                foreach ($names as $name) {
                    if (!in_array($name, $containerNames)) {
                        $containerNames[] = $name;
                        $runner->log("Summary: dir {$dir} container={$name} running=" . (Docker::isDockerContainerRunning($name) ? 'yes' : 'no'));
                    }
                }
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

        $runner->log("Summary totals: {$stoppedCount}/{$containerTotal} stopped, names=" . implode(',', $containerNames));

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
