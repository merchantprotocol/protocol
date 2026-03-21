<?php
namespace Gitcd\Plugins\awssecrets\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\awssecrets\AwsSecretsHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;

class AwsSecretsPush extends Command
{
    protected static $defaultName = 'aws:push';
    protected static $defaultDescription = 'Push local .env secrets to AWS Secrets Manager';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Reads a local .env file and pushes its contents to AWS Secrets Manager.
            The .env key-value pairs are stored as a JSON object in the secret.

            By default, reads from the config repo's .env file. Use --file to specify
            an alternate path.

            The secret is created if it doesn't exist, or updated if it does.

            HELP)
            ->addOption('file', 'f', InputOption::VALUE_OPTIONAL, 'Path to .env file to push')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = $input->getOption('dir') ?: WORKING_DIR;
        $helper = $this->getHelper('question');

        // Resolve .env file path
        $envFile = $input->getOption('file');
        if (!$envFile) {
            // Try config repo first, then project root
            $configRepo = Config::repo($repoDir);
            if ($configRepo && is_file($configRepo . '.env')) {
                $envFile = $configRepo . '.env';
            } elseif (is_file($repoDir . '.env')) {
                $envFile = $repoDir . '.env';
            }
        }

        if (!$envFile || !is_file($envFile)) {
            $output->writeln('<error>No .env file found. Use --file to specify a path.</error>');
            return Command::FAILURE;
        }

        $envContents = file_get_contents($envFile);
        $secretName = AwsSecretsHelper::secretName($repoDir);

        // Parse and show key names (not values)
        $json = json_decode(AwsSecretsHelper::envToJson($envContents), true);
        $keyCount = count($json);

        $output->writeln('');
        $output->writeln("  <fg=white;options=bold>Push to AWS Secrets Manager</>");
        $output->writeln('');
        $output->writeln("  Source:  <fg=white>{$envFile}</>");
        $output->writeln("  Secret:  <fg=white>{$secretName}</>");
        $output->writeln("  Region:  <fg=white>" . AwsSecretsHelper::region($repoDir) . "</>");
        $output->writeln("  Keys:    <fg=white>{$keyCount}</>");
        $output->writeln('');

        // Show key names
        foreach (array_keys($json) as $key) {
            $output->writeln("    <fg=gray>•</> {$key}");
        }
        $output->writeln('');

        // Confirm
        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion('  Push these secrets to AWS? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('  <fg=gray>Cancelled.</>');
                return Command::SUCCESS;
            }
            $output->writeln('');
        }

        // Push
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
}
