<?php
namespace Gitcd\Plugins\awssecrets\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\awssecrets\AwsSecretsHelper;
use Gitcd\Helpers\Git;
use Gitcd\Utils\Json;

class AwsSecretsStatus extends Command
{
    protected static $defaultName = 'aws:status';
    protected static $defaultDescription = 'Show secret metadata (version, last modified, ARN)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Displays metadata about the AWS Secrets Manager secret configured for
            this project. Shows the ARN, last modified date, version count, and
            the key names stored (not their values).

            HELP)
            ->addArgument('environment', InputArgument::OPTIONAL, 'Environment to check (e.g., production, staging)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = $input->getOption('dir') ?: WORKING_DIR;
        $environment = $input->getArgument('environment');
        if ($environment) {
            $projectName = Json::read('name', '', $repoDir);
            if (!$projectName && $repoDir) {
                $projectName = basename(rtrim($repoDir, '/'));
            }
            $secretName = "protocol/{$projectName}/{$environment}";
        } else {
            $secretName = AwsSecretsHelper::secretName($repoDir);
        }
        $region = AwsSecretsHelper::region($repoDir);

        $output->writeln('');
        $output->writeln('  <fg=white;options=bold>AWS Secrets Manager Status</>');
        $output->writeln('  <fg=gray>────────────────────────────────</>');
        $output->writeln('');

        $output->writeln("  Secret:  <fg=white>{$secretName}</>");
        $output->writeln("  Region:  <fg=white>{$region}</>");
        $output->writeln('');

        // Describe the secret
        $meta = AwsSecretsHelper::describeSecret($repoDir, $secretName);

        if (!$meta) {
            $output->writeln('  <error>Secret not found or access denied.</error>');
            $output->writeln('  <fg=gray>Run "protocol aws:init" to configure, or "protocol aws:push" to create.</>');
            return Command::FAILURE;
        }

        // Display metadata
        $arn = $meta['ARN'] ?? 'unknown';
        $lastChanged = $meta['LastChangedDate'] ?? $meta['CreatedDate'] ?? 'unknown';
        if ($lastChanged instanceof \DateTimeInterface) {
            $lastChanged = $lastChanged->format('Y-m-d H:i:s T');
        } elseif (is_string($lastChanged)) {
            $lastChanged = date('Y-m-d H:i:s T', strtotime($lastChanged));
        }

        $versionIds = $meta['VersionIdsToStages'] ?? [];
        $versionCount = count($versionIds);

        $rotationEnabled = !empty($meta['RotationEnabled']) ? 'yes' : 'no';

        $output->writeln("  ARN:       <fg=white>{$arn}</>");
        $output->writeln("  Modified:  <fg=white>{$lastChanged}</>");
        $output->writeln("  Versions:  <fg=white>{$versionCount}</>");
        $output->writeln("  Rotation:  <fg=white>{$rotationEnabled}</>");
        $output->writeln('');

        // Show key names (not values)
        $keys = AwsSecretsHelper::getSecretKeys($repoDir, $secretName);
        $keyCount = count($keys);
        if (!empty($keys)) {
            $output->writeln("  <fg=white;options=bold>Keys stored ({$keyCount}):</>");
            foreach ($keys as $key) {
                $output->writeln("    <fg=gray>•</> {$key}");
            }
        } else {
            $output->writeln('  <fg=gray>No keys found (secret may be empty or binary).</>');
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
