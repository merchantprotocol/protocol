<?php
namespace Gitcd\Plugins\awssecrets\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Gitcd\Plugins\awssecrets\AwsSecretsHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Secrets;
use Gitcd\Utils\Json;

class AwsSecretsPush extends Command
{
    protected static $defaultName = 'aws:push';
    protected static $defaultDescription = 'Push local .env secrets to AWS Secrets Manager';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Reads a .env file from the config repo and pushes its contents to AWS Secrets
            Manager. If no environment is specified, you will be prompted to choose one.

            The .env key-value pairs are stored as a JSON object in the secret.
            If the .env is encrypted (.env.enc), it will be decrypted in-memory first.

            The secret name includes the environment: protocol/{project}/{environment}

            Examples:
              protocol aws:push                     # Interactive — choose environment
              protocol aws:push production           # Push production .env directly
              protocol aws:push --file /path/.env    # Push a specific file

            HELP)
            ->addArgument('environment', InputArgument::OPTIONAL, 'Environment to push (e.g., production, staging)')
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Path to .env file to push (bypasses config repo)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = $input->getOption('dir') ?: WORKING_DIR;
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln('  <fg=white;options=bold>Push to AWS Secrets Manager</>');
        $output->writeln('  <fg=gray>────────────────────────────────</>');
        $output->writeln('');

        // If --file is specified, skip environment selection
        if ($input->getOption('file')) {
            return $this->pushFile($input->getOption('file'), null, $repoDir, $input, $output);
        }

        // Get the config repo
        $configRepo = Config::repo($repoDir);
        if (!$configRepo || !is_dir($configRepo)) {
            $output->writeln('  <error>No config repo found. Run `protocol config:init` first.</error>');
            $output->writeln('  Or use <fg=cyan>--file</> to push a specific .env file.');
            $output->writeln('');
            return Command::FAILURE;
        }

        // Get available environments (git branches in config repo)
        $branchOutput = Shell::run("git -C " . escapeshellarg($configRepo) . " branch -a 2>/dev/null");
        $environments = $this->parseBranches($branchOutput);

        if (empty($environments)) {
            $output->writeln('  <error>No environment branches found in config repo.</error>');
            return Command::FAILURE;
        }

        // Determine which environment to push
        $environment = $input->getArgument('environment');
        if (!$environment) {
            // Show current environment
            $currentBranch = trim(Shell::run("git -C " . escapeshellarg($configRepo) . " rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');
            if ($currentBranch) {
                $output->writeln("  Current config branch: <fg=white>{$currentBranch}</>");
                $output->writeln('');
            }

            // Prompt user to choose, defaulting to the current config branch
            $defaultIndex = $currentBranch ? array_search($currentBranch, $environments) : 0;
            if ($defaultIndex === false) $defaultIndex = 0;

            $question = new ChoiceQuestion(
                '  Which environment do you want to push secrets for?',
                $environments,
                $defaultIndex
            );
            $environment = $helper->ask($input, $output, $question);
            $output->writeln('');
        }

        if (!in_array($environment, $environments)) {
            $output->writeln("  <error>Environment \"{$environment}\" not found in config repo.</error>");
            $output->writeln('  Available: ' . implode(', ', $environments));
            return Command::FAILURE;
        }

        // Save config repo first so any local .env edits are committed
        $output->writeln('  Saving config repo...');
        $configSaveCmd = $this->getApplication()->find('config:save');
        $configSaveInput = new ArrayInput(['--dir' => $repoDir]);
        $configSaveCmd->run($configSaveInput, $output);
        $output->writeln('');

        // Read .env from that branch (without switching branches)
        $envContents = $this->readEnvFromBranch($configRepo, $environment, $output);
        if ($envContents === null) {
            return Command::FAILURE;
        }

        // Override secret name to use the selected environment
        $projectName = Json::read('name', '', $repoDir);
        if (!$projectName && $repoDir) {
            $projectName = basename(rtrim($repoDir, '/'));
        }
        $secretName = "protocol/{$projectName}/{$environment}";

        // Parse and show key names (not values)
        $json = json_decode(AwsSecretsHelper::envToJson($envContents), true);
        $keyCount = count($json);

        $output->writeln("  Environment: <fg=white;options=bold>{$environment}</>");
        $output->writeln("  Secret:      <fg=white>{$secretName}</>");
        $output->writeln("  Region:      <fg=white>" . AwsSecretsHelper::region($repoDir) . "</>");
        $output->writeln("  Keys:        <fg=white>{$keyCount}</>");
        $output->writeln('');

        // Show key names
        foreach (array_keys($json) as $key) {
            $output->writeln("    <fg=gray>-</> {$key}");
        }
        $output->writeln('');

        // Confirm
        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion("  Push {$environment} secrets to AWS? [y/N] ", false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('  <fg=gray>Cancelled.</>');
                return Command::SUCCESS;
            }
            $output->writeln('');
        }

        // Push with environment-specific secret name
        $output->writeln('  Pushing secrets...');
        $success = AwsSecretsHelper::pushSecretAs($envContents, $secretName, $repoDir);

        if ($success) {
            $output->writeln("  <info>✓ Secrets pushed to {$secretName}</info>");
            $output->writeln('');
        } else {
            $output->writeln('  <error>Failed to push secrets. Check aws-secrets.log for details.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Push a specific file (--file mode, no environment selection).
     */
    private function pushFile(string $envFile, ?string $environment, string $repoDir, InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        if (!is_file($envFile)) {
            $output->writeln("  <error>File not found: {$envFile}</error>");
            return Command::FAILURE;
        }

        $envContents = file_get_contents($envFile);
        $secretName = AwsSecretsHelper::secretName($repoDir);

        $json = json_decode(AwsSecretsHelper::envToJson($envContents), true);
        $keyCount = count($json);

        $output->writeln("  Source:  <fg=white>{$envFile}</>");
        $output->writeln("  Secret:  <fg=white>{$secretName}</>");
        $output->writeln("  Region:  <fg=white>" . AwsSecretsHelper::region($repoDir) . "</>");
        $output->writeln("  Keys:    <fg=white>{$keyCount}</>");
        $output->writeln('');

        foreach (array_keys($json) as $key) {
            $output->writeln("    <fg=gray>-</> {$key}");
        }
        $output->writeln('');

        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion('  Push these secrets to AWS? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('  <fg=gray>Cancelled.</>');
                return Command::SUCCESS;
            }
            $output->writeln('');
        }

        $output->writeln('  Pushing secrets...');
        $success = AwsSecretsHelper::pushSecret($envContents, $repoDir);

        if ($success) {
            $output->writeln("  <info>✓ Secrets pushed to {$secretName}</info>");
            $output->writeln('');
        } else {
            $output->writeln('  <error>Failed to push secrets. Check aws-secrets.log for details.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Parse git branch output into clean branch names.
     */
    private function parseBranches(string $branchOutput): array
    {
        $branches = [];
        foreach (explode("\n", trim($branchOutput)) as $line) {
            $line = trim($line, " *\t");
            // Skip remote tracking branches like remotes/origin/HEAD -> origin/master
            if (str_contains($line, '->')) continue;
            // Extract branch name from remotes/origin/xxx
            if (str_starts_with($line, 'remotes/origin/')) {
                $line = substr($line, strlen('remotes/origin/'));
            }
            if ($line && !in_array($line, $branches)) {
                $branches[] = $line;
            }
        }
        return $branches;
    }

    /**
     * Read .env contents from a specific branch without switching branches.
     * Handles both plaintext .env and encrypted .env.enc.
     */
    private function readEnvFromBranch(string $configRepo, string $branch, OutputInterface $output): ?string
    {
        $escapedRepo = escapeshellarg($configRepo);
        $escapedBranch = escapeshellarg($branch);

        // Try plaintext .env first
        $envContents = Shell::run("git -C {$escapedRepo} show {$escapedBranch}:.env 2>/dev/null", $returnVar);
        if ($returnVar === 0 && $envContents) {
            $output->writeln("  <info>✓</info> Read .env from <fg=white>{$branch}</> branch");
            $output->writeln('');
            return $envContents;
        }

        // Try encrypted .env.enc
        $encContents = Shell::run("git -C {$escapedRepo} show {$escapedBranch}:.env.enc 2>/dev/null", $returnVar);
        if ($returnVar === 0 && $encContents) {
            if (!Secrets::hasKey()) {
                $output->writeln("  <error>Found .env.enc on {$branch} but no encryption key on this machine.</error>");
                $output->writeln('  Copy the key from a node that has it, or generate a new one.');
                return null;
            }

            $decrypted = Secrets::decrypt($encContents);
            if ($decrypted === null) {
                $output->writeln("  <error>Failed to decrypt .env.enc from {$branch}. Wrong key?</error>");
                return null;
            }

            $output->writeln("  <info>✓</info> Decrypted .env.enc from <fg=white>{$branch}</> branch");
            $output->writeln('');
            return $decrypted;
        }

        $output->writeln("  <error>No .env or .env.enc found on branch \"{$branch}\"</error>");
        return null;
    }
}
