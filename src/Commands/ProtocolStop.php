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
use Symfony\Component\Console\Input\InputArgument;
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

    private StageRunner $runner;
    private InputInterface $input;
    private OutputInterface $output;

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
            ->addArgument('project', InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', null)
            // ...
        ;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTER
    // ═══════════════════════════════════════════════════════════════

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->runner = new StageRunner($output);

        $output->writeln('');

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $dir = $this->resolveDirectory();
        if (!$dir) {
            $output->writeln('<error>Could not determine which directory to stop.</error>');
            return Command::FAILURE;
        }

        return $this->stopDirect($dir);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESOLVER
    // ═══════════════════════════════════════════════════════════════

    private function resolveDirectory(): ?string
    {
        $explicitDir = $this->input->getOption('dir');

        // 1. Explicit --dir passed: use it directly
        if ($explicitDir !== null) {
            return Dir::realpath($explicitDir);
        }

        // 2. Check if cwd is inside a project/release dir
        $cwd = Git::getGitLocalFolder();
        $repo_dir = $cwd ? Dir::realpath($cwd) : null;

        if ($repo_dir) {
            $match = NodeConfig::findByActiveDir($repo_dir);
            if ($match) {
                return $repo_dir;
            }
            if (Git::isInitializedRepo($repo_dir)) {
                return $repo_dir;
            }
        }

        // 3. Ambient mode: resolve slave node, discover running dir
        $projectArg = $this->input->getArgument('project');
        $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        if ($resolved) {
            [$nodeConfig, $nodeData, $activeDir] = $resolved;
            return $this->findRunningDir($nodeData, $activeDir);
        }

        return $repo_dir;
    }

    private function findRunningDir(array $nodeData, string $defaultDir): string
    {
        // Check if the default dir has docker-compose.yml
        $composePath = rtrim($defaultDir, '/') . '/docker-compose.yml';
        if (file_exists($composePath)) {
            return $defaultDir;
        }

        // Search fallback dirs for one with docker-compose.yml
        $releasesDir = $nodeData['bluegreen']['releases_dir'] ?? null;
        $fallbackDirs = [];

        $branch = $nodeData['deployment']['branch'] ?? null;
        if ($branch && $releasesDir) {
            $fallbackDirs[] = rtrim($releasesDir, '/') . '/' . $branch;
        }

        $release = $nodeData['release']['active'] ?? $nodeData['release']['current'] ?? null;
        if ($release && $releasesDir) {
            $fallbackDirs[] = rtrim($releasesDir, '/') . '/' . $release;
        }

        $nodeRepoDir = $nodeData['repo_dir'] ?? null;
        if ($nodeRepoDir) {
            $fallbackDirs[] = rtrim($nodeRepoDir, '/');
        }

        foreach ($fallbackDirs as $dir) {
            if (is_file(rtrim($dir, '/') . '/docker-compose.yml')) {
                return rtrim($dir, '/') . '/';
            }
        }

        return $defaultDir;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTROLLER
    // ═══════════════════════════════════════════════════════════════

    private function stopDirect(string $dir): int
    {
        $ctx = $this->buildContext($dir);

        $this->runner->log("repo_dir={$dir}");
        $this->runner->log("strategy={$ctx['strategy']}");

        $this->stopWatchers($dir, $ctx);
        $this->unlinkConfiguration($dir, $ctx);
        $this->stopContainers($dir, $ctx);
        $this->stopDevServices($dir, $ctx);
        $this->removeCrontab($dir, $ctx);
        $this->verifyShutdown($dir, $ctx);
        $this->writeStopSummary($dir, $ctx);

        return Command::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTEXT BUILDER
    // ═══════════════════════════════════════════════════════════════

    private function buildContext(string $dir): array
    {
        $nodeConfig = null;
        $nodeData = [];

        $match = NodeConfig::findByActiveDir($dir);
        if ($match) {
            [$nodeConfig, $nodeData] = $match;
        }

        if (!$nodeConfig) {
            $projectArg = $this->input->getArgument('project');
            $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $dir ?: null);
            if ($resolved) {
                [$nodeConfig, $nodeData, ] = $resolved;
            }
        }

        $strategy = $nodeConfig
            ? ($nodeData['deployment']['strategy'] ?? 'none')
            : Json::read('deployment.strategy', 'none', $dir);

        return [
            'strategy'   => $strategy,
            'nodeConfig' => $nodeConfig,
            'nodeData'   => $nodeData,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  WORKERS
    // ═══════════════════════════════════════════════════════════════

    private function stopWatchers(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $arrInput = new ArrayInput(['--dir' => $dir]);
        $nullOutput = new NullOutput();
        $app = $this->getApplication();

        $runner->run('Stopping watchers', function() use ($runner, $app, $arrInput, $nullOutput) {
            try { $app->find('deploy:slave:stop')->run($arrInput, $nullOutput); $runner->log("deploy:slave:stop done"); } catch (\Throwable $e) { $runner->log("deploy:slave:stop error: " . $e->getMessage()); }
            try { $app->find('git:slave:stop')->run($arrInput, $nullOutput); $runner->log("git:slave:stop done"); } catch (\Throwable $e) { $runner->log("git:slave:stop error: " . $e->getMessage()); }
            try { $app->find('config:slave:stop')->run($arrInput, $nullOutput); $runner->log("config:slave:stop done"); } catch (\Throwable $e) { $runner->log("config:slave:stop error: " . $e->getMessage()); }
        });
    }

    private function unlinkConfiguration(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $arrInput = new ArrayInput(['--dir' => $dir]);
        $nullOutput = new NullOutput();
        $app = $this->getApplication();

        $runner->run('Unlinking configuration', function() use ($runner, $app, $arrInput, $nullOutput, $dir) {
            $hasRemote = Json::read('configuration.remote', false, $dir);
            $runner->log("configuration.remote=" . ($hasRemote ?: 'false'));
            if ($hasRemote) {
                $app->find('config:unlink')->run($arrInput, $nullOutput);
                $runner->log("config:unlink done");
            }
        });
    }

    private function stopContainers(string $dir, array $ctx): void
    {
        $runner = $this->runner;

        $runner->run('Stopping containers', function() use ($runner, $dir, $ctx) {
            // Simple case: just docker compose down in the given directory
            if ($ctx['strategy'] === 'none') {
                $dockerCommand = Docker::getDockerCommand();
                $runner->log("strategy=none, running {$dockerCommand} down in {$dir}");
                $result = Shell::run("cd " . escapeshellarg($dir) . " && {$dockerCommand} down 2>&1");
                $runner->log("output=" . trim($result));
                return;
            }

            // Release dir: stop via BlueGreen (resolves .env.deployment container name)
            if (BlueGreen::isReleaseDir($dir, $dir) || is_file(rtrim($dir, '/') . '/.env.deployment')) {
                $containerName = ContainerName::resolveFromDir($dir);
                $runner->log("Stopping release dir {$dir} via BlueGreen::stopContainers (container={$containerName})");
                $stopped = BlueGreen::stopContainers($dir);
                $runner->log("result=" . ($stopped ? 'ok' : 'failed'));
            } else {
                $dockerCommand = Docker::getDockerCommand();
                $runner->log("Stopping dir {$dir} via {$dockerCommand} down");
                $result = Shell::run("cd " . escapeshellarg($dir) . " && {$dockerCommand} down 2>&1");
                $runner->log("output=" . trim($result));
            }

            // Also stop all known release dirs tracked by DeploymentState
            $dirs = DeploymentState::allKnownDirs($dir);
            $runner->log("allKnownDirs returned " . count($dirs) . " dirs");
            foreach ($dirs as $knownDir) {
                if ($knownDir === $dir) continue; // already stopped above
                if (BlueGreen::isReleaseDir($knownDir, $dir)) {
                    $containerName = ContainerName::resolveFromDir($knownDir);
                    $runner->log("Stopping release dir {$knownDir} (container={$containerName})");
                    BlueGreen::stopContainers($knownDir);
                }
            }

            // Sweep all release directories on disk for orphans
            if (BlueGreen::isEnabled($dir)) {
                $releases = BlueGreen::listReleases($dir);
                $runner->log("listReleases returned " . count($releases) . " releases");
                foreach ($releases as $release) {
                    $releaseDir = BlueGreen::getReleaseDir($dir, $release);
                    if (is_dir($releaseDir)) {
                        $containerName = ContainerName::resolveFromDir($releaseDir);
                        $runner->log("Stopping release {$release} (container={$containerName})");
                        BlueGreen::stopContainers($releaseDir);
                    }
                }
            }

            $running = trim(Shell::run("docker ps --format '{{.Names}}' 2>/dev/null"));
            $runner->log("docker ps after stop: " . ($running ?: '(none)'));
        });
    }

    private function stopDevServices(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] !== 'none') {
            return;
        }

        $devComposePath = DevCompose::find($dir);
        if (!$devComposePath) {
            return;
        }

        $runningDev = DevCompose::getRunningContainers($devComposePath);
        if (empty($runningDev)) {
            return;
        }

        $shouldStop = DevCompose::shouldAct($dir, 'Stop', $this->input, $this->output, $devComposePath);
        if ($shouldStop) {
            $runner = $this->runner;
            $runner->run('Stopping dev services', function() use ($runner, $dir, $devComposePath) {
                $result = DevCompose::stop($dir, $devComposePath);
                $runner->log("output=" . trim($result));
            });
        }
    }

    private function removeCrontab(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] === 'none') {
            return;
        }
        $runner = $this->runner;
        $runner->run('Removing crontab entry', function() use ($runner, $dir) {
            Crontab::removeCrontabRestart($dir);
            $runner->log("Crontab entry removed");
        });
    }

    private function verifyShutdown(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $runner->run('Verifying shutdown', function() use ($runner, $dir, $ctx) {
            $allNames = ContainerName::resolveAll($dir);
            if (empty($allNames)) {
                $allNames = Docker::getContainerNamesFromDockerComposeFile($dir);
            }
            foreach ($allNames as $name) {
                $isRunning = Docker::isDockerContainerRunning($name);
                $runner->log("Verify container={$name} running=" . ($isRunning ? 'yes' : 'no'));
                if ($isRunning) {
                    throw new \RuntimeException("Container '{$name}' is still running");
                }
            }

            if ($ctx['strategy'] !== 'none') {
                $watcherRunning = DeploymentState::isWatcherRunning($dir);
                $runner->log("Watcher running=" . ($watcherRunning ? 'yes' : 'no'));
                if ($watcherRunning) {
                    throw new \RuntimeException('Deployment watcher is still running');
                }
            }
        }, 'PASS');
    }

    private function writeStopSummary(string $dir, array $ctx): void
    {
        $environment = Config::read('env', 'unknown');

        $containerNames = ContainerName::resolveAll($dir);
        if (empty($containerNames)) {
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($dir);
        }

        foreach ($containerNames as $name) {
            $this->runner->log("Summary: container={$name} running=" . (Docker::isDockerContainerRunning($name) ? 'yes' : 'no'));
        }

        $stoppedCount = 0;
        foreach ($containerNames as $name) {
            if (!Docker::isDockerContainerRunning($name)) $stoppedCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$stoppedCount}/{$containerTotal} stopped"
            : 'none configured';

        $this->runner->log("Summary totals: {$stoppedCount}/{$containerTotal} stopped, names=" . implode(',', $containerNames));

        $summaryInfo = [
            'Environment' => $environment,
            'Containers'  => $containerStatus,
        ];

        if ($ctx['strategy'] !== 'none') {
            $summaryInfo['Watchers'] = DeploymentState::isWatcherRunning($dir) ? 'still running' : 'stopped';
            $summaryInfo['Crontab'] = Crontab::hasCrontabRestart($dir) ? 'still installed' : 'removed';
        }

        $this->runner->writeSummary($summaryInfo, 'Shutdown complete.', 'Shutdown completed with issues.');
    }

}
