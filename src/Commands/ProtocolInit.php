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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;
use Gitcd\Commands\Init\ProjectType;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\BlueGreen\ReleaseBuilder;
use Gitcd\Utils\NodeConfig;
use Gitcd\Commands\Init\DotMenuTrait;

Class ProtocolInit extends Command {

    use LockableTrait;
    use DotMenuTrait;

    /**
     * Current protocol.json schema version.
     * Bump this when the config format changes so fix/migrate knows what to update.
     *
     * Version history:
     *   0 — no version field (legacy, pre-versioning)
     *   1 — initial versioned schema: build context with .git suffix,
     *       scaffold dirs, project_type field, deployment.strategy
     */
    const SCHEMA_VERSION = 1;

    protected static $defaultName = 'init';
    protected static $defaultDescription = 'Initialize a new project or connect an existing repository';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Walk through setting up Protocol for a new or existing project.

            This command will:
            1. Verify your repository is compatible (git, docker, etc.)
            2. Create or update protocol.json with project settings
            3. Choose a deployment strategy (release-based or branch-based)
            4. Optionally set up encrypted secrets
            5. Optionally initialize a configuration repository

            Safe to run on existing Protocol projects — it detects current
            state and offers to update rather than overwrite.

            HELP)
        ;
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('with-config', 'c', InputOption::VALUE_NONE, 'Also initialize configuration repository')
        ;
    }

    /**
     * Get available project initializers
     */
    protected function getAvailableInitializers(): array
    {
        return ProjectType::all();
    }

    // ─── Display helpers ─────────────────────────────────────────

    protected function writeBanner(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>PROTOCOL</> <fg=gray>·</> <fg=yellow>Project Setup Wizard</>                       <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Release-based deployment for Docker applications</>   <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function clearAndBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $this->writeBanner($output);
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        $this->clearAndBanner($output);
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }

    protected function writeInfo(OutputInterface $output, string $message): void
    {
        $output->writeln("    <fg=gray>›</> {$message}");
    }

    /**
     * Detect project scenario
     *
     * @return string 'new' | 'existing' | 'protocol'
     */
    protected function detectScenario(string $repo_dir): string
    {
        $hasProtocolJson = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'protocol.json');
        if ($hasProtocolJson) {
            return 'protocol';
        }

        $isGitRepo = Git::isInitializedRepo($repo_dir);
        if ($isGitRepo) {
            return 'existing';
        }

        return 'new';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir) {
            $repo_dir = getcwd();
        }
        $helper = $this->getHelper('question');
        $io = new SymfonyStyle($input, $output);

        $this->clearAndBanner($output);

        $output->writeln("    <fg=gray>Directory:</> <fg=white>{$repo_dir}</>");
        $output->writeln('');

        // ── Step 1: What environment is this? ────────────────────
        // Check if environment was already set (idempotent re-entry)
        $storedEnv = \Gitcd\Helpers\Config::read('env');
        if ($storedEnv && in_array($storedEnv, ['production', 'staging'])) {
            $output->writeln("    <fg=gray>Environment:</> <fg=white;options=bold>{$storedEnv}</> <fg=gray>(previously configured)</>");
            $output->writeln('');
            $envKey = $storedEnv;
        } else {
            $output->writeln("    <fg=gray>What environment is this node?</>");
            $output->writeln('');

            $envKey = $this->askWithDots($input, $output, $helper, [
                'development' => 'Development — local machine',
                'staging'     => 'Staging — pre-production testing',
                'production'  => 'Production — live environment',
            ], 'development');
        }

        // ── Production / Staging → slave node ────────────────────
        if ($envKey === 'production' || $envKey === 'staging') {
            return $this->flowSlaveNode($repo_dir, $input, $output, $helper, $io, $envKey);
        }

        // ── Development → auto-detect project state ──────────────
        $isGitRepo = Git::isInitializedRepo($repo_dir);
        $hasProtocolJson = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'protocol.json');

        // ── No git repo → ask for the repository URL first ───────
        if (!$isGitRepo) {
            $output->writeln('');
            $output->writeln("    <fg=gray>No git repository detected in this directory.</>");
            $output->writeln('');

            $question = new Question('    Repository URL: ');
            $gitRemote = $helper->ask($input, $output, $question);

            if (!$gitRemote) {
                $output->writeln('');
                $output->writeln('    <error>A repository URL is required.</error>');
                return Command::FAILURE;
            }

            // Clone the repo into this directory or init + add remote
            Shell::run("git -C " . escapeshellarg($repo_dir) . " init");
            Shell::run("git -C " . escapeshellarg($repo_dir) . " remote add origin " . escapeshellarg($gitRemote) . " 2>/dev/null");
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Repository: <fg=white>{$gitRemote}</>");

            // Re-check for protocol.json (it won't exist yet since we just init'd)
            return $this->flowSetupProject($repo_dir, $input, $output, $helper, $io);
        }

        // ── Has git, no protocol.json → new install ──────────────
        if (!$hasProtocolJson) {
            $output->writeln('');
            $output->writeln("    <fg=white;options=bold>New Project</>");
            $output->writeln("    <fg=gray>Protocol is not installed in this directory.</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                "    Install Protocol in <fg=white>{$repo_dir}</> ? [<fg=green>Y</>/n] ", true
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                $output->writeln('');
                return Command::SUCCESS;
            }

            return $this->flowSetupProject($repo_dir, $input, $output, $helper, $io);
        }

        // ── Has protocol.json → check schema version ────────────
        $currentVersion = (int) Json::read('protocol_version', 0, $repo_dir);
        $targetVersion = self::SCHEMA_VERSION;

        if ($currentVersion < $targetVersion) {
            $projectName = Json::read('name', basename($repo_dir), $repo_dir);
            $output->writeln('');
            $output->writeln("    <fg=yellow;options=bold>Migration Required</>");
            $output->writeln("    <fg=gray>Project:</> <fg=white>{$projectName}</>");
            $output->writeln("    <fg=gray>Schema version:</> <fg=yellow>v{$currentVersion}</> → <fg=green>v{$targetVersion}</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Migrate to the latest version? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                return $this->flowFixMigrate($repo_dir, $input, $output, $helper, $io);
            }
            $output->writeln('');
            $output->writeln('    <fg=gray>No changes made.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        // ── Up-to-date protocol.json → check config repo ────────
        $hasConfigRepo = Json::read('configuration.local', false, $repo_dir);
        if (!$hasConfigRepo) {
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Protocol is installed and up to date");
            $output->writeln("    <fg=gray>No configuration repository detected.</>");
            $output->writeln('');

            $initializers = $this->getAvailableInitializers();
            $initializer = reset($initializers);

            $question = new ConfirmationQuestion(
                '    Set up a configuration repository? [y/<fg=green>N</>] ', false
            );
            if ($helper->ask($input, $output, $question)) {
                $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
            }
            return Command::SUCCESS;
        }

        // ── Everything is set up ─────────────────────────────────
        $projectName = Json::read('name', basename($repo_dir), $repo_dir);
        $output->writeln('');
        $output->writeln("    <fg=green;options=bold>✓  All set!</>");
        $output->writeln("    <fg=gray>Project:</> <fg=white>{$projectName}</>");
        $output->writeln("    <fg=gray>Protocol is installed and configured.</>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    // ─── Flow: Setup Project (unified new + existing) ─────────────

    protected function flowSetupProject(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $step = 0;
        $hasDockerCompose = file_exists(rtrim($repo_dir, '/') . '/docker-compose.yml');
        $totalSteps = $hasDockerCompose ? 1 : 2;

        // Step: Docker container (only if no docker-compose.yml)
        if (!$hasDockerCompose) {
            $this->writeStep($output, ++$step, $totalSteps, 'Docker Container');
            $output->writeln("    <fg=gray>Protocol manages the Docker container for your project.</>");
            $output->writeln("    <fg=gray>No docker-compose.yml was found. We'll set one up for you.</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>We suggest going with the latest in-house container.</>");
            $output->writeln("    <fg=gray>You can also choose an older version or enter your own.</>");
            $output->writeln('');

            $selectedInitializer = $this->selectProjectType($input, $output, $helper);
            $selectedInitializer->initialize($repo_dir, $input, $output, $helper);
        } else {
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> docker-compose.yml detected");
            $initializers = $this->getAvailableInitializers();
            $selectedInitializer = reset($initializers);
        }

        // Step: Create protocol.json
        $this->writeStep($output, ++$step, $totalSteps, 'Protocol Configuration');
        $selectedKey = $this->getInitializerKey($selectedInitializer);
        $selectedInitializer->createProtocolJson($repo_dir, $selectedKey, $output, self::SCHEMA_VERSION);

        // Done
        $this->writeCompletion($output, $repo_dir);

        return Command::SUCCESS;
    }

    // ─── Flow: Protocol Project ──────────────────────────────────

    protected function flowProtocolProject(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $currentStrategy = Json::read('deployment.strategy', 'branch', $repo_dir);
        $projectName = Json::read('name', basename($repo_dir), $repo_dir);

        $this->clearAndBanner($output);
        $output->writeln("    <fg=white;options=bold>{$projectName}</>");
        $output->writeln("    <fg=gray>Strategy:</> <fg=cyan>{$currentStrategy}</>  <fg=gray>·</>  <fg=gray>Dir:</> <fg=white>{$repo_dir}</>");
        $output->writeln('');
        $output->writeln("    <fg=gray>What would you like to update?</>");
        $output->writeln('');

        $actionKey = $this->askWithDots($input, $output, $helper, [
            'fix'      => 'Fix / Migrate — regenerate configs, fix paths, update structure',
            'settings' => 'Re-run full project setup from scratch',
            'strategy' => 'Change deployment strategy',
            'secrets'  => 'Set up encrypted secrets',
            'config'   => 'Initialize configuration repository',
            'exit'     => 'Exit without changes',
        ], 'fix');

        switch ($actionKey) {
            case 'fix':
                return $this->flowFixMigrate($repo_dir, $input, $output, $helper, $io);

            case 'settings':
                return $this->flowSetupProject($repo_dir, $input, $output, $helper, $io);

            case 'strategy':
                $output->writeln('');
                $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper);
                $output->writeln('');
                return Command::SUCCESS;

            case 'secrets':
                $output->writeln('');
                $command = $this->getApplication()->find('secrets:setup');
                $command->run(new ArrayInput([]), $output);
                return Command::SUCCESS;

            case 'config':
                $output->writeln('');
                $initializers = $this->getAvailableInitializers();
                $initializer = reset($initializers);
                $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
                return Command::SUCCESS;

            case 'exit':
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                $output->writeln('');
                return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    // ─── Flow: Slave Node ─────────────────────────────────────

    protected function flowSlaveNode(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io,
        string $environment = 'production'
    ): int {
        $totalSteps = 3;

        // Check for existing node config (idempotent re-entry)
        $existingProject = NodeConfig::findByRepoDir($repo_dir);
        $existingData = $existingProject ? NodeConfig::load($existingProject) : [];
        $storedRemote = $existingData['git']['remote'] ?? null;

        // Step 1: Repository URL
        if ($storedRemote) {
            // Already configured — show and confirm
            $this->writeStep($output, 1, $totalSteps, 'Repository');
            $output->writeln("    <fg=green>✓</> Remote: <fg=white>{$storedRemote}</> <fg=gray>(previously configured)</>");
            $gitRemote = $storedRemote;
        } else {
            $this->writeStep($output, 1, $totalSteps, 'Repository');

            $output->writeln("    <fg=gray>This node will watch a repository and automatically deploy</>");
            $output->writeln("    <fg=gray>new releases. Tell us the repository to listen to.</>");
            $output->writeln('');

            $existingRemote = Git::RemoteUrl($repo_dir);
            $defaultRemote = $existingRemote ?: '';

            $question = new Question(
                '    Repository URL: ' . ($defaultRemote ? "[<fg=green>{$defaultRemote}</>] " : ''),
                $defaultRemote
            );
            $gitRemote = $helper->ask($input, $output, $question);

            if (!$gitRemote) {
                $output->writeln('');
                $output->writeln('    <error>A repository URL is required.</error>');
                return Command::FAILURE;
            }

            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Remote: <fg=white>{$gitRemote}</>");
        }

        // Test repository access (always verify, even on re-entry)
        $output->writeln('');
        $output->writeln("    <fg=gray>›</> Testing repository access...");

        // If GitHub App is configured, refresh credentials before testing
        // (tokens expire, so refresh proactively on re-entry)
        if (GitHubApp::isConfigured()) {
            $owner = '';
            if (preg_match('#github\.com[:/]([^/]+)/#', $gitRemote, $m)) {
                $owner = $m[1];
            }
            if ($owner) {
                GitHubApp::refreshGitCredentials($owner);
            }
        }

        $canAccess = $this->testRepoAccess(GitHubApp::resolveUrl($gitRemote));

        if (!$canAccess) {
            $output->writeln("    <fg=red>✗</> Cannot access repository");
            $output->writeln('');

            $gitRemote = $this->flowGitAuth($gitRemote, $input, $output, $helper);
            if (!$gitRemote) {
                return Command::FAILURE;
            }
        } else {
            $output->writeln("    <fg=green>✓</> Repository accessible");
        }

        // Resolve URL for git operations (SSH → HTTPS when GitHub App is configured)
        $gitCloneUrl = GitHubApp::resolveUrl($gitRemote);

        // Fetch protocol.json from the remote repo
        $output->writeln('');
        $output->writeln("    <fg=gray>›</> Fetching project configuration...");

        $protocolData = $this->fetchRemoteProtocolJson($gitCloneUrl);
        $projectName = $protocolData['name'] ?? basename(parse_url($gitRemote, PHP_URL_PATH) ?: $gitRemote, '.git');

        if (!empty($protocolData)) {
            $output->writeln("    <fg=green>✓</> Found protocol.json for <fg=white;options=bold>{$projectName}</>");
        } else {
            $output->writeln("    <fg=yellow>!</> No protocol.json found — using defaults");
            $protocolData = [
                'name' => $projectName,
                'deployment' => [
                    'strategy' => 'release',
                    'pointer' => 'github_variable',
                    'pointer_name' => 'PROTOCOL_ACTIVE_RELEASE',
                ],
            ];
        }

        // Step 2: Releases directory
        $this->writeStep($output, 2, $totalSteps, 'Code Location');

        $storedReleasesDir = $existingData['bluegreen']['releases_dir'] ?? null;
        $defaultReleasesDir = $storedReleasesDir ?: rtrim($repo_dir, '/') . '/' . $projectName . '-releases';

        $output->writeln("    <fg=gray>Each release gets its own directory with a full git clone,</>");
        $output->writeln("    <fg=gray>Docker containers, and config files.</>");
        $output->writeln('');
        $output->writeln("    <fg=gray>Default:</> <fg=white>{$defaultReleasesDir}/</>");
        $output->writeln('');

        $question = new Question(
            "    Releases directory [<fg=green>{$defaultReleasesDir}</>/]: ",
            $defaultReleasesDir
        );
        $releasesDir = $helper->ask($input, $output, $question);
        $releasesDir = rtrim($releasesDir, '/');

        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Releases: <fg=white>{$releasesDir}/</>");

        // Save node config to ~/.protocol/.node/nodes/
        $nodeData = $protocolData;
        $nodeData['name'] = $projectName;
        $nodeData['node_type'] = 'slave';
        $nodeData['environment'] = $environment;
        // repo_dir is not set — releases_dir is the source of truth for all paths
        $nodeData['git'] = $nodeData['git'] ?? [];
        $nodeData['git']['remote'] = $gitRemote;
        $nodeData['deployment'] = $nodeData['deployment'] ?? [];
        // Merge stored deployment state from NodeConfig (preserves awaiting_release, strategy, branch on re-entry)
        $storedDeployment = $existingData['deployment'] ?? [];
        $nodeData['deployment'] = array_merge($nodeData['deployment'], $storedDeployment);
        $nodeData['deployment']['strategy'] = $nodeData['deployment']['strategy'] ?? 'release';
        $nodeData['deployment']['pointer'] = $nodeData['deployment']['pointer'] ?? 'github_variable';
        $nodeData['deployment']['pointer_name'] = $nodeData['deployment']['pointer_name'] ?? 'PROTOCOL_ACTIVE_RELEASE';
        $nodeData['bluegreen'] = $nodeData['bluegreen'] ?? [];
        $nodeData['bluegreen']['enabled'] = true;
        $nodeData['bluegreen']['git_remote'] = $gitRemote;
        $nodeData['bluegreen']['releases_dir'] = $releasesDir;
        $nodeData['bluegreen']['auto_promote'] = true;

        // Add health check default
        if (!isset($nodeData['bluegreen']['health_checks'])) {
            $nodeData['bluegreen']['health_checks'] = [
                ['type' => 'http', 'path' => '/health', 'expect_status' => 200],
            ];
        }

        NodeConfig::save($projectName, $nodeData);

        // Set the environment globally
        \Gitcd\Helpers\Config::write('env', $environment);

        // Step 3: First release deployment
        $this->writeStep($output, 3, $totalSteps, 'First Deploy');

        // Check if already deployed (idempotent)
        $currentRelease = Json::read('release.current', null, $repo_dir);
        $currentStrategy = $nodeData['deployment']['strategy'] ?? 'release';
        $currentBranch = $nodeData['deployment']['branch'] ?? null;

        $alreadyDeployed = false;
        if ($currentRelease) {
            $releaseDir = rtrim($releasesDir, '/') . '/' . $currentRelease . '/';
            if (is_dir($releaseDir)) {
                $alreadyDeployed = true;
                $output->writeln("    <fg=white;options=bold>Current deployment:</>");
                $output->writeln("    <fg=gray>Strategy:</> <fg=white>release</>");
                $output->writeln("    <fg=gray>Release:</>  <fg=white>{$currentRelease}</>");
                $output->writeln("    <fg=gray>Location:</> <fg=white>{$releaseDir}</>");
            }
        } elseif ($currentStrategy === 'branch' && $currentBranch) {
            $nightlyDir = rtrim($releasesDir, '/') . '/' . $currentBranch;
            if (is_dir($nightlyDir)) {
                $alreadyDeployed = true;
                $awaitingRelease = ($nodeData['deployment']['awaiting_release'] ?? false);

                $output->writeln("    <fg=white;options=bold>Current deployment:</>");
                if ($awaitingRelease) {
                    $output->writeln("    <fg=gray>Strategy:</> <fg=yellow>nightly</> <fg=gray>(waiting for first release tag)</>");
                } else {
                    $output->writeln("    <fg=gray>Strategy:</> <fg=white>nightly (branch tip)</>");
                }
                $output->writeln("    <fg=gray>Branch:</>   <fg=white>{$currentBranch}</>");
                $output->writeln("    <fg=gray>Location:</> <fg=white>{$nightlyDir}</>");

                // If awaiting release, check if tags have appeared
                if ($awaitingRelease) {
                    $output->writeln('');
                    $output->writeln("    <fg=gray>›</> Checking for new release tags...");
                    $tagsCheck = Shell::run("GIT_TERMINAL_PROMPT=0 git ls-remote --tags " . escapeshellarg($gitCloneUrl) . " 2>/dev/null");
                    $hasTags = false;
                    if ($tagsCheck) {
                        foreach (explode("\n", trim($tagsCheck)) as $line) {
                            if (preg_match('#refs/tags/[^{]+$#', trim($line))) {
                                $hasTags = true;
                                break;
                            }
                        }
                    }
                    if ($hasTags) {
                        $output->writeln("    <fg=green>✓</> Release tags found! You can switch to release-based deployment.");
                        // Fall through to the release picker below
                        $alreadyDeployed = false;
                    } else {
                        $output->writeln("    <fg=gray>No release tags yet. Still following branch tip.</>");
                    }
                }
            }
        }

        if ($alreadyDeployed) {
            $output->writeln('');
            $question = new ConfirmationQuestion(
                '    Change deployment? [y/<fg=green>N</>] ', false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('');
                goto completion;
            }
            $output->writeln('');
        }

        $pointerName = $nodeData['deployment']['pointer_name'] ?? 'PROTOCOL_ACTIVE_RELEASE';

        // List available release tags from the remote
        $output->writeln("    <fg=gray>›</> Checking for available releases...");
        $tagsOutput = Shell::run("GIT_TERMINAL_PROMPT=0 git ls-remote --tags " . escapeshellarg($gitCloneUrl) . " 2>/dev/null");

        $tags = [];
        if ($tagsOutput) {
            foreach (explode("\n", trim($tagsOutput)) as $line) {
                if (preg_match('#refs/tags/(.+)$#', trim($line), $m)) {
                    $tag = $m[1];
                    // Skip ^{} dereferenced entries
                    if (!str_ends_with($tag, '^{}')) {
                        $tags[] = $tag;
                    }
                }
            }
            // Sort by version, newest first
            usort($tags, function ($a, $b) {
                return version_compare(
                    ltrim($b, 'v'),
                    ltrim($a, 'v')
                );
            });
        }

        if (empty($tags)) {
            // No releases — offer nightly (branch tip) strategy
            $output->writeln("    <fg=yellow>!</> No release tags found in this repository.");
            $output->writeln('');
            $output->writeln("    <fg=gray>You can start with</> <fg=white>nightly</> <fg=gray>mode, which follows the branch");
            $output->writeln("    tip. Once a release tag is created, Protocol will automatically");
            $output->writeln("    switch to release-based deployments.</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Start with nightly (branch tip) mode? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                // Determine branch
                $branch = 'main';
                $branchOutput = Shell::run("GIT_TERMINAL_PROMPT=0 git ls-remote --symref " . escapeshellarg($gitCloneUrl) . " HEAD 2>/dev/null");
                if ($branchOutput && preg_match('#ref: refs/heads/(\S+)#', $branchOutput, $m)) {
                    $branch = $m[1];
                }

                $output->writeln('');
                $output->writeln("    <fg=gray>›</> Cloning <fg=white>{$branch}</> into releases directory...");

                $nightlyDir = rtrim($releasesDir, '/') . '/' . $branch;
                if (!is_dir($nightlyDir)) {
                    Shell::run("GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($gitCloneUrl) . " " . escapeshellarg($nightlyDir) . " --branch " . escapeshellarg($branch) . " 2>&1");
                } else {
                    Shell::run("GIT_TERMINAL_PROMPT=0 git -C " . escapeshellarg($nightlyDir) . " pull 2>&1");
                }

                // Update node config with nightly strategy (mark as fallback)
                $nodeData['deployment']['strategy'] = 'branch';
                $nodeData['deployment']['branch'] = $branch;
                $nodeData['deployment']['awaiting_release'] = true;
                NodeConfig::save($projectName, $nodeData);
                Json::write('deployment.strategy', 'branch', $repo_dir);
                Json::write('deployment.branch', $branch, $repo_dir);
                Json::save($repo_dir);

                $output->writeln('');
                $output->writeln("    <fg=green>✓</> Nightly deploy: <fg=white>{$branch}</> → <fg=white>{$nightlyDir}</>");
                $output->writeln("    <fg=gray>Will auto-switch to releases when the first tag is created.</>");
            } else {
                $output->writeln('');
                $output->writeln("    <fg=gray>Skipped. Create a release with</> <fg=white>protocol release:create</> <fg=gray>when ready.</>");
            }
        } else {
            // Releases available — let user pick one
            $output->writeln("    <fg=green>✓</> Found " . count($tags) . " release(s)");
            $output->writeln('');

            // Build menu of releases (show up to 10)
            $choices = [];
            foreach (array_slice($tags, 0, 10) as $tag) {
                $choices[$tag] = $tag;
            }

            $output->writeln("    <fg=gray>Choose which release to deploy:</>");
            $output->writeln('');

            $selectedTag = $this->askWithDots($input, $output, $helper, $choices, array_key_first($choices));

            $output->writeln('');
            $output->writeln("    <fg=gray>›</> Cloning release <fg=white>{$selectedTag}</>...");

            $releaseDir = rtrim($releasesDir, '/') . '/' . $selectedTag . '/';
            $cloneUrl = GitHubApp::resolveUrl($gitCloneUrl);
            if (!is_dir($releasesDir)) {
                mkdir($releasesDir, 0755, true);
            }
            $cloneResult = Shell::run(
                "GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($cloneUrl) . " " . escapeshellarg(rtrim($releaseDir, '/')) . " 2>&1",
                $cloneReturnVar
            );
            $cloneSuccess = $cloneReturnVar === 0 && is_dir($releaseDir);
            if ($cloneSuccess) {
                ReleaseBuilder::checkoutVersion($releaseDir, $selectedTag);

                $output->writeln("    <fg=green>✓</> Release <fg=white>{$selectedTag}</> cloned to <fg=white>{$releaseDir}</>");

                // Set as active release and switch to release strategy
                Json::write('release.current', $selectedTag, $repo_dir);
                Json::write('deployment.strategy', 'release', $repo_dir);
                Json::save($repo_dir);

                // Update node config with deployment state
                $nodeData['deployment']['strategy'] = 'release';
                $nodeData['release'] = $nodeData['release'] ?? [];
                $nodeData['release']['current'] = $selectedTag;
                unset($nodeData['deployment']['awaiting_release']);
                unset($nodeData['deployment']['branch']);
                NodeConfig::save($projectName, $nodeData);

                $output->writeln("    <fg=green>✓</> Active release set to <fg=white>{$selectedTag}</>");
            } else {
                $output->writeln("    <fg=red>✗</> Failed to clone release. Check access permissions.");
                $output->writeln("    <fg=gray>You can deploy manually with:</> <fg=white>protocol shadow:build {$selectedTag}</>");
            }
        }

        $output->writeln('');

        // Completion — verify repos exist, then run protocol start
        completion:

        // Verify primary repo is cloned
        $currentStrategy = $nodeData['deployment']['strategy'] ?? 'release';
        $currentBranch = $nodeData['deployment']['branch'] ?? null;
        $currentRelease = $nodeData['release']['current'] ?? null;

        $activeCloneDir = null;
        if ($currentStrategy === 'branch' && $currentBranch) {
            $activeCloneDir = rtrim($releasesDir, '/') . '/' . $currentBranch;
        } elseif ($currentRelease) {
            $activeCloneDir = rtrim($releasesDir, '/') . '/' . $currentRelease;
        }

        if ($activeCloneDir && !is_dir($activeCloneDir)) {
            if (!is_dir($releasesDir)) {
                mkdir($releasesDir, 0755, true);
            }

            $cloneUrl = GitHubApp::resolveUrl($gitCloneUrl);
            $output->writeln("    <fg=gray>›</> Cloning primary repository...");
            if ($currentStrategy === 'branch' && $currentBranch) {
                Shell::run("GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($cloneUrl) . " " . escapeshellarg($activeCloneDir) . " --branch " . escapeshellarg($currentBranch) . " 2>&1");
            } else {
                Shell::run("GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($cloneUrl) . " " . escapeshellarg($activeCloneDir) . " 2>&1");
                ReleaseBuilder::checkoutVersion($activeCloneDir . '/', $currentRelease);
            }
            if (is_dir($activeCloneDir)) {
                $output->writeln("    <fg=green>✓</> Primary repo cloned to <fg=white>{$activeCloneDir}</>");
            } else {
                $output->writeln("    <fg=red>✗</> Failed to clone primary repo");
            }
            $output->writeln('');
        }

        // Verify config repo is cloned
        $configRemote = $nodeData['configuration']['remote'] ?? null;
        if ($configRemote && $releasesDir) {
            $configDirName = $projectName . '-config';
            $configDir = rtrim($releasesDir, '/') . '/' . $configDirName;

            if (!is_dir($configDir)) {
                if (!is_dir($releasesDir)) {
                    mkdir($releasesDir, 0755, true);
                }

                $output->writeln("    <fg=gray>›</> Cloning configuration repository...");
                $configCloneUrl = GitHubApp::resolveUrl($configRemote);
                $cloneResult = Shell::run("GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($configCloneUrl) . " " . escapeshellarg($configDir));

                if (is_dir($configDir)) {
                    // Switch to environment branch if it exists
                    if ($environment) {
                        $currentBranchConfig = Git::branch($configDir);
                        if ($environment !== $currentBranchConfig) {
                            Shell::run("GIT_TERMINAL_PROMPT=0 git -C " . escapeshellarg($configDir) . " checkout " . escapeshellarg($environment) . " 2>/dev/null");
                        }
                    }
                    $output->writeln("    <fg=green>✓</> Config repo cloned to <fg=white>{$configDir}</>");
                } else {
                    $output->writeln("    <fg=red>✗</> Failed to clone config repo");
                    if ($cloneResult) {
                        $output->writeln("    <fg=gray>" . trim($cloneResult) . "</>");
                    }
                }
                $output->writeln('');
            }
        }

        $this->clearAndBanner($output);

        // Check if we have anything deployed to start services for
        $hasDeployment = ($activeCloneDir && is_dir($activeCloneDir));

        if ($hasDeployment) {
            $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
            $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Slave Node Configured!</>                              <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=gray>Starting services...</>                                  <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
            $output->writeln('');

            // Run protocol start — this will also run protocol status at the end
            $app = $this->getApplication();
            $startArgs = new \Symfony\Component\Console\Input\ArrayInput([
                'environment' => $environment,
                'project' => $projectName,
            ]);
            $app->find('start')->run($startArgs, $output);
        } else {
            $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
            $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Slave Node Configured!</>                              <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=yellow>No release deployed yet.</>                              <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=gray>Deploy with: protocol shadow:build <version></>       <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        }

        return Command::SUCCESS;
    }

    /**
     * Test whether the current machine can access a git remote.
     */
    protected function testRepoAccess(string $gitRemote): bool
    {
        $returnVar = 0;
        $result = Shell::run("GIT_TERMINAL_PROMPT=0 git ls-remote " . escapeshellarg($gitRemote) . " HEAD 2>&1", $returnVar);
        // ls-remote exits 0 on success (even for empty repos with no refs).
        // On failure it exits non-zero and/or prints error messages.
        if ($returnVar !== 0) {
            return false;
        }
        if (str_contains($result, 'fatal:') || str_contains($result, 'ERROR') || str_contains($result, 'Permission denied')) {
            return false;
        }
        return true;
    }

    /**
     * Ask the user whether to retry or exit. Returns true to retry.
     */
    protected function askRetryOrExit(
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): bool {
        $output->writeln('');
        $question = new ConfirmationQuestion(
            '    Try again? [<fg=green>Y</>/n] ', true
        );
        return (bool) $helper->ask($input, $output, $question);
    }

    /**
     * Read a private key from the user — accepts file path or pasted content.
     * Returns the PEM contents or null if input was invalid.
     */
    protected function readPrivateKey(
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): ?string {
        $question = new Question('    Private key: ');
        $firstLine = trim($helper->ask($input, $output, $question) ?? '');

        if (empty($firstLine)) {
            $output->writeln("    <fg=yellow>!</> No private key entered.");
            return null;
        }

        $pemContents = null;

        if (is_file($firstLine)) {
            $pemContents = file_get_contents($firstLine);
        } elseif (str_contains($firstLine, 'BEGIN')) {
            if (str_contains($firstLine, 'END')) {
                // Entire key flattened onto one line
                $pemContents = $this->reconstructPem($firstLine);
            } else {
                // Multi-line paste — read until END marker
                $pemLines = [$firstLine];
                $stdin = fopen('php://stdin', 'r');
                while (($line = fgets($stdin)) !== false) {
                    $line = rtrim($line, "\r\n");
                    $pemLines[] = $line;
                    if (str_contains($line, 'END')) {
                        break;
                    }
                }
                $pemContents = implode("\n", $pemLines) . "\n";
            }
        } else {
            // Maybe a file path with ~ expansion
            $HOME = rtrim(Shell::run('echo $HOME'));
            if (str_starts_with($firstLine, '~/')) {
                $firstLine = $HOME . substr($firstLine, 1);
            }
            if (is_file($firstLine)) {
                $pemContents = file_get_contents($firstLine);
            } else {
                $output->writeln("    <fg=yellow>!</> Not a valid private key or file path.");
                return null;
            }
        }

        if (!$pemContents || (!str_contains($pemContents, 'BEGIN RSA PRIVATE KEY') && !str_contains($pemContents, 'BEGIN PRIVATE KEY'))) {
            $output->writeln("    <fg=yellow>!</> Invalid private key format.");
            return null;
        }

        return $pemContents;
    }

    /**
     * Reconstruct a proper PEM from a flattened single-line paste.
     *
     * When users paste a PEM key, terminals often flatten it to one line.
     * This extracts the base64 content and reformats it with 64-char lines.
     */
    protected function reconstructPem(string $flattened): string
    {
        if (preg_match('/(-{5}BEGIN [A-Z ]+KEY-{5})(.+?)(-{5}END [A-Z ]+KEY-{5})/', $flattened, $m)) {
            $header = $m[1];
            $base64 = trim($m[2]);
            $footer = $m[3];
            $formatted = chunk_split($base64, 64, "\n");
            return "{$header}\n{$formatted}{$footer}\n";
        }
        return $flattened;
    }

    /**
     * Guide the user through setting up GitHub authentication when repo access fails.
     *
     * Uses a GitHub App owned by the organization so that access survives
     * team member departures.
     *
     * @return string|null The original git remote URL (credentials are set up separately), or null on failure
     */
    protected function flowGitAuth(
        string $gitRemote,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): ?string {
        // Extract org/repo from the URL
        $owner = '';
        $repo = '';
        $isGitHub = false;
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+?)(?:\.git)?$#', $gitRemote, $matches)) {
            $owner = $matches[1];
            $repo = $matches[2];
            $isGitHub = true;
        }

        if (!$isGitHub) {
            $output->writeln("    <fg=yellow>!</> This server cannot access the repository.");
            $output->writeln("    <fg=gray>This doesn't appear to be a GitHub URL.</>");
            $output->writeln("    <fg=gray>You can generate an SSH key with:</> <fg=white>protocol key:generate</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Try again after setting up SSH? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                // Re-test access — user may have configured SSH externally
                if ($this->testRepoAccess($gitRemote)) {
                    $output->writeln("    <fg=green>✓</> Repository accessible now");
                    return $gitRemote;
                }
                $output->writeln("    <fg=red>✗</> Still cannot access repository.");
            }
            $output->writeln('');
            $output->writeln("    <fg=gray>Run</> <fg=white>protocol init</> <fg=gray>again after configuring access.</>");
            $output->writeln('');
            return null;
        }

        $output->writeln("    <fg=white;options=bold>GitHub Authentication Required</>");
        $output->writeln('');
        $output->writeln("    <fg=gray>This server needs read access to</> <fg=white>{$owner}/{$repo}</>");
        $output->writeln('');

        // Check if a GitHub App is already configured
        if (GitHubApp::isConfigured()) {
            $output->writeln("    <fg=gray>›</> Found existing GitHub App credentials, refreshing token...");
            if (GitHubApp::refreshGitCredentials($owner)) {
                $output->writeln("    <fg=green>✓</> Git credentials refreshed from GitHub App");
                $output->writeln('');
                return $gitRemote;
            }
            $output->writeln("    <fg=yellow>!</> Existing credentials failed — setting up a new app");
            $output->writeln('');
        }

        // Outer retry loop — user controls when to give up
        while (true) {
            $appUrl = "https://github.com/organizations/{$owner}/settings/apps/new";

            $output->writeln("    <fg=yellow;options=bold>Create a GitHub App</> for this organization.");
            $output->writeln("    The app belongs to the org — not any individual — so access");
            $output->writeln("    won't break when someone leaves the team.");
            $output->writeln('');
            $output->writeln("    <fg=cyan;options=bold>{$appUrl}</>");
            $output->writeln('');
            $output->writeln('    ┌──────────────────────────────────┬──────────────────────────────────────────┐');
            $output->writeln('    │ <fg=white;options=bold>Field</>                            │ <fg=white;options=bold>Value</>                                    │');
            $output->writeln('    ├──────────────────────────────────┼──────────────────────────────────────────┤');
            $output->writeln('    │ GitHub App name                  │ <fg=green>Protocol Deploy App</>                    │');
            $output->writeln('    │ Homepage URL                     │ <fg=green>https://merchantprotocol.com</>            │');
            $output->writeln('    │ Description                      │ <fg=green>Private internal app for production</>     │');
            $output->writeln('    │                                  │ <fg=green>server repo pulls</>                       │');
            $output->writeln('    ├──────────────────────────────────┼──────────────────────────────────────────┤');
            $output->writeln('    │ Webhook → Active                 │ <fg=yellow>☐  Unchecked</>                            │');
            $output->writeln('    ├──────────────────────────────────┼──────────────────────────────────────────┤');
            $output->writeln('    │ Callback URL                     │ <fg=gray>Leave blank</>                              │');
            $output->writeln('    │ Setup URL                        │ <fg=gray>Leave blank</>                              │');
            $output->writeln('    │ Webhook URL                      │ <fg=gray>Leave blank</>                              │');
            $output->writeln('    │ Webhook secret                   │ <fg=gray>Leave blank</>                              │');
            $output->writeln('    ├──────────────────────────────────┼──────────────────────────────────────────┤');
            $output->writeln('    │ <fg=white;options=bold>Repository Permissions</>             │                                          │');
            $output->writeln('    │   Contents                       │ <fg=green>Read-only</>                               │');
            $output->writeln('    │   Metadata                       │ <fg=green>Read-only</>                               │');
            $output->writeln('    │   Variables                      │ <fg=green>Read-only</>                               │');
            $output->writeln('    │   <fg=gray>Everything else</>                 │ <fg=gray>No access</>                               │');
            $output->writeln('    ├──────────────────────────────────┼──────────────────────────────────────────┤');
            $output->writeln('    │ Where can this app be installed? │ <fg=green>Only on this account</>                    │');
            $output->writeln('    └──────────────────────────────────┴──────────────────────────────────────────┘');
            $output->writeln('');
            $output->writeln("    <fg=white>After creating the app:</>");
            $output->writeln("    <fg=yellow>1.</> Click <fg=white>Generate a private key</> — a .pem file will download");
            $output->writeln("    <fg=yellow>2.</> Install the app: <fg=white>https://github.com/organizations/{$owner}/settings/apps</>");
            $output->writeln("       → your app → <fg=white>Install</> → select <fg=white>{$repo}</>");
            $output->writeln("    <fg=yellow>3.</> Come back here and paste the App ID and private key");
            $output->writeln('');

            // ── Collect App ID ──
            $question = new Question('    App ID: ');
            $appId = trim($helper->ask($input, $output, $question) ?? '');

            if (empty($appId)) {
                $output->writeln("    <fg=yellow>!</> No App ID entered.");
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }

            // ── Collect private key ──
            $output->writeln('');
            $output->writeln("    <fg=gray>Paste the private key or enter the path to the .pem file.</>");
            $output->writeln("    <fg=gray>If pasting, just paste — we'll read until the END marker.</>");
            $output->writeln('');

            $pemContents = $this->readPrivateKey($input, $output, $helper);

            if (!$pemContents) {
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }

            // ── Test JWT ──
            $output->writeln('');
            $output->writeln("    <fg=gray>›</> Testing private key...");

            $jwt = GitHubApp::generateJwt($appId, $pemContents);
            if (!$jwt) {
                $output->writeln("    <fg=red>✗</> Private key failed to generate a valid JWT.");
                $output->writeln("    <fg=gray>This usually means the key doesn't match the App ID.</>");
                $output->writeln('');
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }
            $output->writeln("    <fg=green>✓</> Private key verified");

            // Save credentials immediately — if init is interrupted after this
            // point, the next run will find them via isConfigured() and skip
            // the setup wizard entirely.
            GitHubApp::saveCredentials($appId, $pemContents, $owner);
            $output->writeln("    <fg=green>✓</> Credentials saved to <fg=white>" . GitHubApp::credentialsPath() . "</>");

            // ── Find installation — wait for user to install if needed ──
            $installationId = null;
            while (!$installationId) {
                $output->writeln("    <fg=gray>›</> Looking for app installation on <fg=white>{$owner}</>...");

                // JWT expires after 10 min, regenerate each attempt
                $jwt = GitHubApp::generateJwt($appId, $pemContents);
                $installationId = $jwt ? GitHubApp::getInstallationId($jwt, $owner) : null;

                if (!$installationId) {
                    $output->writeln("    <fg=yellow>!</> App not yet installed on <fg=white>{$owner}</>");
                    $output->writeln('');
                    $output->writeln("    <fg=gray>Install it here:</>");
                    $output->writeln("    <fg=cyan>https://github.com/organizations/{$owner}/settings/apps</>");
                    $output->writeln("    <fg=gray>→ your app → Install → select</> <fg=white>{$owner}</>");
                    $output->writeln('');

                    $question = new ConfirmationQuestion(
                        '    Retry after installing? [<fg=green>Y</>/n] ', true
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        break; // break inner loop, will hit askRetryOrExit below
                    }
                    $output->writeln('');
                }
            }

            if (!$installationId) {
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }
            $output->writeln("    <fg=green>✓</> Found installation (ID: {$installationId})");

            // ── Generate installation token ──
            $output->writeln("    <fg=gray>›</> Generating access token...");

            $token = GitHubApp::generateInstallationToken($jwt, $installationId);
            if (!$token) {
                $output->writeln("    <fg=red>✗</> Failed to generate installation token.");
                $output->writeln("    <fg=gray>The app may not have the correct permissions.</>");
                $output->writeln('');
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }

            // ── Test repo access ──
            $httpsUrl = "https://x-access-token:{$token}@github.com/{$owner}/{$repo}.git";
            if (!$this->testRepoAccess($httpsUrl)) {
                $output->writeln("    <fg=red>✗</> Token doesn't have access to <fg=white>{$repo}</>");
                $output->writeln("    <fg=gray>Make sure the app is installed with access to this specific repository.</>");
                $output->writeln('');
                if ($this->askRetryOrExit($input, $output, $helper)) {
                    continue;
                }
                return null;
            }

            $output->writeln("    <fg=green>✓</> Access verified — read access to <fg=white>{$repo}</> confirmed");

            // Write git credentials for clone/fetch operations
            GitHubApp::writeGitCredentials($token);
            $output->writeln('');

            return $gitRemote;
        } // end while(true)
    }

    /**
     * Fetch protocol.json from a remote git repository.
     *
     * Tries GitHub API first (for github.com repos), falls back to
     * git archive for SSH remotes.
     */
    protected function fetchRemoteProtocolJson(string $gitRemote): array
    {
        // Try GitHub API if it's a GitHub URL
        if (preg_match('#github\.com[:/]([^/]+)/([^/.]+)#', $gitRemote, $matches)) {
            $owner = $matches[1];
            $repo = $matches[2];

            $token = GitHubApp::getAccessToken();
            if ($token) {
                $apiResult = Shell::run(
                    "curl -s"
                    . " -H " . escapeshellarg("Authorization: token {$token}")
                    . " -H 'Accept: application/vnd.github+json'"
                    . " " . escapeshellarg("https://api.github.com/repos/{$owner}/{$repo}/contents/protocol.json")
                    . " 2>/dev/null"
                );
                if ($apiResult) {
                    $apiData = json_decode($apiResult, true);
                    $content = $apiData['content'] ?? null;
                    if ($content) {
                        $decoded = base64_decode($content);
                        if ($decoded) {
                            $data = json_decode($decoded, true);
                            if (is_array($data)) {
                                return $data;
                            }
                        }
                    }
                }
            }
        }

        // Fall back to git archive (works with SSH remotes)
        $tmpDir = sys_get_temp_dir() . '/protocol-fetch-' . uniqid();
        mkdir($tmpDir, 0700);
        $result = Shell::run("GIT_TERMINAL_PROMPT=0 git archive --remote=" . escapeshellarg($gitRemote) . " HEAD protocol.json 2>/dev/null | tar -xO -C " . escapeshellarg($tmpDir) . " 2>/dev/null");
        if ($result && trim($result)) {
            $data = json_decode(trim($result), true);
            @rmdir($tmpDir);
            if (is_array($data)) {
                return $data;
            }
        }
        @rmdir($tmpDir);

        // Last resort: shallow clone
        $tmpClone = sys_get_temp_dir() . '/protocol-clone-' . uniqid();
        Shell::run("GIT_TERMINAL_PROMPT=0 git clone --depth 1 " . escapeshellarg($gitRemote) . " " . escapeshellarg($tmpClone) . " 2>/dev/null");
        $protocolFile = $tmpClone . '/protocol.json';
        if (is_file($protocolFile)) {
            $data = json_decode(file_get_contents($protocolFile), true);
            Shell::run("rm -rf " . escapeshellarg($tmpClone));
            if (is_array($data)) {
                return $data;
            }
        }
        Shell::run("rm -rf " . escapeshellarg($tmpClone));

        return [];
    }

    /**
     * Ask the user which environment this node should be.
     *
     * Lists branches from the config repo (filtering out local/localhost dev branches),
     * plus standard options (production, staging, custom).
     */
    protected function askEnvironment(
        string $gitRemote,
        array $protocolData,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): string {
        $options = [];
        $remoteBranches = [];

        // Try to list branches from the config repo
        $configRemote = $protocolData['configuration']['remote'] ?? null;
        if ($configRemote) {
            $output->writeln("    <fg=gray>›</> Checking config repo for available environments...");
            $branchOutput = Shell::run("GIT_TERMINAL_PROMPT=0 git ls-remote --heads " . escapeshellarg($configRemote) . " 2>/dev/null");

            if ($branchOutput) {
                $lines = explode("\n", trim($branchOutput));
                foreach ($lines as $line) {
                    if (preg_match('#refs/heads/(.+)$#', trim($line), $m)) {
                        $branch = $m[1];
                        // Filter out local dev branches
                        if (preg_match('/^(local|localhost)/i', $branch)) {
                            continue;
                        }
                        $remoteBranches[] = $branch;
                    }
                }
            }
            $output->writeln('');
        }

        // Build the menu: remote branches first, then standard options
        if (!empty($remoteBranches)) {
            foreach ($remoteBranches as $branch) {
                $label = $branch;
                if ($branch === 'production') {
                    $label = 'production — live environment';
                } elseif ($branch === 'staging') {
                    $label = 'staging — pre-production testing';
                } elseif ($branch === 'main' || $branch === 'master') {
                    $label = $branch . ' — default branch';
                }
                $options[$branch] = $label;
            }
        } else {
            // No config repo or no branches found — offer standard options
            $options['production'] = 'production — live environment';
            $options['staging'] = 'staging — pre-production testing';
        }

        $options['custom'] = 'Custom environment name';

        // Default to production if available, otherwise first option
        $defaultKey = in_array('production', array_keys($options)) ? 'production' : array_keys($options)[0];

        $selectedKey = $this->askWithDots($input, $output, $helper, $options, $defaultKey);

        if ($selectedKey === 'custom') {
            $question = new Question('    Environment name: ');
            $environment = $helper->ask($input, $output, $question);
            if (!$environment) {
                $environment = 'production';
            }
            return $environment;
        }

        return $selectedKey;
    }

    // ─── Flow: Fix / Migrate ───────────────────────────────────

    protected function flowFixMigrate(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $this->clearAndBanner($output);

        $currentVersion = (int) Json::read('protocol_version', 0, $repo_dir);
        $targetVersion = self::SCHEMA_VERSION;

        if ($currentVersion >= $targetVersion) {
            $output->writeln("    <fg=green>✓</> protocol.json is up to date <fg=gray>(v{$currentVersion})</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>Running integrity checks...</>");
            $output->writeln('');
        } else {
            $output->writeln("    <fg=yellow>!</> protocol.json version: <fg=white>v{$currentVersion}</> → <fg=green>v{$targetVersion}</>");
            $output->writeln('');
        }

        $fixes = [];

        // Detect project type
        $projectType = Json::read('project_type', 'php82', $repo_dir);
        $initializers = $this->getAvailableInitializers();
        $initializer = $initializers[$projectType] ?? reset($initializers);

        // Run migrations for each version the project is behind
        if ($currentVersion < 1) {
            $migrated = $this->migrateToV1($repo_dir, $input, $output, $helper, $initializer);
            $fixes = array_merge($fixes, $migrated);
        }

        // Always run integrity checks regardless of version
        $integrity = $this->runIntegrityChecks($repo_dir, $output, $initializer);
        $fixes = array_merge($fixes, $integrity);

        // Stamp the current version
        Json::write('protocol_version', $targetVersion, $repo_dir);
        Json::save($repo_dir);

        // Summary
        $output->writeln('');
        if (!empty($fixes)) {
            $output->writeln("    <fg=green;options=bold>Done!</> Applied " . count($fixes) . " fix(es):");
            foreach ($fixes as $fix) {
                $output->writeln("      <fg=green>✓</> {$fix}");
            }
        } else {
            $output->writeln("    <fg=green;options=bold>Everything looks good!</> No fixes needed.");
        }

        $this->commitProtocolFiles($output, $repo_dir);

        $output->writeln('');
        return Command::SUCCESS;
    }

    /**
     * Migration: v0 → v1
     * - Convert image: to build: context: with .git suffix
     * - Add missing protocol.json fields (project_type, name, deployment.strategy)
     * - Create scaffold directories (nginx.d, cron.d, supervisor.d)
     */
    protected function migrateToV1(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        $initializer
    ): array {
        $fixes = [];
        $output->writeln("    <fg=cyan>── Migrating to v1 ──</>");
        $output->writeln('');

        // Fix docker-compose.yml
        $dockerComposePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
        if (file_exists($dockerComposePath)) {
            $content = file_get_contents($dockerComposePath);
            $reasons = [];

            $customImage = Json::read('docker.image', null, $repo_dir);
            if (!$customImage && preg_match('/^\s+image:/m', $content)) {
                $reasons[] = 'convert image: to build: context:';
            }
            if (preg_match('/context:\s+(https:\/\/github\.com\/[^\s]+)/', $content, $m)) {
                if (!str_ends_with(trim($m[1]), '.git')) {
                    $reasons[] = 'add .git suffix to build context URL';
                }
            }

            if (!empty($reasons)) {
                foreach ($reasons as $reason) {
                    $output->writeln("      <fg=yellow>!</> {$reason}");
                }
                $output->writeln('');
                $question = new ConfirmationQuestion(
                    '    Regenerate docker-compose.yml? [<fg=green>Y</>/n] ', true
                );
                if ($helper->ask($input, $output, $question)) {
                    $initializer->initialize($repo_dir, $input, $output, $helper);
                    $fixes[] = 'Regenerated docker-compose.yml (v1 format)';
                }
            }
        } else {
            $output->writeln("    <fg=yellow>!</> No docker-compose.yml found");
            $output->writeln('');
            $question = new ConfirmationQuestion(
                '    Generate docker-compose.yml? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                $initializer->initialize($repo_dir, $input, $output, $helper);
                $fixes[] = 'Created docker-compose.yml';
            }
        }

        // Ensure protocol.json has required v1 fields
        $updated = false;
        if (!Json::read('project_type', null, $repo_dir)) {
            Json::write('project_type', $this->getInitializerKey($initializer), $repo_dir);
            $updated = true;
        }
        if (!Json::read('name', null, $repo_dir)) {
            Json::write('name', basename($repo_dir), $repo_dir);
            $updated = true;
        }
        if (!Json::read('deployment.strategy', null, $repo_dir)) {
            Json::write('deployment.strategy', 'release', $repo_dir);
            $updated = true;
        }
        $remoteurl = Git::RemoteUrl($repo_dir);
        if ($remoteurl && $remoteurl !== Json::read('git.remote', null, $repo_dir)) {
            Json::write('git.remote', $remoteurl, $repo_dir);
            $updated = true;
        }
        if ($updated) {
            Json::save($repo_dir);
            $fixes[] = 'Added missing protocol.json fields';
            $output->writeln("    <fg=green>✓</> Updated protocol.json with v1 fields");
        }

        $output->writeln('');
        return $fixes;
    }

    /**
     * Integrity checks run every time, regardless of version.
     * Catches drift like missing directories or .gitignore entries.
     */
    protected function runIntegrityChecks(string $repo_dir, OutputInterface $output, $initializer): array
    {
        $fixes = [];

        // Ensure .gitignore has protocol.lock
        Git::addIgnore('protocol.lock', $repo_dir);

        // Create missing scaffold directories
        foreach (['nginx.d', 'cron.d', 'supervisor.d'] as $dir) {
            if (!is_dir(rtrim($repo_dir, '/') . '/' . $dir)) {
                $initializer->createOverrideDirectories($repo_dir, $output);
                $fixes[] = 'Created missing scaffold directories';
                break;
            }
        }

        if (empty($fixes)) {
            $output->writeln("    <fg=green>✓</> Scaffold directories present");
        }

        return $fixes;
    }

    // ─── Shared steps ────────────────────────────────────────────

    protected function selectProjectType(InputInterface $input, OutputInterface $output, $helper)
    {
        $initializers = $this->getAvailableInitializers();
        $choices = [];
        foreach ($initializers as $key => $initializer) {
            $choices[$key] = $initializer->getName() . ' — ' . $initializer->getDescription();
        }
        $choices['custom'] = 'Custom Docker image';

        $selectedKey = $this->askWithDots($input, $output, $helper, $choices, 'php82');

        if ($selectedKey === 'custom') {
            $question = new Question('    Docker image (e.g. myorg/myimage:latest): ');
            $customImage = $helper->ask($input, $output, $question);
            // Use the default initializer but override the image
            $selectedInitializer = reset($initializers);
            $selectedInitializer->setCustomImage($customImage);
            $output->writeln("    <fg=green>✓</> Selected: <fg=white;options=bold>{$customImage}</>");
        } else {
            $selectedInitializer = $initializers[$selectedKey];
            $output->writeln("    <fg=green>✓</> Selected: <fg=white;options=bold>{$selectedInitializer->getName()}</>");
        }

        return $selectedInitializer;
    }

    protected function getInitializerKey($initializer): string
    {
        $initializers = $this->getAvailableInitializers();
        foreach ($initializers as $key => $init) {
            if (get_class($init) === get_class($initializer)) {
                return $key;
            }
        }
        return 'php82';
    }

    protected function configureDeploymentStrategy(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): void {
        $currentStrategy = Json::read('deployment.strategy', null, $repo_dir);

        $strategyKey = $this->askWithDots($input, $output, $helper, [
            'release' => 'Release-based — rollback, audit trail, multi-node',
            'branch'  => 'Branch-based — simple, tracks branch tip (legacy)',
        ], $currentStrategy ?: 'release');

        Json::write('deployment.strategy', $strategyKey, $repo_dir);

        if ($strategyKey === 'release') {
            Json::write('deployment.pointer', 'github_variable', $repo_dir);
            Json::write('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
        }

        Json::save($repo_dir);
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Strategy: <fg=white;options=bold>{$strategyKey}</>");
    }

    protected function configureSecrets(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): void {
        if (Secrets::hasKey()) {
            $output->writeln("    <fg=green>✓</> Encryption key present <fg=gray>— " . Secrets::keyPath() . "</>");
            return;
        }

        $output->writeln("    <fg=gray>Encrypt your .env files with AES-256-GCM.</>");
        $output->writeln("    <fg=gray>Keys stay local, secrets travel encrypted in git.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Set up encrypted secrets? <fg=gray>(recommended for production)</> [y/<fg=green>N</>] ', false
        );
        if ($helper->ask($input, $output, $question)) {
            $command = $this->getApplication()->find('secrets:setup');
            $command->run(new ArrayInput([]), $output);
            Json::write('deployment.secrets', 'encrypted', $repo_dir);
            Json::save($repo_dir);
        } else {
            $this->writeInfo($output, '<fg=gray>Skipped. Run</> <fg=cyan>protocol secrets:setup</> <fg=gray>later.</>');
        }
    }

    protected function configureConfigRepo(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        $initializer
    ): void {
        if ($input->getOption('with-config')) {
            $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
            return;
        }

        $output->writeln("    <fg=gray>Store .env, nginx, and cron configs in a separate repo.</>");
        $output->writeln("    <fg=gray>Each branch = one environment. Symlinked into your app.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Initialize a configuration repository? [y/<fg=green>N</>] ', false
        );
        if ($helper->ask($input, $output, $question)) {
            $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
        } else {
            $this->writeInfo($output, '<fg=gray>Skipped. Run</> <fg=cyan>protocol config:init</> <fg=gray>later.</>');
        }
    }

    protected function writeCompletion(OutputInterface $output, string $repo_dir): void
    {
        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        // Auto-commit protocol files
        $this->commitProtocolFiles($output, $repo_dir);

        $this->clearAndBanner($output);
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Setup Complete!</>                                    <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>Next steps:</>                                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>1.</> <fg=white>protocol start</>           <fg=gray>Start this node</>       <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>2.</> <fg=white>protocol status</>          <fg=gray>Check node health</>     <fg=cyan>│</>');

        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function commitProtocolFiles(OutputInterface $output, string $repo_dir): void
    {
        if (!Git::isInitializedRepo($repo_dir)) {
            return;
        }

        $filesToAdd = [
            'protocol.json',
            'docker-compose.yml',
            '.gitignore',
            'nginx.d',
            'cron.d',
            'supervisor.d',
        ];

        $added = [];
        foreach ($filesToAdd as $file) {
            $fullPath = rtrim($repo_dir, '/') . '/' . $file;
            if (file_exists($fullPath) || is_dir($fullPath)) {
                Shell::run("git -C " . escapeshellarg($repo_dir) . " add " . escapeshellarg($file) . " 2>/dev/null");
                $added[] = $file;
            }
        }

        if (empty($added)) {
            return;
        }

        // Check if there's anything staged
        $status = Shell::run("git -C " . escapeshellarg($repo_dir) . " diff --cached --name-only 2>/dev/null");
        if (empty(trim($status))) {
            $output->writeln('');
            $output->writeln("    <fg=gray>›</> No changes to commit");
            return;
        }

        Shell::run("git -C " . escapeshellarg($repo_dir) . " commit -m 'protocol init' 2>/dev/null");
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Committed: <fg=white>" . implode(', ', $added) . "</>");
    }
}
