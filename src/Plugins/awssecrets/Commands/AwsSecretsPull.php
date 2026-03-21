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

class AwsSecretsPull extends Command
{
    protected static $defaultName = 'aws:pull';
    protected static $defaultDescription = 'Pull secrets from AWS Secrets Manager to local .env';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Fetches the secret from AWS Secrets Manager and writes it as a .env file.

            By default, writes to the config repo's .env file. Use --stdout to print
            to stdout instead of writing to a file.

            HELP)
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Print to stdout instead of writing to file')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = $input->getOption('dir') ?: WORKING_DIR;
        $helper = $this->getHelper('question');
        $secretName = AwsSecretsHelper::secretName($repoDir);

        $output->writeln('');
        $output->writeln("  <fg=white;options=bold>Pull from AWS Secrets Manager</>");
        $output->writeln('');
        $output->writeln("  Secret:  <fg=white>{$secretName}</>");
        $output->writeln("  Region:  <fg=white>" . AwsSecretsHelper::region($repoDir) . "</>");
        $output->writeln('');

        // Pull the secret
        $output->writeln('  Fetching...');
        $envContents = AwsSecretsHelper::pullSecret($repoDir);

        if ($envContents === null) {
            $output->writeln('  <error>Failed to pull secret. Check aws-secrets.log for details.</error>');
            return Command::FAILURE;
        }

        // Count keys
        $lines = array_filter(explode("\n", trim($envContents)));
        $keyCount = count($lines);
        $output->writeln("  <info>✓</info> Retrieved {$keyCount} keys");
        $output->writeln('');

        // stdout mode
        if ($input->getOption('stdout')) {
            $output->writeln($envContents);
            return Command::SUCCESS;
        }

        // Determine output file path
        $configRepo = Config::repo($repoDir);
        $envFile = $configRepo ? $configRepo . '.env' : $repoDir . '.env';

        $exists = is_file($envFile);
        $output->writeln("  Target:  <fg=white>{$envFile}</>" . ($exists ? ' <comment>(exists)</comment>' : ''));
        $output->writeln('');

        // Confirm if file exists
        if ($exists && !$input->getOption('yes')) {
            $question = new ConfirmationQuestion('  This will overwrite the existing .env file. Continue? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('  <fg=gray>Cancelled.</>');
                return Command::SUCCESS;
            }
            $output->writeln('');
        }

        // Write file
        file_put_contents($envFile, $envContents);
        chmod($envFile, 0600);

        $output->writeln("  <info>✓ Written to {$envFile}</info>");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
