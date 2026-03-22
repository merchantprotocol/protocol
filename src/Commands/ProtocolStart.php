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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Crontab;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\SecretsProvider;
use Gitcd\Helpers\StageRunner;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Helpers\SecurityAudit;
use Gitcd\Helpers\Soc2Check;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\Webhook;
use Gitcd\Helpers\DiskCheck;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

Class ProtocolStart extends Command {

    private ?\Symfony\Component\Lock\LockInterface $lock = null;
    private const LOCK_TTL = 120; // 2 minutes — auto-expires stale locks

    protected static $defaultName = 'docker:start|start';
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
            ->addArgument('project', InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force start, ignoring any existing lock')
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

        // Detect slave node mode so start works from anywhere
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

        $helper = $this->getHelper('question');

        // command should only have one running instance (lock auto-expires after 2 min)
        $store = SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore();
        $this->lock = (new LockFactory($store))->createLock($this->getName(), self::LOCK_TTL);
        if (!$this->lock->acquire()) {
            if ($input->getOption('force')) {
                $this->lock->acquire(true); // blocking acquire
                $output->writeln('<comment>Forcing lock override...</comment>');
            } else {
                $output->writeln('The command is already running in another process. Use --force (-f) to override.');
                $output->writeln('<comment>Lock auto-expires after ' . self::LOCK_TTL . ' seconds.</comment>');
                return Command::SUCCESS;
            }
        }

        // get the correct environment
        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment && $nodeConfig) {
            $environment = $nodeData['environment'] ?? 'production';
        }
        if (!$environment) {
            $question = new Question('What is the current env we need to configure protocol for globally? This must be set:', 'localhost');
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        }

        $devEnvs = ['localhost', 'local', 'dev', 'development'];
        $isDev = (in_array($environment, $devEnvs) || strpos($environment, 'localhost') !== false);

        $strategy = $nodeConfig
            ? ($nodeData['deployment']['strategy'] ?? 'branch')
            : Json::read('deployment.strategy', 'branch', $repo_dir);

        // Prepare sub-command inputs
        $force = $input->getOption('force');
        $arrInput = new ArrayInput(['--dir' => $repo_dir] + ($force ? ['--force' => true] : []));
        $arrInput1 = new ArrayInput(['--dir' => $repo_dir, 'environment' => $environment] + ($force ? ['--force' => true] : []));
        $verbose = $output->isVerbose();
        $subOutput = $verbose ? $output : new NullOutput();
        $app = $this->getApplication();

        $output->writeln('');

        $runner = new StageRunner($output, $verbose);

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
        $runner->run('Infrastructure provisioning', function() use ($runner, $app, $arrInput, $arrInput1, $subOutput, $repo_dir, $environment, $strategy, $isDev, $nodeConfig, $nodeData) {

            // Refresh GitHub App credentials and fix remote URLs if needed
            if (GitHubApp::isConfigured()) {
                $creds = GitHubApp::loadCredentials();
                $appOwner = $creds['owner'] ?? null;
                if ($appOwner) {
                    $runner->log("Refreshing GitHub App credentials for {$appOwner}");
                    $refreshed = GitHubApp::refreshGitCredentials($appOwner);
                    if (!$refreshed) {
                        throw new \RuntimeException("GitHub App credential refresh failed for {$appOwner} — cannot authenticate git operations");
                    }
                    $runner->log("Credentials refreshed successfully");
                }

                // Update git remote URL in cloned repos to use HTTPS
                $currentRemote = trim(Shell::run("git -C " . escapeshellarg($repo_dir) . " remote get-url origin 2>/dev/null") ?: '');
                $resolvedRemote = GitHubApp::resolveUrl($currentRemote);
                if ($currentRemote && $resolvedRemote !== $currentRemote) {
                    $runner->log("Updating remote URL: {$currentRemote} → {$resolvedRemote}");
                    Shell::run("git -C " . escapeshellarg($repo_dir) . " remote set-url origin " . escapeshellarg($resolvedRemote) . " 2>/dev/null");
                }
            }

            $configRemote = Json::read('configuration.remote', false, $repo_dir);
            // On slave nodes, fall back to node config for the config repo remote
            if (!$configRemote && $nodeConfig) {
                $configRemote = $nodeData['configuration']['remote'] ?? false;
            }
            $configRepo = Config::repo($repo_dir);
            $hasConfigRepo = $configRemote || is_dir($configRepo);

            // Also fix config repo remote URL if it was cloned with SSH
            if (GitHubApp::isConfigured() && is_dir($configRepo)) {
                $configCurrentRemote = trim(Shell::run("git -C " . escapeshellarg($configRepo) . " remote get-url origin 2>/dev/null") ?: '');
                $configResolvedRemote = GitHubApp::resolveUrl($configCurrentRemote);
                if ($configCurrentRemote && $configResolvedRemote !== $configCurrentRemote) {
                    $runner->log("Updating config repo remote: {$configCurrentRemote} → {$configResolvedRemote}");
                    Shell::run("git -C " . escapeshellarg($configRepo) . " remote set-url origin " . escapeshellarg($configResolvedRemote) . " 2>/dev/null");
                }
            }

            $runner->log("strategy={$strategy} hasConfigRepo=" . ($hasConfigRepo ? 'yes' : 'no') . " isDev=" . ($isDev ? 'yes' : 'no') . " configRemote=" . ($configRemote ?: 'none'));

            if (in_array($strategy, ['release', 'bluegreen'])) {
                // Release/bluegreen deployment mode — both use the release watcher
                if ($hasConfigRepo) {
                    if ($configRemote) {
                        $runner->log("Running config:slave");
                        $app->find('config:slave')->run($arrInput, $subOutput);
                    }
                    $runner->log("Running config:link");
                    $app->find('config:link')->run($arrInput, $subOutput);
                }

                // Start the release watcher daemon
                $runner->log("Running deploy:slave");
                $app->find('deploy:slave')->run($arrInput, $subOutput);

            } else {
                // Legacy branch-based deployment mode
                if (!$isDev) {
                    // Log diagnostic info for debugging auth issues
                    $runner->log("HOME=" . (getenv('HOME') ?: 'NOT SET'));
                    $credFile = (defined('NODE_DATA_DIR') ? NODE_DATA_DIR : '') . 'git-credentials';
                    $runner->log("git-credentials exists=" . (is_file($credFile) ? 'yes' : 'no'));
                    $credHelper = trim(Shell::run("git config --global credential.helper 2>/dev/null") ?: '');
                    $runner->log("credential.helper={$credHelper}");
                    $remoteUrl = trim(Shell::run("git -C " . escapeshellarg($repo_dir) . " remote get-url origin 2>/dev/null") ?: '');
                    $runner->log("remote.origin.url={$remoteUrl}");

                    $runner->log("Running git:pull");
                    $app->find('git:pull')->run($arrInput, $subOutput);
                    $runner->log("git:pull completed");
                    $runner->log("Running git:slave");
                    $app->find('git:slave')->run($arrInput, $subOutput);
                }

                if ($hasConfigRepo) {
                    if (!$isDev && $configRemote) {
                        $runner->log("Running config:slave");
                        $app->find('config:slave')->run($arrInput, $subOutput);
                    }

                    $runner->log("Running config:link");
                    $app->find('config:link')->run($arrInput, $subOutput);
                }
            }

            // Add crontab restart command
            $runner->log("Adding crontab restart");
            Crontab::addCrontabRestart($repo_dir);
        });

        // ── Stage 3: Container build & start ────────────────────
        // Handles all three strategies:
        //   "release"   — Start the active release's containers on production ports
        //   "bluegreen" — Start the active version's containers (may be on shadow or production ports)
        //   "branch"    — Standard in-place build from repo_dir
        $runner->run('Container build & start', function() use ($runner, $repo_dir, $strategy) {
            // Release and bluegreen strategies both use release directories.
            // Start the active version's containers from the release dir.
            if (BlueGreen::isEnabled($repo_dir)) {
                // Try bluegreen.active_version first, then fall back to
                // DeploymentState::current() which checks release.current
                // and other legacy keys. This handles cases where the version
                // was deployed before the strategy separation changes.
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                if (!$activeVersion) {
                    $curDeploy = DeploymentState::current($repo_dir);
                    $activeVersion = $curDeploy['version'] ?? null;
                }
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    if (is_dir($releaseDir)) {
                        $runner->log("Starting active release {$activeVersion} from {$releaseDir}");

                        // For release strategy, ensure production ports are set
                        if ($strategy === 'release') {
                            BlueGreen::writeReleaseEnv(
                                $releaseDir,
                                BlueGreen::PRODUCTION_HTTP,
                                BlueGreen::PRODUCTION_HTTPS,
                                $activeVersion
                            );
                        }

                        BlueGreen::startContainers($releaseDir);
                        return;
                    }
                }
                // No active version yet — fall through to standard build
                $runner->log("No active release version found, falling back to standard build");
            }

            // Standard single-container mode (branch strategy or fallback)
            $composePath = rtrim($repo_dir, '/') . '/docker-compose.yml';

            if (!file_exists($composePath)) {
                return; // No docker-compose.yml, nothing to do
            }

            // Pull or build the Docker image
            $content = file_get_contents($composePath);
            $usesBuild = (bool) preg_match('/^\s+build:/m', $content);

            if ($usesBuild) {
                $dockerCmd = Docker::getDockerCommand();
                $runner->log("{$dockerCmd} build");
                Shell::run("{$dockerCmd} -f " . escapeshellarg($composePath) . " build 2>&1");
            } else {
                $image = Json::read('docker.image', false, $repo_dir);
                if ($image) {
                    $runner->log("docker pull {$image}");
                    Shell::run("docker pull " . escapeshellarg($image) . " 2>&1");
                }
            }

            // Rebuild containers (inject secrets if encrypted or AWS mode)
            $dockerCommand = Docker::getDockerCommand();
            $tmpEnv = SecretsProvider::resolveToTempFile($repo_dir);

            if ($tmpEnv) {
                // Write secrets into the project dir so compose can reference it
                $secretsFile = rtrim($repo_dir, '/') . '/.env.protocol-secrets';
                copy($tmpEnv, $secretsFile);
                chmod($secretsFile, 0600);
                unlink($tmpEnv);

                // Generate a compose override that injects env_file into every service
                $overrideFile = SecretsProvider::generateComposeOverride($composePath, $secretsFile);

                $runner->log("{$dockerCommand} up --build -d (with secrets injected into containers)");
                Shell::run("cd " . escapeshellarg($repo_dir)
                    . " && {$dockerCommand} -f " . escapeshellarg($composePath)
                    . " -f " . escapeshellarg($overrideFile)
                    . " up --build -d 2>&1");

                // Clean up secrets files immediately
                unlink($secretsFile);
                unlink($overrideFile);
                $runner->log("Secrets temp files cleaned up");
            } else {
                $runner->log("{$dockerCommand} up --build -d");
                Shell::run("cd " . escapeshellarg($repo_dir) . " && {$dockerCommand} up --build -d 2>&1");
            }

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
            Webhook::notifyAudit('security_audit', $repo_dir, $audit->getResults(), $audit->passed());
            if (!$audit->passed()) {
                $failures = array_filter($audit->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');

        // ── Stage 5: SOC 2 readiness check ───────────────────────
        $runner->run('SOC 2 readiness check', function() use ($repo_dir) {
            $check = new Soc2Check($repo_dir);
            $check->runAll();
            Webhook::notifyAudit('soc2_check', $repo_dir, $check->getResults(), $check->passed());
            if (!$check->passed()) {
                $failures = array_filter($check->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');

        // ── Stage 6: Health checks ──────────────────────────────
        $runner->run('Health checks', function() use ($repo_dir, $strategy) {
            // For release/bluegreen, check the active release's container
            if (BlueGreen::isEnabled($repo_dir)) {
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                if (!$activeVersion) {
                    $curDeploy = DeploymentState::current($repo_dir);
                    $activeVersion = $curDeploy['version'] ?? null;
                }
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    $containerName = BlueGreen::getContainerName($releaseDir);
                    if ($containerName) {
                        if (!Docker::isDockerContainerRunning($containerName)) {
                            throw new \RuntimeException("Container '{$containerName}' is not running");
                        }
                        // Container is running — skip the fallback check below
                        $watcherPid = DeploymentState::watcherPid($repo_dir);
                        if ($watcherPid && !Shell::isRunning($watcherPid)) {
                            throw new \RuntimeException('Deployment watcher is not running');
                        }
                        return;
                    }
                }
            }

            // Branch strategy or fallback: check containers from compose file in repo_dir
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
            foreach ($containerNames as $name) {
                if (!Docker::isDockerContainerRunning($name)) {
                    throw new \RuntimeException("Container '{$name}' is not running");
                }
            }

            // Check watcher is running
            $watcherPid = DeploymentState::watcherPid($repo_dir);
            if ($watcherPid && !Shell::isRunning($watcherPid)) {
                throw new \RuntimeException('Deployment watcher is not running');
            }
        }, 'PASS');

        // ── Stage 7: Disk space check ──────────────────────────
        $diskWarnings = [];
        $runner->run('Disk space check', function() use (&$diskWarnings) {
            $check = DiskCheck::check();
            $diskWarnings = DiskCheck::formatWarnings($check);

            if ($check['level'] === 'alert') {
                throw new \RuntimeException("Disk {$check['percent']}% full — cleanup recommended");
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $curDeploy = DeploymentState::current($repo_dir);
        $version = ($curDeploy['version'] ?? null)
            ?: trim(Shell::run("cd " . escapeshellarg($repo_dir) . " && git describe --tags --always 2>/dev/null") ?: 'unknown');
        $secretsStatus = Secrets::hasKey() ? 'decrypted' : 'no key found';
        $cronStatus = Crontab::hasCrontabRestart($repo_dir) ? 'installed' : 'not installed';

        $watcherType = DeploymentState::strategy($repo_dir);
        $watcherPid = DeploymentState::watcherPid($repo_dir);
        $watcherStatus = ($watcherPid && Shell::isRunning($watcherPid)) ? 'running' : 'not running';

        // Collect container names from all known dirs (includes release dirs)
        $containerNames = [];
        foreach (DeploymentState::allKnownDirs($repo_dir) as $dir) {
            $containerNames = array_merge($containerNames, Docker::getContainerNamesFromDockerComposeFile($dir));
        }
        // Also check release dirs for patched container names
        if (BlueGreen::isEnabled($repo_dir)) {
            $activeVersion = BlueGreen::getActiveVersion($repo_dir);
            if (!$activeVersion) {
                $curDeploy = DeploymentState::current($repo_dir);
                $activeVersion = $curDeploy['version'] ?? null;
            }
            if ($activeVersion) {
                $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                $envName = BlueGreen::getContainerName($releaseDir);
                if ($envName && !in_array($envName, $containerNames)) {
                    $containerNames[] = $envName;
                }
            }
        }
        $runningCount = 0;
        foreach ($containerNames as $name) {
            if (Docker::isDockerContainerRunning($name)) $runningCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$runningCount}/{$containerTotal} running"
            : 'none configured';

        $cleanupCron = Crontab::hasDockerCleanup($repo_dir) ? 'scheduled' : 'not scheduled';

        $summaryInfo = [
            'Environment' => $environment,
            'Strategy'    => $strategy . ($version !== 'unknown' ? " ({$version})" : ''),
            'Secrets'     => $secretsStatus,
            'Containers'  => $containerStatus,
            'Watchers'    => "{$watcherType} watcher {$watcherStatus}",
            'Crontab'     => $cronStatus,
            'Cleanup'     => $cleanupCron,
        ];

        $runner->writeSummary($summaryInfo);

        // Show disk warnings after summary if any
        if (!empty($diskWarnings)) {
            $output->writeln('');
            $output->writeln('  <fg=yellow;options=bold>Disk Space Warning</>');
            foreach ($diskWarnings as $warning) {
                $output->writeln("    {$warning}");
            }
            $output->writeln('');
        }

        // Suggest cleanup schedule for production blue-green without it
        if (!$isDev && !Crontab::hasDockerCleanup($repo_dir) && BlueGreen::isEnabled($repo_dir)) {
            $output->writeln('  <fg=yellow>!</> Blue-green deployment detected without scheduled Docker cleanup.');
            $output->writeln('    <fg=gray>Old images will accumulate. Enable with:</> <fg=white>protocol docker:cleanup:schedule on</>');
            $output->writeln('');
        }

        // Run protocol status to show full dashboard
        $statusArgs = new ArrayInput(['--dir' => $repo_dir]);
        $app->find('status')->run($statusArgs, $output);

        return Command::SUCCESS;
    }

}
