<?php
namespace Gitcd\Plugins\awssecrets\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Gitcd\Plugins\awssecrets\AwsSecretsHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;

class AwsSecretsInit extends Command
{
    protected static $defaultName = 'aws:init';
    protected static $defaultDescription = 'Configure AWS Secrets Manager for this project';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Interactive setup wizard for AWS Secrets Manager integration.
            Configures the AWS region, secret name, and sets deployment.secrets
            to 'aws' in protocol.json.

            Prerequisites:
              - AWS CLI installed and configured (or IAM role on EC2)
              - Appropriate IAM permissions for Secrets Manager

            HELP)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = $input->getOption('dir') ?: WORKING_DIR;
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln('  <fg=white;options=bold>AWS Secrets Manager Setup</>');
        $output->writeln('  <fg=gray>────────────────────────────────</>');
        $output->writeln('');

        // ── Step 1: Verify AWS Access ────────────────────────────────
        $output->writeln('  <fg=white;options=bold>Step 1/3:</> Verify AWS Access');
        $output->writeln('');

        $identity = Shell::run('aws sts get-caller-identity 2>&1', $returnVar);

        if ($returnVar === 0) {
            $identityData = json_decode($identity, true);
            $account = $identityData['Account'] ?? 'unknown';
            $arn = $identityData['Arn'] ?? 'unknown';
            $userId = $identityData['UserId'] ?? 'unknown';
            $output->writeln("  Current AWS identity:");
            $output->writeln("    Account: <fg=white>{$account}</>");
            $output->writeln("    ARN:     <fg=white>{$arn}</>");
            $output->writeln("    User ID: <fg=white>{$userId}</>");
            $output->writeln('');

            $question = new ConfirmationQuestion('  Use this identity? [<fg=cyan>Y</>/n]: ', true);
            $useExisting = $helper->ask($input, $output, $question);
            $output->writeln('');

            if (!$useExisting) {
                $output->writeln('  <fg=white>How would you like to configure AWS credentials?</>');
                $output->writeln('');
                $choice = new ChoiceQuestion('  Select method:', [
                    '1' => 'Run "aws configure" (access key + secret key)',
                    '2' => 'Set a named profile (aws configure --profile <name>)',
                    '3' => 'Cancel — I\'ll configure credentials manually',
                ], '1');
                $method = $helper->ask($input, $output, $choice);
                $output->writeln('');

                if ($method === '3' || str_contains($method, 'Cancel')) {
                    $output->writeln('  Configure your credentials and re-run <fg=cyan>protocol aws:init</>');
                    $output->writeln('');
                    return Command::FAILURE;
                }

                if ($method === '2' || str_contains($method, 'profile')) {
                    $profileQ = new Question('  Profile name: ', '');
                    $profile = $helper->ask($input, $output, $profileQ);
                    if (!$profile) {
                        $output->writeln('  <error>Profile name is required.</error>');
                        return Command::FAILURE;
                    }
                    $output->writeln('');
                    $output->writeln("  Running <fg=cyan>aws configure --profile {$profile}</>...");
                    $output->writeln('');
                    Shell::passthru("aws configure --profile " . escapeshellarg($profile));
                    // Set the profile for subsequent AWS calls in this session
                    putenv("AWS_PROFILE={$profile}");
                    // Save profile to protocol.json so deploy uses the right one
                    Json::write('aws.profile', $profile, $repoDir);
                } else {
                    $output->writeln('  Running <fg=cyan>aws configure</>...');
                    $output->writeln('');
                    Shell::passthru('aws configure');
                }

                $output->writeln('');

                // Re-verify after configuration
                $identity = Shell::run('aws sts get-caller-identity 2>&1', $returnVar);
                if ($returnVar !== 0) {
                    $output->writeln('  <error>AWS authentication failed after configuration.</error>');
                    $output->writeln("  <fg=gray>{$identity}</>");
                    return Command::FAILURE;
                }

                $identityData = json_decode($identity, true);
                $account = $identityData['Account'] ?? 'unknown';
                $arn = $identityData['Arn'] ?? 'unknown';
            }
        } else {
            $output->writeln('  <comment>No AWS credentials found.</comment>');
            $output->writeln('');
            $output->writeln('  Running <fg=cyan>aws configure</> to set up credentials...');
            $output->writeln('');

            Shell::passthru('aws configure');
            $output->writeln('');

            // Verify after configuration
            $identity = Shell::run('aws sts get-caller-identity 2>&1', $returnVar);
            if ($returnVar !== 0) {
                $output->writeln('  <error>AWS authentication failed. Check your credentials and try again.</error>');
                $output->writeln("  <fg=gray>{$identity}</>");
                return Command::FAILURE;
            }

            $identityData = json_decode($identity, true);
            $account = $identityData['Account'] ?? 'unknown';
            $arn = $identityData['Arn'] ?? 'unknown';
        }

        $output->writeln("  <info>✓</info> Authenticated as: <fg=white>{$arn}</>");
        $output->writeln("  <info>✓</info> Account: <fg=white>{$account}</>");
        $output->writeln('');

        // ── Step 2: Configure Region & Secret Name ───────────────────
        $output->writeln('  <fg=white;options=bold>Step 2/3:</> Configuration');
        $output->writeln('');

        // Region
        $currentRegion = AwsSecretsHelper::config('region', null, $repoDir);
        $defaultRegion = $currentRegion ?: 'us-east-1';

        $question = new Question("  AWS Region [<fg=cyan>{$defaultRegion}</>]: ", $defaultRegion);
        $region = $helper->ask($input, $output, $question);
        $output->writeln('');

        // Secret name
        $defaultName = AwsSecretsHelper::config('secret_name', null, $repoDir)
            ?: AwsSecretsHelper::defaultSecretName($repoDir);

        $question = new Question("  Secret name [<fg=cyan>{$defaultName}</>]: ", $defaultName);
        $secretName = $helper->ask($input, $output, $question);
        $output->writeln('');

        // ── Step 3: Test Access ──────────────────────────────────────
        $output->writeln('  <fg=white;options=bold>Step 3/3:</> Test Access');
        $output->writeln('');

        // Try to describe the secret (may not exist yet, that's ok)
        $testCmd = 'aws secretsmanager describe-secret'
            . ' --secret-id ' . escapeshellarg($secretName)
            . ' --region ' . escapeshellarg($region)
            . ' 2>&1';
        $testResult = Shell::run($testCmd, $testReturn);

        if ($testReturn === 0) {
            $output->writeln("  <info>✓</info> Secret exists: <fg=white>{$secretName}</>");
        } else {
            $testData = json_decode($testResult, true);
            $errorCode = $testData['Error']['Code'] ?? '';

            if (strpos($testResult, 'ResourceNotFoundException') !== false) {
                $output->writeln("  <info>✓</info> Secret does not exist yet — it will be created on first <fg=cyan>aws:push</>");
            } elseif (strpos($testResult, 'AccessDeniedException') !== false) {
                $output->writeln('  <error>✗ Access denied — check IAM permissions for secretsmanager:DescribeSecret</error>');
                $output->writeln("  <fg=gray>{$testResult}</>");
                return Command::FAILURE;
            } else {
                $output->writeln("  <comment>⚠ Unexpected response:</comment> <fg=gray>{$testResult}</>");
            }
        }

        $output->writeln('');

        // ── Save Configuration ───────────────────────────────────────
        Json::write('aws.region', $region, $repoDir);
        Json::write('aws.secret_name', $secretName, $repoDir);
        Json::write('deployment.secrets', 'aws', $repoDir);
        Json::save($repoDir);

        $output->writeln('  <info>Configuration saved to protocol.json:</info>');
        $output->writeln("    aws.region      = <fg=white>{$region}</>");
        $output->writeln("    aws.secret_name = <fg=white>{$secretName}</>");
        $output->writeln("    deployment.secrets = <fg=white>aws</>");
        $output->writeln('');
        $output->writeln('  Next steps:');
        $output->writeln('    <fg=cyan>protocol aws:push</>    Push your .env secrets to AWS');
        $output->writeln('    <fg=cyan>protocol aws:status</>  Verify the secret in AWS');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
