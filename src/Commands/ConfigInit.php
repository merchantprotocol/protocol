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
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;
use Gitcd\Utils\Json;
use Gitcd\Commands\Init\DotMenuTrait;

Class ConfigInit extends Command {

    use DotMenuTrait;

    protected static $defaultName = 'config:init';
    protected static $defaultDescription = 'Initialize the configuration repository';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Walk through setting up a configuration repository for your project.

            The config repo stores environment-specific files (.env, nginx configs,
            cron jobs) in a separate git repository. Each branch represents an
            environment (localhost, staging, production).

            This wizard will:
            1. Create or connect the config repository
            2. Set up your environment branch
            3. Optionally encrypt your secrets with AES-256-GCM

            HELP)
        ;
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    // ─── Display helpers ─────────────────────────────────────────

    protected function clearAndBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>PROTOCOL</> <fg=gray>·</> <fg=yellow>Configuration Setup</>                      <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Environment-specific configs, encrypted secrets</>      <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        $this->clearAndBanner($output);
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);
        $helper = $this->getHelper('question');

        if (!file_exists("{$repo_dir}/protocol.json")) {
            $output->writeln('<error>Run `protocol init` first to initialize the project.</error>');
            return Command::FAILURE;
        }

        $configrepo = Config::repo($repo_dir);
        $basedir = dirname($repo_dir) . DIRECTORY_SEPARATOR;
        $foldername = basename($repo_dir) . '-config';
        $preExistingRemoteUrl = Json::read('configuration.remote', false, $repo_dir);
        $hasExistingConfig = is_dir($configrepo) && is_dir($configrepo . '.git');

        // ── If config repo exists, show action menu ──────────────
        if ($hasExistingConfig) {
            return $this->flowExistingConfig($input, $output, $repo_dir, $configrepo, $foldername);
        }

        // ── No config repo — run first-time setup wizard ─────────
        if ($preExistingRemoteUrl) {
            return $this->flowCloneRemote($input, $output, $repo_dir, $configrepo, $basedir, $foldername, $preExistingRemoteUrl);
        }

        return $this->flowNewConfig($input, $output, $repo_dir, $configrepo, $basedir, $foldername);
    }

    // ─── Existing config repo: action menu ──────────────────────

    protected function flowExistingConfig(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $foldername
    ): int {
        $helper = $this->getHelper('question');

        // Detect current state to recommend an action
        $hasKey = Secrets::hasKey();
        $branch = Git::branch($configrepo);

        $encFiles = glob(rtrim($configrepo, '/') . '/*.enc');
        $envFiles = glob(rtrim($configrepo, '/') . '/.env*');
        $hasEncryptedFiles = !empty($encFiles);
        $unencryptedEnvFiles = [];
        foreach ($envFiles as $f) {
            if (!str_ends_with($f, '.enc') && is_file($f)) {
                $unencryptedEnvFiles[] = basename($f);
            }
        }
        $hasUnencryptedEnvFiles = !empty($unencryptedEnvFiles);

        // Also check subdirectories for .enc and unencrypted files
        $encFilesDeep = glob(rtrim($configrepo, '/') . '/**/*.enc');
        $hasEncryptedFiles = $hasEncryptedFiles || !empty($encFilesDeep);

        // Build the menu
        $this->clearAndBanner($output);

        $output->writeln("    <fg=gray>Config repo:</> <fg=white>{$foldername}/</>");
        $output->writeln("    <fg=gray>Branch:</>      <fg=cyan>{$branch}</>");
        $output->writeln("    <fg=gray>Encryption:</> " . ($hasKey ? '<fg=green>key present</>' : '<fg=yellow>no key</>'));
        if ($hasEncryptedFiles) {
            $output->writeln("    <fg=gray>Encrypted files:</> <fg=green>yes</>");
        }
        if ($hasUnencryptedEnvFiles) {
            $output->writeln("    <fg=gray>Unencrypted .env:</> <fg=yellow>" . implode(', ', $unencryptedEnvFiles) . "</>");
        }
        $output->writeln('');

        // Determine recommended action
        $recommended = 'cancel';
        if ($hasUnencryptedEnvFiles) {
            $recommended = 'encrypt';
        } elseif ($hasEncryptedFiles && !$hasKey) {
            $recommended = 'decrypt';
        }

        // Build dot-menu options
        $options = [];
        $options['encrypt'] = 'Encrypt secrets' . (!$hasKey ? ' (will generate key)' : '');
        $options['decrypt'] = 'Decrypt secrets' . (!$hasKey ? ' (requires key)' : '');
        $options['reinit']  = 'Re-initialize config repo (wipes existing)';
        $options['cancel']  = 'Cancel';

        $output->writeln("    <fg=gray>What would you like to do?</>");
        $output->writeln('');

        $action = $this->askWithDots($input, $output, $helper, $options, $recommended);

        switch ($action) {
            case 'encrypt':
                return $this->flowEncrypt($input, $output, $repo_dir, $configrepo, $foldername);

            case 'decrypt':
                return $this->flowDecrypt($input, $output, $repo_dir, $configrepo, $foldername);

            case 'reinit':
                return $this->flowReinitialize($input, $output, $repo_dir, $configrepo, $foldername);

            case 'cancel':
            default:
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                return Command::SUCCESS;
        }
    }

    // ─── Encrypt flow ────────────────────────────────────────────

    protected function flowEncrypt(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $foldername
    ): int {
        $helper = $this->getHelper('question');
        $this->writeStep($output, 1, 2, 'Encryption Key');

        if (Secrets::hasKey()) {
            $output->writeln("    <fg=green>✓</> Encryption key already present");
            $output->writeln("    <fg=gray>Key:</> <fg=white>" . Secrets::keyPath() . "</>");
        } else {
            $output->writeln("    <fg=gray>An AES-256-GCM encryption key will be generated and stored at:</>");
            $output->writeln("    <fg=white>" . Secrets::keyPath() . "</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>This key never leaves your machine. Only encrypted data goes into git.</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Generate encryption key? [<fg=green>Y</>/n] ', true
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('');
                $output->writeln('    <fg=gray>Cancelled.</>');
                return Command::SUCCESS;
            }

            $hexKey = Secrets::generateKey();
            Secrets::storeKey($hexKey);
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Key generated");
            $output->writeln('');
            $output->writeln("    <fg=yellow;options=bold>IMPORTANT:</> Copy this key to every node that needs to decrypt:");
            $output->writeln('');
            $output->writeln("    <fg=white;options=bold>{$hexKey}</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>On each node, run:</>");
            $output->writeln("    <fg=cyan>protocol secrets:setup {$hexKey}</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Continue to encrypt files? [<fg=green>Y</>/n] ', true
            );
            if (!$helper->ask($input, $output, $question)) {
                Json::write('deployment.secrets', 'encrypted', $repo_dir);
                Json::save($repo_dir);
                return Command::SUCCESS;
            }
        }

        // Step 2: Encrypt files
        $this->writeStep($output, 2, 2, 'Encrypt Files');

        $envFiles = glob(rtrim($configrepo, '/') . '/.env*');
        $unencrypted = [];
        foreach ($envFiles as $f) {
            if (!str_ends_with($f, '.enc') && is_file($f)) {
                $unencrypted[] = basename($f);
            }
        }

        if (empty($unencrypted)) {
            $output->writeln("    <fg=gray>No unencrypted .env files found in the config repo.</>");
            $output->writeln("    <fg=gray>All secrets appear to be encrypted already.</>");
            $output->writeln('');
            Json::write('deployment.secrets', 'encrypted', $repo_dir);
            Json::save($repo_dir);
            $this->showCompletion($output, $foldername, Config::read('env', 'unknown'));
            return Command::SUCCESS;
        }

        $output->writeln("    <fg=gray>Found unencrypted files in config repo:</>");
        $output->writeln('');
        foreach ($unencrypted as $name) {
            $output->writeln("    <fg=white>  {$name}</>");
        }
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Encrypt these files now? [<fg=green>Y</>/n] ', true
        );
        if ($helper->ask($input, $output, $question)) {
            $output->writeln('');
            foreach ($unencrypted as $envName) {
                $envPath = rtrim($configrepo, '/') . '/' . $envName;
                $encPath = $envPath . '.enc';
                if (Secrets::encryptFile($envPath, $encPath)) {
                    unlink($envPath);
                    Git::addIgnore($envName, $configrepo);
                    $output->writeln("    <fg=green>✓</> <fg=white>{$envName}</> → <fg=white>{$envName}.enc</>");
                } else {
                    $output->writeln("    <error>  Failed to encrypt {$envName}</error>");
                }
            }
            Shell::run("git -C '$configrepo' add -A");
            Shell::run("git -C '$configrepo' commit -m 'encrypt secrets' 2>/dev/null");

            // Push if remote exists
            $remoteUrl = Git::RemoteUrl($configrepo);
            if ($remoteUrl) {
                $output->writeln('');
                $output->writeln("    <fg=gray>Pushing encrypted files to remote...</>");
                Shell::passthru("git -C '$configrepo' push 2>/dev/null");
                $output->writeln("    <fg=green>✓</> Pushed to remote");
            }
        }

        Json::write('deployment.secrets', 'encrypted', $repo_dir);
        Json::save($repo_dir);

        $this->showCompletion($output, $foldername, Config::read('env', 'unknown'));
        return Command::SUCCESS;
    }

    // ─── Decrypt flow ────────────────────────────────────────────

    protected function flowDecrypt(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $foldername
    ): int {
        $helper = $this->getHelper('question');
        $this->writeStep($output, 1, 2, 'Encryption Key');

        if (Secrets::hasKey()) {
            $output->writeln("    <fg=green>✓</> Encryption key present");
            $output->writeln("    <fg=gray>Key:</> <fg=white>" . Secrets::keyPath() . "</>");
        } else {
            $output->writeln("    <fg=yellow>!</> No encryption key found on this node.");
            $output->writeln('');
            $output->writeln("    <fg=gray>To decrypt, you need the key from the node that encrypted the files.</>");
            $output->writeln("    <fg=gray>Paste the hex key below, or run:</>");
            $output->writeln("    <fg=cyan>protocol secrets:setup <key></>");
            $output->writeln('');

            $question = new Question('    Encryption key: ', false);
            $hexKey = $helper->ask($input, $output, $question);

            if (!$hexKey) {
                $output->writeln('');
                $output->writeln('    <fg=gray>Cancelled. Cannot decrypt without a key.</>');
                return Command::SUCCESS;
            }

            // Validate key format
            $keyBin = hex2bin($hexKey);
            if ($keyBin === false || strlen($keyBin) !== Secrets::KEY_LENGTH) {
                $output->writeln('');
                $output->writeln("    <error>Invalid key. Must be a " . (Secrets::KEY_LENGTH * 2) . "-character hex string.</error>");
                return Command::FAILURE;
            }

            Secrets::storeKey($hexKey);
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Key saved to <fg=white>" . Secrets::keyPath() . "</>");
        }

        // Step 2: Decrypt files
        $this->writeStep($output, 2, 2, 'Decrypt Files');

        $encFiles = glob(rtrim($configrepo, '/') . '/*.enc');
        if (empty($encFiles)) {
            $output->writeln("    <fg=gray>No .enc files found in config repo.</>");
            $this->showCompletion($output, $foldername, Config::read('env', 'unknown'));
            return Command::SUCCESS;
        }

        $output->writeln("    <fg=gray>Encrypted files found:</>");
        $output->writeln('');
        foreach ($encFiles as $f) {
            $output->writeln("    <fg=white>  " . basename($f) . "</>");
        }
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Decrypt these files now? [<fg=green>Y</>/n] ', true
        );
        if ($helper->ask($input, $output, $question)) {
            $output->writeln('');
            foreach ($encFiles as $encPath) {
                $encName = basename($encPath);
                $plainName = preg_replace('/\.enc$/', '', $encName);
                $plainPath = dirname($encPath) . '/' . $plainName;

                $plaintext = Secrets::decryptFile($encPath);
                if ($plaintext === null) {
                    $output->writeln("    <error>  Failed to decrypt {$encName} — wrong key?</error>");
                    continue;
                }

                file_put_contents($plainPath, $plaintext);
                chmod($plainPath, 0600);
                Git::addIgnore($plainName, $configrepo);
                $output->writeln("    <fg=green>✓</> <fg=white>{$encName}</> → <fg=white>{$plainName}</>");
            }
        }

        $this->showCompletion($output, $foldername, Config::read('env', 'unknown'));
        return Command::SUCCESS;
    }

    // ─── Re-initialize flow ──────────────────────────────────────

    protected function flowReinitialize(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $foldername
    ): int {
        $helper = $this->getHelper('question');
        $this->clearAndBanner($output);

        $output->writeln("    <fg=red;options=bold>WARNING:</> This will delete the existing config repo at:");
        $output->writeln("    <fg=white>{$configrepo}</>");
        $output->writeln('');
        $output->writeln("    <fg=gray>All local config files and history will be lost.</>");
        $output->writeln("    <fg=gray>If you have a remote, you can re-clone it afterwards.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Are you sure? Type "yes" to confirm [y/<fg=green>N</>] ', false
        );
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('');
            $output->writeln('    <fg=gray>Cancelled. No changes made.</>');
            return Command::SUCCESS;
        }

        // Wipe the config repo
        Shell::run("rm -rf '$configrepo'");
        Json::write('configuration.local', null, $repo_dir);
        Json::write('configuration.environments', null, $repo_dir);
        Json::save($repo_dir);

        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Config repo removed.");
        $output->writeln('');

        // Now run the first-time setup
        $basedir = dirname($repo_dir) . DIRECTORY_SEPARATOR;
        $preExistingRemoteUrl = Json::read('configuration.remote', false, $repo_dir);

        if ($preExistingRemoteUrl) {
            return $this->flowCloneRemote($input, $output, $repo_dir, $configrepo, $basedir, $foldername, $preExistingRemoteUrl);
        }
        return $this->flowNewConfig($input, $output, $repo_dir, $configrepo, $basedir, $foldername);
    }

    // ─── Clone remote config repo ────────────────────────────────

    protected function flowCloneRemote(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $basedir,
        string $foldername,
        string $remoteUrl
    ): int {
        $helper = $this->getHelper('question');
        $totalSteps = 3;

        // Step 1: Environment
        $environment = $this->stepEnvironment($input, $output, $helper, 1, $totalSteps);

        // Step 2: Clone
        $this->writeStep($output, 2, $totalSteps, 'Clone Config Repository');
        $output->writeln("    <fg=gray>Cloning config repo from remote...</>");
        $output->writeln("    <fg=white>{$remoteUrl}</>");
        $output->writeln('');

        if (!is_dir($configrepo)) {
            Shell::run("mkdir -p '$configrepo'");
        }
        if (!is_dir($basedir . $foldername . DIRECTORY_SEPARATOR . '.git')) {
            $arrInput = new ArrayInput([
                'remote' => $remoteUrl,
                'repo_dir' => $basedir . $foldername,
                '--dir' => $repo_dir
            ]);
            $command = $this->getApplication()->find('git:clone');
            $command->run($arrInput, $output);
        }
        Git::fetch($configrepo);
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Cloned to <fg=white>{$foldername}/</>");

        // Switch to environment branch
        if ($environment !== Git::branch($configrepo)) {
            Shell::run("git -C '$configrepo' checkout -b $environment 2>/dev/null");
            Shell::run("git -C '$configrepo' checkout $environment 2>/dev/null");
            $output->writeln("    <fg=green>✓</> Switched to branch: <fg=white;options=bold>{$environment}</>");
        }

        Json::save($repo_dir);

        // Step 3: Secrets
        $this->stepSecrets($input, $output, $helper, $repo_dir, $configrepo, 3, $totalSteps);

        $this->addDockerComposeVolume($repo_dir, $foldername);
        $this->showCompletion($output, $foldername, $environment);
        return Command::SUCCESS;
    }

    // ─── New config repo setup ───────────────────────────────────

    protected function flowNewConfig(
        InputInterface $input,
        OutputInterface $output,
        string $repo_dir,
        string $configrepo,
        string $basedir,
        string $foldername
    ): int {
        $helper = $this->getHelper('question');
        $totalSteps = 4;

        // Step 1: Environment
        $environment = $this->stepEnvironment($input, $output, $helper, 1, $totalSteps);

        // Step 2: Create repo
        $this->writeStep($output, 2, $totalSteps, 'Create Config Repository');

        $output->writeln("    <fg=gray>A new git repository will be created at:</>");
        $output->writeln("    <fg=white>{$basedir}{$foldername}/</>");
        $output->writeln('');

        if (!is_dir($configrepo)) {
            Shell::run("mkdir -p '$configrepo'");
        }

        if (!is_dir($configrepo . '.git')) {
            if (!Git::initialize($configrepo)) {
                $output->writeln("<error>Unable to create git repo at {$configrepo}</error>");
                return Command::FAILURE;
            }
            Shell::run("git -C '$configrepo' branch -m $environment");
        }

        Json::write('configuration.local', '..' . DIRECTORY_SEPARATOR . $foldername, $repo_dir);

        // Copy template files
        $templatedir = TEMPLATES_DIR . 'configrepo' . DIRECTORY_SEPARATOR;
        if (!file_exists($configrepo . 'README.md')) {
            Shell::run("cp -R '{$templatedir}' '$configrepo'");
            Shell::run("git -C '$configrepo' add -A");
            Shell::run("git -C '$configrepo' commit -m 'initial commit'");
        }
        Json::write('configuration.environments', Git::branches($configrepo), $repo_dir);

        $output->writeln("    <fg=green>✓</> Created config repo");

        // Ask for remote URL
        $output->writeln('');
        $output->writeln("    <fg=gray>Optionally connect a remote so you can share configs across nodes.</>");
        $output->writeln('');
        $question = new Question('    Remote git URL (leave blank to skip): ', false);
        $configRemoteUrl = $helper->ask($input, $output, $question);

        if ($configRemoteUrl) {
            Shell::passthru("git -C '$configrepo' remote add origin '$configRemoteUrl'");
            Json::write('configuration.remote', $configRemoteUrl, $repo_dir);
        }

        Json::save($repo_dir);

        // Step 3: Secrets
        $this->stepSecrets($input, $output, $helper, $repo_dir, $configrepo, 3, $totalSteps);

        // Step 4: Push to remote (if applicable)
        $configRemoteUrl = Git::RemoteUrl($configrepo);
        if ($configRemoteUrl) {
            $this->writeStep($output, 4, $totalSteps, 'Push to Remote');
            $output->writeln("    <fg=gray>Pushing config repo to remote...</>");
            Shell::passthru("git -C '$configrepo' push --all origin");
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Pushed to <fg=white>{$configRemoteUrl}</>");
        }

        $this->addDockerComposeVolume($repo_dir, $foldername);
        $this->showCompletion($output, $foldername, $environment);
        return Command::SUCCESS;
    }

    // ─── Shared step: Environment ────────────────────────────────

    protected function stepEnvironment(
        InputInterface $input,
        OutputInterface $output,
        $helper,
        int $stepNum,
        int $totalSteps
    ): string {
        $this->writeStep($output, $stepNum, $totalSteps, 'Environment');
        $output->writeln("    <fg=gray>Each environment (localhost, staging, production) gets its own</>");
        $output->writeln("    <fg=gray>branch in the config repo. Files on that branch are linked</>");
        $output->writeln("    <fg=gray>into your project when you run protocol start.</>");
        $output->writeln('');

        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment) {
            $defaultEnv = 'localhost';
            $question = new Question("    Environment name [{$defaultEnv}]: ", $defaultEnv);
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        } else {
            $output->writeln("    <fg=green>✓</> Environment: <fg=white;options=bold>{$environment}</>");
        }

        return $environment;
    }

    // ─── Shared step: Secrets & Encryption ───────────────────────

    protected function stepSecrets(
        InputInterface $input,
        OutputInterface $output,
        $helper,
        string $repo_dir,
        string $configrepo,
        int $stepNum,
        int $totalSteps
    ): void {
        $this->writeStep($output, $stepNum, $totalSteps, 'Secrets & Encryption');

        if (Secrets::hasKey()) {
            $output->writeln("    <fg=green>✓</> Encryption key present");
            $output->writeln("    <fg=gray>Key:</> <fg=white>" . Secrets::keyPath() . "</>");
            $output->writeln('');

            $this->offerEncryptFiles($input, $output, $helper, $repo_dir, $configrepo);
        } else {
            $output->writeln("    <fg=gray>Encrypt .env and credential files with AES-256-GCM so they</>");
            $output->writeln("    <fg=gray>can be safely committed to git. The encryption key stays</>");
            $output->writeln("    <fg=gray>on your machine — only encrypted data travels through git.</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Generate an encryption key? [<fg=green>Y</>/n] ', true
            );
            if ($helper->ask($input, $output, $question)) {
                $hexKey = Secrets::generateKey();
                Secrets::storeKey($hexKey);
                $output->writeln('');
                $output->writeln("    <fg=green>✓</> Key generated and saved to <fg=white>" . Secrets::keyPath() . "</>");
                $output->writeln('');
                $output->writeln("    <fg=yellow;options=bold>IMPORTANT:</> Copy this key to every node that needs to decrypt:");
                $output->writeln('');
                $output->writeln("    <fg=white;options=bold>{$hexKey}</>");
                $output->writeln('');
                $output->writeln("    <fg=gray>On each node, run:</>");
                $output->writeln("    <fg=cyan>protocol secrets:setup {$hexKey}</>");
                $output->writeln('');

                Json::write('deployment.secrets', 'encrypted', $repo_dir);
                Json::save($repo_dir);

                $this->offerEncryptFiles($input, $output, $helper, $repo_dir, $configrepo);
            } else {
                $output->writeln('');
                $output->writeln("    <fg=gray>Skipped. Run</> <fg=cyan>protocol secrets:setup</> <fg=gray>later.</>");
            }
        }
    }

    // ─── Offer to encrypt unencrypted .env files ─────────────────

    protected function offerEncryptFiles(
        InputInterface $input,
        OutputInterface $output,
        $helper,
        string $repo_dir,
        string $configrepo
    ): void {
        $envFiles = glob(rtrim($configrepo, '/') . '/.env*');
        $unencrypted = [];
        foreach ($envFiles as $f) {
            if (!str_ends_with($f, '.enc') && is_file($f)) {
                $unencrypted[] = basename($f);
            }
        }

        if (empty($unencrypted)) {
            $output->writeln("    <fg=gray>No unencrypted .env files found in config repo.</>");
            return;
        }

        $output->writeln("    <fg=gray>Found:</> <fg=white>" . implode(', ', $unencrypted) . "</>");
        $question = new ConfirmationQuestion(
            '    Encrypt them now? [<fg=green>Y</>/n] ', true
        );
        if ($helper->ask($input, $output, $question)) {
            foreach ($unencrypted as $envName) {
                $envPath = rtrim($configrepo, '/') . '/' . $envName;
                $encPath = $envPath . '.enc';
                if (Secrets::encryptFile($envPath, $encPath)) {
                    unlink($envPath);
                    Git::addIgnore($envName, $configrepo);
                    $output->writeln("    <fg=green>✓</> <fg=white>{$envName}</> → <fg=white>{$envName}.enc</>");
                }
            }
            Shell::run("git -C '$configrepo' add -A");
            Shell::run("git -C '$configrepo' commit -m 'encrypt secrets' 2>/dev/null");

            Json::write('deployment.secrets', 'encrypted', $repo_dir);
            Json::save($repo_dir);
        }
    }

    // ─── Docker compose volume helper ────────────────────────────

    protected function addDockerComposeVolume(string $repo_dir, string $foldername): void
    {
        $dockerComposePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
        if (file_exists($dockerComposePath)) {
            $content = file_get_contents($dockerComposePath);
            if (strpos($content, $foldername) === false) {
                $configVolumeLine = "      - \"../{$foldername}/:/var/www/{$foldername}:rw\"";
                if (preg_match('/(    volumes:\s*\n\s*- "\.:.+?:rw")/s', $content, $matches)) {
                    $replacement = $matches[1] . "\n" . $configVolumeLine;
                    $content = str_replace($matches[1], $replacement, $content);
                    file_put_contents($dockerComposePath, $content);
                }
            }
        }
    }

    // ─── Completion screen ───────────────────────────────────────

    protected function showCompletion(OutputInterface $output, string $foldername, string $environment): void
    {
        $this->clearAndBanner($output);
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Configuration Setup Complete!</>                    <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Config repo:</>  <fg=white>' . $foldername . '/</>                        <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Environment:</> <fg=white>' . $environment . '</>                          <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Secrets:</>     ' . (Secrets::hasKey() ? '<fg=green>encrypted</>' : '<fg=yellow>plaintext</>') . '                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

}
