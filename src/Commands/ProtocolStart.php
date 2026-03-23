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
use Symfony\Component\Console\Question\ChoiceQuestion;
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

    private StageRunner $runner;
    private InputInterface $input;
    private OutputInterface $output;

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
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', null)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force start, ignoring any existing lock')
            // ...
        ;
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTER — figures out the situation, picks the controller
    // ═══════════════════════════════════════════════════════════════

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->output = $output;
        $this->runner = new StageRunner($output, $output->isVerbose());

        $output->writeln('');

        // Acquire lock
        if (!$this->acquireLock()) {
            return Command::SUCCESS;
        }

        // Resolve which directory to operate on
        $dir = $this->resolveDirectory();
        if (!$dir) {
            $output->writeln('<error>Could not determine which directory to start.</error>');
            return Command::FAILURE;
        }

        // Migrate from protocol.lock if it exists (one-time, idempotent)
        DeploymentState::migrateFromLockFile($dir);

        return $this->startDirect($dir);
    }

    // ═══════════════════════════════════════════════════════════════
    //  RESOLVER — determines which directory to operate on
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
            // cwd is a plain git repo (local dev) — use it
            if (Git::isInitializedRepo($repo_dir)) {
                return $repo_dir;
            }
        }

        // 3. Ambient mode: resolve slave node, then pick interactively
        $projectArg = $this->input->getArgument('project');
        $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        if ($resolved) {
            [$nodeConfig, $nodeData, $activeDir] = $resolved;
            return $this->pickRelease($nodeData, $activeDir);
        }

        return $repo_dir;
    }

    private function pickRelease(array $nodeData, string $defaultDir): string
    {
        // Non-interactive (cron, watcher nohup): use default
        if (!$this->input->isInteractive()) {
            return $defaultDir;
        }

        $releasesDir = $nodeData['bluegreen']['releases_dir']
            ?? $nodeData['release']['releases_dir']
            ?? null;
        if (!$releasesDir || !is_dir($releasesDir)) {
            return $defaultDir;
        }

        $releases = BlueGreen::listReleases($defaultDir);
        if (empty($releases)) {
            return $defaultDir;
        }

        $activeVersion = $nodeData['release']['active'] ?? null;

        // Build choices with active marker
        $choices = [];
        $defaultIndex = 0;
        foreach ($releases as $i => $release) {
            $label = $release;
            if ($release === $activeVersion) {
                $label .= ' (active)';
                $defaultIndex = $i;
            }
            $choices[] = $label;
        }

        $question = new ChoiceQuestion(
            'Select a release to start:',
            $choices,
            $defaultIndex
        );
        $selected = $this->getHelper('question')->ask($this->input, $this->output, $question);

        // Strip the " (active)" marker
        $version = preg_replace('/ \(active\)$/', '', $selected);
        return BlueGreen::getReleaseDir($defaultDir, $version);
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTROLLER — calls workers in order, no work done here
    // ═══════════════════════════════════════════════════════════════

    private function startDirect(string $dir): int
    {
        $ctx = $this->buildContext($dir);

        $this->scanCodebase($dir);
        $this->provisionInfrastructure($dir, $ctx);

        $portOverrideFile = $this->detectPortConflicts($dir, $ctx);
        $ctx['portOverrideFile'] = $portOverrideFile;

        $this->startContainers($dir, $ctx);
        // Spawn watchers AFTER startContainers sets release.active —
        // otherwise the watcher sees target != active and triggers a
        // duplicate stop+start cycle that kills what we just started.
        $this->provisionSlaveWatchers($dir, $ctx);
        $this->startDevServices($dir, $ctx);
        $this->runPostStartHooks($dir, $ctx);
        $this->runSecurityAudit($dir, $ctx);
        $this->runSoc2Check($dir, $ctx);
        $this->verifyHealth($dir, $ctx);
        $this->checkDiskSpace();
        $this->writeSummary($dir, $ctx);

        // Clear cached data so status reads fresh state from disk
        Json::clearInstances();

        // Run protocol status to show full dashboard
        $statusArgs = new ArrayInput(['--dir' => $dir]);
        $this->getApplication()->find('status')->run($statusArgs, $this->output);

        return Command::SUCCESS;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTEXT BUILDER — reads all metadata once, passed to workers
    // ═══════════════════════════════════════════════════════════════

    private function buildContext(string $dir): array
    {
        $nodeConfig = null;
        $nodeData = [];

        // Check if dir is inside a slave node's releases
        $match = NodeConfig::findByActiveDir($dir);
        if ($match) {
            [$nodeConfig, $nodeData] = $match;
        }

        // If not found via activeDir, try resolveSlaveNode
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

        $environment = $this->input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment && $nodeConfig) {
            $environment = $nodeData['environment'] ?? 'production';
        }
        if (!$environment) {
            $question = new Question('What is the current env we need to configure protocol for globally? This must be set:', 'localhost');
            $environment = $this->getHelper('question')->ask($this->input, $this->output, $question);
            Config::write('env', $environment);
        }

        $devEnvs = ['localhost', 'local', 'dev', 'development'];
        $isDev = (in_array($environment, $devEnvs) || strpos($environment, 'localhost') !== false);

        $configRepo = Config::repo($dir);
        $configRemote = Json::read('configuration.remote', false, $dir);
        if (!$configRemote && $nodeConfig) {
            $configRemote = $nodeData['configuration']['remote'] ?? false;
        }

        // Extract version from dir basename for release directories
        $version = basename(rtrim($dir, '/'));

        return [
            'strategy'       => $strategy,
            'environment'    => $environment,
            'isDev'          => $isDev,
            'nodeConfig'     => $nodeConfig,
            'nodeData'       => $nodeData,
            'version'        => $version,
            'configRepo'     => $configRepo,
            'configRemote'   => $configRemote,
            'hasConfigRepo'  => $configRemote || is_dir($configRepo),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  WORKERS — each does one thing, never makes decisions
    // ═══════════════════════════════════════════════════════════════

    private function acquireLock(): bool
    {
        $store = SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore();
        $this->lock = (new LockFactory($store))->createLock($this->getName(), self::LOCK_TTL);
        if (!$this->lock->acquire()) {
            if ($this->input->getOption('force')) {
                $this->lock->acquire(true);
                $this->output->writeln('<comment>Forcing lock override...</comment>');
            } else {
                $this->output->writeln('The command is already running in another process. Use --force (-f) to override.');
                $this->output->writeln('<comment>Lock auto-expires after ' . self::LOCK_TTL . ' seconds.</comment>');
                return false;
            }
        }
        return true;
    }

    private function scanCodebase(string $dir): void
    {
        $this->runner->run('Scanning codebase', function() use ($dir) {
            if (!Git::isInitializedRepo($dir)) {
                throw new \RuntimeException('Not an initialized Protocol project');
            }
            if (!Docker::isDockerInitialized($dir)) {
                throw new \RuntimeException('No docker-compose.yml found');
            }
        });
    }

    private function provisionInfrastructure(string $dir, array $ctx): void
    {
        $this->provisionGitHubCredentials($dir, $ctx);
        $this->provisionConfigRepo($dir, $ctx);
        // NOTE: provisionSlaveWatchers() is called AFTER startContainers() in
        // startDirect() to avoid a race condition. The watcher must not start
        // polling until release.active is set by startContainers().
        $this->provisionCrontab($dir, $ctx);
    }

    private function provisionGitHubCredentials(string $dir, array $ctx): void
    {
        if (!GitHubApp::isConfigured()) {
            return;
        }

        $runner = $this->runner;
        $runner->run('GitHub App credentials', function() use ($runner, $dir, $ctx) {
            $creds = GitHubApp::loadCredentials();
            $appOwner = $creds['owner'] ?? null;
            if ($appOwner) {
                $runner->log("Refreshing credentials for {$appOwner}");
                $refreshed = GitHubApp::refreshGitCredentials($appOwner);
                if (!$refreshed) {
                    throw new \RuntimeException("GitHub App credential refresh failed for {$appOwner}");
                }
                $runner->log("Credentials refreshed");
            }

            // Fix project remote URL
            $currentRemote = trim(Shell::run("git -C " . escapeshellarg($dir) . " remote get-url origin 2>/dev/null") ?: '');
            $resolvedRemote = GitHubApp::resolveUrl($currentRemote);
            if ($currentRemote && $resolvedRemote !== $currentRemote) {
                $runner->log("Updating remote URL: {$currentRemote} → {$resolvedRemote}");
                Shell::run("git -C " . escapeshellarg($dir) . " remote set-url origin " . escapeshellarg($resolvedRemote) . " 2>/dev/null");
            }

            // Fix config repo remote URL
            if (is_dir($ctx['configRepo'])) {
                $configCurrentRemote = trim(Shell::run("git -C " . escapeshellarg($ctx['configRepo']) . " remote get-url origin 2>/dev/null") ?: '');
                $configResolvedRemote = GitHubApp::resolveUrl($configCurrentRemote);
                if ($configCurrentRemote && $configResolvedRemote !== $configCurrentRemote) {
                    $runner->log("Updating config repo remote: {$configCurrentRemote} → {$configResolvedRemote}");
                    Shell::run("git -C " . escapeshellarg($ctx['configRepo']) . " remote set-url origin " . escapeshellarg($configResolvedRemote) . " 2>/dev/null");
                }
            }
        });
    }

    private function provisionConfigRepo(string $dir, array $ctx): void
    {
        if (!$ctx['hasConfigRepo']) {
            return;
        }

        $runner = $this->runner;
        $force = $this->input->getOption('force');
        $arrInput = new ArrayInput(['--dir' => $dir] + ($force ? ['--force' => true] : []));
        $subOutput = $this->output->isVerbose() ? $this->output : new NullOutput();
        $app = $this->getApplication();

        $runner->run('Configuration repo', function() use ($runner, $app, $arrInput, $subOutput, $ctx) {
            // Start config watcher for non-dev strategies with a remote
            if ($ctx['strategy'] !== 'none' && $ctx['configRemote']) {
                $runner->log("Running config:slave");
                $app->find('config:slave')->run($arrInput, $subOutput);
            }
            // Link config for all strategies
            $runner->log("Running config:link");
            $app->find('config:link')->run($arrInput, $subOutput);
        });
    }

    private function provisionSlaveWatchers(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $force = $this->input->getOption('force');
        $arrInput = new ArrayInput(['--dir' => $dir] + ($force ? ['--force' => true] : []));
        $subOutput = $this->output->isVerbose() ? $this->output : new NullOutput();
        $app = $this->getApplication();

        if (in_array($ctx['strategy'], ['release', 'bluegreen'])) {
            $runner->run('Deploy watcher', function() use ($runner, $app, $arrInput, $subOutput) {
                $runner->log("Running deploy:slave");
                $app->find('deploy:slave')->run($arrInput, $subOutput);
                $runner->log("deploy:slave returned");
            });
        } elseif ($ctx['strategy'] === 'none') {
            $runner->log("strategy=none, skipping watchers");
        } else {
            // Legacy branch-based deployment
            $runner->run('Git watcher', function() use ($runner, $app, $arrInput, $subOutput, $ctx) {
                if (!$ctx['isDev']) {
                    $runner->log("Running git:pull");
                    $app->find('git:pull')->run($arrInput, $subOutput);
                    $runner->log("Running git:slave");
                    $app->find('git:slave')->run($arrInput, $subOutput);
                }
            });
        }
    }

    private function provisionCrontab(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] === 'none') {
            return;
        }

        $runner = $this->runner;
        $runner->run('Crontab setup', function() use ($runner, $dir) {
            $runner->log("Adding crontab restart");
            Crontab::addCrontabRestart($dir);
        });
    }

    private function detectPortConflicts(string $dir, array $ctx): ?string
    {
        if ($ctx['strategy'] !== 'none') {
            return null;
        }

        $conflicts = PortConflict::detectConflicts($dir);
        if (empty($conflicts)) {
            return null;
        }

        $alternatives = PortConflict::suggestAlternatives($conflicts);
        $resolution = PortConflict::promptUser($conflicts, $alternatives, $this->input, $this->output, $dir);

        if ($resolution === null) {
            $this->output->writeln('  <fg=red>Startup aborted due to port conflicts.</>');
            return null;
        }
        if ($resolution === 'remap') {
            $file = PortConflict::generateOverrideFile($dir, $alternatives);
            $this->runner->log("Port override file generated: {$file}");
            return $file;
        }

        return null;
    }

    private function startContainers(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $portOverrideFile = $ctx['portOverrideFile'] ?? null;

        $runner->run('Container build & start', function() use ($runner, $dir, $ctx, $portOverrideFile) {
            $runner->log("strategy={$ctx['strategy']} dir={$dir}");

            // Release/bluegreen: $dir IS the release directory. Start it.
            if (BlueGreen::isEnabled($dir) || in_array($ctx['strategy'], ['release', 'bluegreen'])) {
                BlueGreen::patchComposeFile($dir);

                $version = $ctx['version'];
                if ($ctx['strategy'] === 'release') {
                    $runner->log("Writing production ports (80/443) for release strategy");
                    BlueGreen::writeReleaseEnv(
                        $dir,
                        BlueGreen::PRODUCTION_HTTP,
                        BlueGreen::PRODUCTION_HTTPS,
                        $version
                    );
                }

                $containerName = ContainerName::resolveFromDir($dir);
                $runner->log("Starting containers: version={$version} container={$containerName} dir={$dir}");

                $started = $this->dockerUpWithSecrets($dir, $runner);
                $runner->log("startContainers result=" . ($started ? 'ok' : 'failed'));

                // Verify and mark active
                $isRunning = $containerName ? Docker::isDockerContainerRunning($containerName) : false;
                $runner->log("Post-start verify: container={$containerName} running=" . ($isRunning ? '1' : '0'));

                if ($isRunning || $started) {
                    $runner->log("Setting release.active={$version}");
                    BlueGreen\ReleaseState::setActiveVersion($dir, $version);
                    DeploymentState::writeDeploymentJson($dir, [
                        'status' => 'active',
                        'deployed_at' => date('c'),
                    ]);
                }
                return;
            }

            // Standard single-container mode (branch/none strategy)
            $composePath = rtrim($dir, '/') . '/docker-compose.yml';
            if (!file_exists($composePath)) {
                return;
            }

            // Pull or build
            $content = file_get_contents($composePath);
            $usesBuild = (bool) preg_match('/^\s+build:/m', $content);
            if ($usesBuild) {
                $dockerCmd = Docker::getDockerCommand();
                $runner->log("{$dockerCmd} build");
                Shell::run("{$dockerCmd} -f " . escapeshellarg($composePath) . " build 2>&1");
            } else {
                $image = Json::read('docker.image', false, $dir);
                if ($image) {
                    $runner->log("docker pull {$image}");
                    Shell::run("docker pull " . escapeshellarg($image) . " 2>&1");
                }
            }

            // Start containers
            $dockerCommand = Docker::getDockerCommand();
            $tmpEnv = SecretsProvider::resolveToTempFile($dir);

            $portOverrideFlag = '';
            if ($portOverrideFile && is_file($portOverrideFile)) {
                $portOverrideFlag = ' -f ' . escapeshellarg($portOverrideFile);
                $runner->log("Using port override: {$portOverrideFile}");
            }

            if ($tmpEnv) {
                $secretsFile = rtrim($dir, '/') . '/.env.protocol-secrets';
                copy($tmpEnv, $secretsFile);
                chmod($secretsFile, 0600);
                unlink($tmpEnv);

                $overrideFile = SecretsProvider::generateComposeOverride($composePath, $secretsFile);

                $runner->log("{$dockerCommand} up --build -d (with secrets)");
                Shell::run("cd " . escapeshellarg($dir)
                    . " && {$dockerCommand} -f " . escapeshellarg($composePath)
                    . " -f " . escapeshellarg($overrideFile)
                    . $portOverrideFlag
                    . " up --build -d 2>&1");

                unlink($secretsFile);
                unlink($overrideFile);
                $runner->log("Secrets temp files cleaned up");
            } else {
                $runner->log("{$dockerCommand} up --build -d");
                Shell::run("cd " . escapeshellarg($dir)
                    . " && {$dockerCommand}"
                    . " -f " . escapeshellarg($composePath)
                    . $portOverrideFlag
                    . " up --build -d 2>&1");
            }

            if ($portOverrideFile && is_file($portOverrideFile)) {
                unlink($portOverrideFile);
                $runner->log("Port override file cleaned up");
            }
        });
    }

    private function dockerUpWithSecrets(string $dir, StageRunner $runner): bool
    {
        $tmpEnv = SecretsProvider::resolveToTempFile($dir);
        if ($tmpEnv) {
            $secretsFile = rtrim($dir, '/') . '/.env.protocol-secrets';
            copy($tmpEnv, $secretsFile);
            chmod($secretsFile, 0600);
            unlink($tmpEnv);

            $composePath = rtrim($dir, '/') . '/docker-compose.yml';
            $overrideFile = SecretsProvider::generateComposeOverride($composePath, $secretsFile);
            $envFile = rtrim($dir, '/') . '/.env.deployment';
            $dockerCommand = Docker::getDockerCommand();

            $runner->log("{$dockerCommand} up -d (with secrets + deployment env)");
            Shell::run("cd " . escapeshellarg(rtrim($dir, '/'))
                . " && {$dockerCommand}"
                . " --env-file " . escapeshellarg($envFile)
                . " -f " . escapeshellarg($composePath)
                . " -f " . escapeshellarg($overrideFile)
                . " up -d 2>&1", $returnVar);
            $started = $returnVar === 0;

            unlink($secretsFile);
            unlink($overrideFile);
            $runner->log("Secrets temp files cleaned up");
            return $started;
        }

        return BlueGreen::startContainers($dir);
    }

    private function startDevServices(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] !== 'none') {
            return;
        }

        $devComposePath = DevCompose::find($dir);
        if (!$devComposePath) {
            return;
        }

        $shouldStart = DevCompose::shouldAct($dir, 'Start', $this->input, $this->output, $devComposePath);
        if ($shouldStart) {
            $runner = $this->runner;
            $runner->run('Starting dev services', function() use ($runner, $dir, $devComposePath) {
                $result = DevCompose::start($dir, $devComposePath);
                $runner->log("output=" . trim($result));
            });
        }
    }

    private function runPostStartHooks(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $runner->run('Post-start hooks', function() use ($runner, $dir, $ctx) {
            $hookKey = 'lifecycle.post_start';
            if ($ctx['strategy'] === 'none') {
                $devHooks = Json::read('lifecycle.post_start_dev', null, $dir);
                if (is_array($devHooks)) {
                    $hookKey = 'lifecycle.post_start_dev';
                    $runner->log("strategy=none, using {$hookKey}");
                }
            }

            $postStart = Json::read($hookKey, [], $dir);
            if (empty($postStart) || !is_array($postStart)) {
                $runner->log("No {$hookKey} hooks configured");
                return;
            }

            $envFile = null;
            $bgEnv = rtrim($dir, '/') . '/.env.deployment';
            if (is_file($bgEnv)) {
                $envFile = $bgEnv;
            }

            $runner->log("Running " . count($postStart) . " {$hookKey} hook(s) in {$dir}");
            Lifecycle::runPostStart($dir, function($msg) use ($runner) {
                $runner->log($msg);
            }, $envFile, $hookKey);
        });
    }

    private function runSecurityAudit(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] === 'none') {
            return;
        }
        $this->runner->run('Running security audit', function() use ($dir) {
            $audit = new SecurityAudit($dir);
            $audit->runAll();
            Webhook::notifyAudit('security_audit', $dir, $audit->getResults(), $audit->passed());
            if (!$audit->passed()) {
                $failures = array_filter($audit->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');
    }

    private function runSoc2Check(string $dir, array $ctx): void
    {
        if ($ctx['strategy'] === 'none') {
            return;
        }
        $this->runner->run('SOC 2 readiness check', function() use ($dir) {
            $check = new Soc2Check($dir);
            $check->runAll();
            Webhook::notifyAudit('soc2_check', $dir, $check->getResults(), $check->passed());
            if (!$check->passed()) {
                $failures = array_filter($check->getResults(), fn($r) => $r['status'] === 'fail');
                $messages = array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures);
                throw new \RuntimeException(implode("\n", $messages));
            }
        }, 'PASS');
    }

    private function verifyHealth(string $dir, array $ctx): void
    {
        $runner = $this->runner;
        $runner->run('Health checks', function() use ($runner, $dir, $ctx) {
            // Check containers in $dir are running
            $containerName = ContainerName::resolveFromDir($dir);
            if ($containerName) {
                $isRunning = Docker::isDockerContainerRunning($containerName);
                $runner->log("Health check: container={$containerName} running=" . ($isRunning ? '1' : '0'));
                if (!$isRunning) {
                    throw new \RuntimeException("Container '{$containerName}' is not running");
                }
            } else {
                // Fallback: check containers from compose file
                $containerNames = Docker::getContainerNamesFromDockerComposeFile($dir);
                $runner->log("Health check: compose containers=" . implode(',', $containerNames));
                foreach ($containerNames as $name) {
                    $isRunning = Docker::isDockerContainerRunning($name);
                    $runner->log("Health check: container={$name} running=" . ($isRunning ? '1' : '0'));
                    if (!$isRunning) {
                        throw new \RuntimeException("Container '{$name}' is not running");
                    }
                }
            }

            // Check watcher is running (skip for local dev)
            if ($ctx['strategy'] !== 'none') {
                $watcherPid = DeploymentState::watcherPid($dir);
                $runner->log("Health check: watcherPid={$watcherPid}");
                if ($watcherPid && !Shell::isRunning($watcherPid)) {
                    throw new \RuntimeException('Deployment watcher is not running');
                }
            }
        }, 'PASS');
    }

    private function checkDiskSpace(): void
    {
        $diskWarnings = [];
        $this->runner->run('Disk space check', function() use (&$diskWarnings) {
            $check = DiskCheck::check();
            $diskWarnings = DiskCheck::formatWarnings($check);
            if ($check['level'] === 'alert') {
                throw new \RuntimeException("Disk {$check['percent']}% full — cleanup recommended");
            }
        }, 'PASS');

        if (!empty($diskWarnings)) {
            $this->output->writeln('');
            $this->output->writeln('  <fg=yellow;options=bold>Disk Space Warning</>');
            foreach ($diskWarnings as $warning) {
                $this->output->writeln("    {$warning}");
            }
            $this->output->writeln('');
        }
    }

    private function writeSummary(string $dir, array $ctx): void
    {
        $containerNames = ContainerName::resolveAll($dir);
        if (empty($containerNames)) {
            $containerNames = Docker::getContainerNamesFromDockerComposeFile($dir);
        }

        $this->runner->log("Summary: containerNames=" . implode(',', $containerNames));
        $runningCount = 0;
        foreach ($containerNames as $name) {
            if (Docker::isDockerContainerRunning($name)) $runningCount++;
        }
        $containerTotal = count($containerNames);
        $containerStatus = $containerTotal > 0
            ? "{$runningCount}/{$containerTotal} running"
            : 'none configured';

        $summaryInfo = [
            'Environment' => $ctx['environment'],
            'Strategy'    => $ctx['strategy'],
            'Containers'  => $containerStatus,
        ];

        if ($ctx['strategy'] !== 'none') {
            $summaryInfo['Strategy'] = $ctx['strategy'] . " ({$ctx['version']})";
            $summaryInfo['Secrets'] = Secrets::hasKey() ? 'decrypted' : 'no key found';

            $watcherPid = DeploymentState::watcherPid($dir);
            $summaryInfo['Watchers'] = "{$ctx['strategy']} watcher " . (($watcherPid && Shell::isRunning($watcherPid)) ? 'running' : 'not running');
            $summaryInfo['Crontab'] = Crontab::hasCrontabRestart($dir) ? 'installed' : 'not installed';
            $summaryInfo['Cleanup'] = Crontab::hasDockerCleanup($dir) ? 'scheduled' : 'not scheduled';
        }

        $this->runner->writeSummary($summaryInfo);

        if (!$ctx['isDev'] && !Crontab::hasDockerCleanup($dir) && BlueGreen::isEnabled($dir)) {
            $this->output->writeln('  <fg=yellow>!</> Blue-green deployment detected without scheduled Docker cleanup.');
            $this->output->writeln('    <fg=gray>Old images will accumulate. Enable with:</> <fg=white>protocol docker:cleanup:schedule on</>');
            $this->output->writeln('');
        }
    }

}
