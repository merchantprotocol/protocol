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
use Gitcd\Helpers\ContainerName;
use Gitcd\Helpers\DevCompose;
use Gitcd\Helpers\PortConflict;
use Gitcd\Helpers\Lifecycle;
use Gitcd\Helpers\Webhook;
use Gitcd\Helpers\DiskCheck;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\Json;
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

        // Migrate from protocol.lock if it exists (one-time, idempotent)
        DeploymentState::migrateFromLockFile($repo_dir);

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
            ? ($nodeData['deployment']['strategy'] ?? 'none')
            : Json::read('deployment.strategy', 'none', $repo_dir);

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
                $runner->log("deploy:slave returned");

            } elseif ($strategy === 'none') {
                // No deployment strategy — skip watchers entirely, just link configs
                $runner->log("strategy=none, skipping watchers");
                if ($hasConfigRepo) {
                    $runner->log("Running config:link");
                    $app->find('config:link')->run($arrInput, $subOutput);
                }

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

            // Add crontab restart command (skip for local dev — no deployment to restart)
            if ($strategy !== 'none') {
                $runner->log("Adding crontab restart");
                Crontab::addCrontabRestart($repo_dir);
            } else {
                $runner->log("strategy=none, skipping crontab");
            }
        });

        // ── Port conflict detection (none strategy only) ─────────
        $portOverrideFile = null;
        if ($strategy === 'none') {
            $conflicts = PortConflict::detectConflicts($repo_dir);
            if (!empty($conflicts)) {
                $alternatives = PortConflict::suggestAlternatives($conflicts);
                $resolution = PortConflict::promptUser($conflicts, $alternatives, $input, $output, $repo_dir);
                if ($resolution === null) {
                    $output->writeln('  <fg=red>Startup aborted due to port conflicts.</>');
                    return Command::FAILURE;
                }
                if ($resolution === 'remap') {
                    $portOverrideFile = PortConflict::generateOverrideFile($repo_dir, $alternatives);
                    $runner->log("Port override file generated: {$portOverrideFile}");
                }
            }
        }

        // ── Stage 3: Container build & start ────────────────────
        // Handles all three strategies:
        //   "release"   — Start the active release's containers on production ports
        //   "bluegreen" — Start the active version's containers (may be on shadow or production ports)
        //   "branch"    — Standard in-place build from repo_dir
        $runner->run('Container build & start', function() use ($runner, $repo_dir, $strategy, $portOverrideFile) {
            $runner->log("strategy={$strategy} isEnabled=" . (BlueGreen::isEnabled($repo_dir) ? 'true' : 'false'));

            // Release and bluegreen strategies both use release directories.
            // Start the active version's containers from the release dir.
            if (BlueGreen::isEnabled($repo_dir)) {
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                $runner->log("getActiveVersion={$activeVersion}");
                if (!$activeVersion) {
                    $curDeploy = DeploymentState::current($repo_dir);
                    $activeVersion = $curDeploy['version'] ?? null;
                    $runner->log("DeploymentState::current fallback version={$activeVersion}");
                }
                // Final fallback: read release.active from node config.
                // The watcher persists the active version here before calling
                // protocol stop+start.
                if (!$activeVersion || $activeVersion === 'main') {
                    $project = NodeConfig::findByRepoDir($repo_dir);
                    if ($project) {
                        $nd = NodeConfig::load($project);
                        $nodeRelease = $nd['release']['active'] ?? null;
                        if ($nodeRelease) {
                            $activeVersion = $nodeRelease;
                            $runner->log("NodeConfig fallback version={$activeVersion} (project={$project})");
                        }
                    }
                }
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    $dirExists = is_dir($releaseDir);
                    $runner->log("releaseDir={$releaseDir} exists={$dirExists}");
                    if ($dirExists) {
                        // IMPORTANT: Patch docker-compose.yml to replace hardcoded
                        // container_name and port mappings with parameterized versions:
                        //   container_name: mp-gateway  →  container_name: ${CONTAINER_NAME:-mp-gateway}
                        //   ports: "8090:80"            →  ports: "${PROTOCOL_PORT_HTTP:-80}:80"
                        //
                        // Without this, writeReleaseEnv() sets CONTAINER_NAME=mp-gateway-v1.0.0
                        // in .env.deployment, but docker-compose ignores it because the compose
                        // file has a hardcoded name. The result is containers named "mp-gateway"
                        // with no version info, making it impossible to tell which release is
                        // running or to run multiple releases side-by-side.
                        //
                        // The watcher also patches on initial clone, but git operations
                        // (checkout, reset) can restore the original unpatched file.
                        // Patching here on every start guarantees correctness.
                        BlueGreen::patchComposeFile($releaseDir);

                        // For release strategy, ensure production ports are set
                        if ($strategy === 'release') {
                            $runner->log("Writing production ports (80/443) for release strategy");
                            BlueGreen::writeReleaseEnv(
                                $releaseDir,
                                BlueGreen::PRODUCTION_HTTP,
                                BlueGreen::PRODUCTION_HTTPS,
                                $activeVersion
                            );
                        }

                        $containerName = ContainerName::resolveFromDir($releaseDir);
                        $runner->log("Starting containers: release={$activeVersion} container={$containerName} dir={$releaseDir}");

                        // Inject secrets (encrypted or AWS) into containers,
                        // same as the branch strategy path below.
                        $tmpEnv = SecretsProvider::resolveToTempFile($releaseDir);
                        if ($tmpEnv) {
                            $secretsFile = rtrim($releaseDir, '/') . '/.env.protocol-secrets';
                            copy($tmpEnv, $secretsFile);
                            chmod($secretsFile, 0600);
                            unlink($tmpEnv);

                            $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
                            $overrideFile = SecretsProvider::generateComposeOverride($composePath, $secretsFile);
                            $envFile = rtrim($releaseDir, '/') . '/.env.deployment';
                            $dockerCommand = Docker::getDockerCommand();

                            $runner->log("{$dockerCommand} up -d (with secrets + bluegreen env)");
                            Shell::run("cd " . escapeshellarg(rtrim($releaseDir, '/'))
                                . " && {$dockerCommand}"
                                . " --env-file " . escapeshellarg($envFile)
                                . " -f " . escapeshellarg($composePath)
                                . " -f " . escapeshellarg($overrideFile)
                                . " up -d 2>&1", $returnVar);
                            $started = $returnVar === 0;

                            unlink($secretsFile);
                            unlink($overrideFile);
                            $runner->log("Secrets temp files cleaned up");
                        } else {
                            $started = BlueGreen::startContainers($releaseDir);
                        }
                        $runner->log("startContainers result=" . ($started ? 'ok' : 'failed'));

                        // Verify the container is actually running
                        $isRunning = false;
                        if ($containerName) {
                            $isRunning = Docker::isDockerContainerRunning($containerName);
                            $runner->log("Post-start verify: container={$containerName} running={$isRunning}");
                        }

                        // Mark this version as active NOW that containers are confirmed running.
                        // The watcher set release.target earlier; this closes the gap so target == active.
                        // If we never reach here (crash/failure), target != active and the next
                        // watcher startup will detect the failed deploy and retry.
                        if ($isRunning || $started) {
                            $runner->log("Setting release.active={$activeVersion} (containers confirmed running)");
                            BlueGreen\ReleaseState::setActiveVersion($repo_dir, $activeVersion);
                            DeploymentState::writeDeploymentJson($releaseDir, [
                                'status' => 'active',
                                'deployed_at' => date('c'),
                            ]);
                        }
                        return;
                    }
                }
                // No active version yet — do NOT fall through to standard build.
                // Starting bare containers from repo_dir creates a container with
                // the un-versioned name (e.g. "ghostagent" instead of "ghostagent-v0.1.0"),
                // which conflicts with the release watcher's versioned containers.
                // The watcher will handle building and starting the correct release.
                $runner->log("No active release version found — skipping container start (watcher will deploy)");
                return;
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

            // Build the compose command with optional override files
            $portOverrideFlag = '';
            if ($portOverrideFile && is_file($portOverrideFile)) {
                $portOverrideFlag = ' -f ' . escapeshellarg($portOverrideFile);
                $runner->log("Using port override: {$portOverrideFile}");
            }

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
                    . $portOverrideFlag
                    . " up --build -d 2>&1");

                // Clean up secrets files immediately
                unlink($secretsFile);
                unlink($overrideFile);
                $runner->log("Secrets temp files cleaned up");
            } else {
                $runner->log("{$dockerCommand} up --build -d");
                Shell::run("cd " . escapeshellarg($repo_dir)
                    . " && {$dockerCommand}"
                    . " -f " . escapeshellarg($composePath)
                    . $portOverrideFlag
                    . " up --build -d 2>&1");
            }

            // Clean up port override file
            if ($portOverrideFile && is_file($portOverrideFile)) {
                unlink($portOverrideFile);
                $runner->log("Port override file cleaned up");
            }

        });

        // ── Dev compose services ─────────────────────────────────
        // Only for "none" strategy (local dev). Check for dev compose files
        // and offer to start their containers too.
        if ($strategy === 'none') {
            $devComposePath = DevCompose::find($repo_dir);
            if ($devComposePath) {
                $shouldStart = DevCompose::shouldAct($repo_dir, 'Start', $input, $output, $devComposePath);
                if ($shouldStart) {
                    $runner->run('Starting dev services', function() use ($runner, $repo_dir, $devComposePath) {
                        $result = DevCompose::start($repo_dir, $devComposePath);
                        $runner->log("output=" . trim($result));
                    });
                }
            }
        }

        // ── Stage 4: Post-start lifecycle hooks ──────────────────
        // For "none" strategy (local dev), use lifecycle.post_start_dev if defined,
        // otherwise fall back to lifecycle.post_start.
        $runner->run('Post-start hooks', function() use ($runner, $repo_dir, $strategy) {
            $hookKey = 'lifecycle.post_start';
            if ($strategy === 'none') {
                $devHooks = Json::read('lifecycle.post_start_dev', null, $repo_dir);
                if (is_array($devHooks)) {
                    $hookKey = 'lifecycle.post_start_dev';
                    $runner->log("strategy=none, using {$hookKey}");
                } else {
                    $runner->log("strategy=none, no post_start_dev defined, falling back to post_start");
                }
            }

            $postStart = Json::read($hookKey, [], $repo_dir);
            if (empty($postStart) || !is_array($postStart)) {
                $runner->log("No {$hookKey} hooks configured");
                return;
            }

            // For release/bluegreen, run hooks in the release dir
            $hookDir = $repo_dir;
            $envFile = null;
            if (BlueGreen::isEnabled($repo_dir)) {
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                if (!$activeVersion) {
                    $curDeploy = DeploymentState::current($repo_dir);
                    $activeVersion = $curDeploy['version'] ?? null;
                }
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    if (is_dir($releaseDir)) {
                        $hookDir = $releaseDir;
                        $bgEnv = rtrim($releaseDir, '/') . '/.env.deployment';
                        if (is_file($bgEnv)) {
                            $envFile = $bgEnv;
                        }
                    }
                }
            }

            $runner->log("Running " . count($postStart) . " {$hookKey} hook(s) in {$hookDir}");
            Lifecycle::runPostStart($hookDir, function($msg) use ($runner) {
                $runner->log($msg);
            }, $envFile, $hookKey);
        });

        // ── Stage 5: Security audit ─────────────────────────────
        // Skip for local dev — security/compliance checks are for deployed environments
        if ($strategy !== 'none') {
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
        }

        // ── Stage 6: SOC 2 readiness check ───────────────────────
        // Skip for local dev — compliance checks are for deployed environments
        if ($strategy !== 'none') {
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
        }

        // ── Stage 7: Health checks ──────────────────────────────
        $runner->run('Health checks', function() use ($runner, $repo_dir, $strategy) {
            $runner->log("Health check: strategy={$strategy} isEnabled=" . (BlueGreen::isEnabled($repo_dir) ? 'true' : 'false'));

            // For local dev, just verify containers are running — no watchers to check
            if ($strategy === 'none') {
                $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
                $runner->log("Health check (none): compose containers=" . implode(',', $containerNames));
                foreach ($containerNames as $name) {
                    $isRunning = Docker::isDockerContainerRunning($name);
                    $runner->log("Health check: container={$name} running={$isRunning}");
                    if (!$isRunning) {
                        throw new \RuntimeException("Container '{$name}' is not running");
                    }
                }
                return;
            }

            // For release/bluegreen, check the active release's container
            if (BlueGreen::isEnabled($repo_dir)) {
                $activeVersion = BlueGreen::getActiveVersion($repo_dir);
                $runner->log("Health check: getActiveVersion={$activeVersion}");
                if (!$activeVersion) {
                    $curDeploy = DeploymentState::current($repo_dir);
                    $activeVersion = $curDeploy['version'] ?? null;
                    $runner->log("Health check: DeploymentState fallback version={$activeVersion}");
                }
                if (!$activeVersion || $activeVersion === 'main') {
                    $project = NodeConfig::findByRepoDir($repo_dir);
                    if ($project) {
                        $nd = NodeConfig::load($project);
                        $nodeRelease = $nd['release']['active'] ?? null;
                        if ($nodeRelease) {
                            $activeVersion = $nodeRelease;
                            $runner->log("Health check: NodeConfig fallback version={$activeVersion}");
                        }
                    }
                }
                if ($activeVersion) {
                    $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                    $containerName = ContainerName::resolveFromDir($releaseDir);
                    $runner->log("Health check: releaseDir={$releaseDir} containerName={$containerName}");
                    if ($containerName) {
                        $isRunning = Docker::isDockerContainerRunning($containerName);
                        $runner->log("Health check: container={$containerName} running={$isRunning}");
                        if (!$isRunning) {
                            throw new \RuntimeException("Container '{$containerName}' is not running");
                        }
                        // Container is running — skip the fallback check below
                        $watcherPid = DeploymentState::watcherPid($repo_dir);
                        $runner->log("Health check: watcherPid={$watcherPid}");
                        if ($watcherPid && !Shell::isRunning($watcherPid)) {
                            throw new \RuntimeException('Deployment watcher is not running');
                        }
                        return;
                    } else {
                        $runner->log("Health check: no .env.deployment container name found, falling through to compose check");
                    }
                }
            }

            // Branch strategy or fallback: check containers from compose file in repo_dir
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
            $runner->log("Health check fallback: compose containers in repo_dir=" . implode(',', $containerNames));
            foreach ($containerNames as $name) {
                $isRunning = Docker::isDockerContainerRunning($name);
                $runner->log("Health check: container={$name} running={$isRunning}");
                if (!$isRunning) {
                    throw new \RuntimeException("Container '{$name}' is not running");
                }
            }

            // Check watcher is running
            $watcherPid = DeploymentState::watcherPid($repo_dir);
            $runner->log("Health check: watcherPid={$watcherPid}");
            if ($watcherPid && !Shell::isRunning($watcherPid)) {
                throw new \RuntimeException('Deployment watcher is not running');
            }
        }, 'PASS');

        // ── Stage 8: Disk space check ──────────────────────────
        $diskWarnings = [];
        $runner->run('Disk space check', function() use (&$diskWarnings) {
            $check = DiskCheck::check();
            $diskWarnings = DiskCheck::formatWarnings($check);

            if ($check['level'] === 'alert') {
                throw new \RuntimeException("Disk {$check['percent']}% full — cleanup recommended");
            }
        }, 'PASS');

        // ── Summary ─────────────────────────────────────────────
        $containerNames = ContainerName::resolveAll($repo_dir);

        $runner->log("Summary: containerNames=" . implode(',', $containerNames));
        $runningCount = 0;
        foreach ($containerNames as $name) {
            if (Docker::isDockerContainerRunning($name)) $runningCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$runningCount}/{$containerTotal} running"
            : 'none configured';

        $summaryInfo = [
            'Environment' => $environment,
            'Strategy'    => $strategy,
            'Containers'  => $containerStatus,
        ];

        if ($strategy !== 'none') {
            $curDeploy = DeploymentState::current($repo_dir);
            $version = ($curDeploy['version'] ?? null)
                ?: trim(Shell::run("cd " . escapeshellarg($repo_dir) . " && git describe --tags --always 2>/dev/null") ?: 'unknown');
            $summaryInfo['Strategy'] = $strategy . ($version !== 'unknown' ? " ({$version})" : '');
            $summaryInfo['Secrets'] = Secrets::hasKey() ? 'decrypted' : 'no key found';

            $watcherType = DeploymentState::strategy($repo_dir);
            $watcherPid = DeploymentState::watcherPid($repo_dir);
            $summaryInfo['Watchers'] = "{$watcherType} watcher " . (($watcherPid && Shell::isRunning($watcherPid)) ? 'running' : 'not running');
            $summaryInfo['Crontab'] = Crontab::hasCrontabRestart($repo_dir) ? 'installed' : 'not installed';
            $summaryInfo['Cleanup'] = Crontab::hasDockerCleanup($repo_dir) ? 'scheduled' : 'not scheduled';
        }

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
