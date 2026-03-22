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

        // ── Step 1: Select AWS Credentials ────────────────────────────
        $output->writeln('  <fg=white;options=bold>Step 1/3:</> Select AWS Credentials');
        $output->writeln('');
        $output->writeln('  Scanning profiles for Secrets Manager access...');
        $output->writeln('');

        // Discover all AWS profiles from ~/.aws/credentials and ~/.aws/config
        $profiles = $this->discoverProfiles();

        // Test each profile for Secrets Manager access
        $profileResults = [];
        foreach ($profiles as $profile) {
            $profileFlag = ($profile === 'default') ? '' : ' --profile ' . escapeshellarg($profile);

            // Check identity
            $identResult = Shell::run("aws sts get-caller-identity{$profileFlag} 2>&1", $identReturn);
            if ($identReturn !== 0) {
                $profileResults[$profile] = ['auth' => false, 'sm' => false, 'arn' => null, 'account' => null];
                continue;
            }

            $identData = json_decode($identResult, true);
            $profileArn = $identData['Arn'] ?? 'unknown';
            $profileAccount = $identData['Account'] ?? 'unknown';

            // Check Secrets Manager access
            $smResult = Shell::run("aws secretsmanager list-secrets --max-results 1{$profileFlag} 2>&1", $smReturn);
            $hasSm = ($smReturn === 0);

            $profileResults[$profile] = [
                'auth' => true,
                'sm' => $hasSm,
                'arn' => $profileArn,
                'account' => $profileAccount,
            ];
        }

        // Also check if EC2 instance role is available (no profile)
        if (!in_array('default', $profiles)) {
            $identResult = Shell::run('aws sts get-caller-identity 2>&1', $identReturn);
            if ($identReturn === 0) {
                $identData = json_decode($identResult, true);
                $smResult = Shell::run('aws secretsmanager list-secrets --max-results 1 2>&1', $smReturn);
                $profileResults['(instance role)'] = [
                    'auth' => true,
                    'sm' => ($smReturn === 0),
                    'arn' => $identData['Arn'] ?? 'unknown',
                    'account' => $identData['Account'] ?? 'unknown',
                ];
            }
        }

        // Build the choice list
        $choices = [];
        $selectableMap = []; // index => profile name
        $idx = 0;
        $hasSelectable = false;

        foreach ($profileResults as $profile => $result) {
            if (!$result['auth']) {
                $output->writeln("  <fg=gray>  {$profile} — invalid credentials</>");
                continue;
            }

            if ($result['sm']) {
                // Selectable — has Secrets Manager access
                $label = "{$profile} — {$result['arn']}";
                $choices[$idx] = $label;
                $selectableMap[$idx] = $profile;
                $output->writeln("  <info>✓</info> {$label}");
                $hasSelectable = true;
            } else {
                // Greyed out — no Secrets Manager access
                $output->writeln("  <fg=gray>✗ {$profile} — {$result['arn']} (no Secrets Manager access)</>");
            }
            $idx++;
        }

        // Always add "Configure new credentials"
        $newCredsIdx = $idx;
        $choices[$newCredsIdx] = 'Configure new credentials';
        $output->writeln('');

        if (empty($profileResults) || (!$hasSelectable && count($profileResults) === 0)) {
            $output->writeln('  <comment>No AWS profiles found.</comment>');
            $output->writeln('');
        }

        // Prompt selection
        $question = new ChoiceQuestion('  Select credentials to use:', $choices);
        $selected = $helper->ask($input, $output, $question);
        $output->writeln('');

        $selectedProfile = null;
        $account = 'unknown';
        $arn = 'unknown';

        if ($selected === 'Configure new credentials') {
            $profileQ = new Question('  New profile name (or "default"): ', 'default');
            $newProfile = $helper->ask($input, $output, $profileQ);
            $output->writeln('');
            $output->writeln("  Running <fg=cyan>aws configure --profile {$newProfile}</>...");
            $output->writeln('');
            Shell::passthru('aws configure --profile ' . escapeshellarg($newProfile));
            $output->writeln('');

            // Verify the new profile
            $profileFlag = ($newProfile === 'default') ? '' : ' --profile ' . escapeshellarg($newProfile);
            $identity = Shell::run("aws sts get-caller-identity{$profileFlag} 2>&1", $returnVar);
            if ($returnVar !== 0) {
                $output->writeln('  <error>Authentication failed for new profile.</error>');
                return Command::FAILURE;
            }

            $identityData = json_decode($identity, true);
            $account = $identityData['Account'] ?? 'unknown';
            $arn = $identityData['Arn'] ?? 'unknown';

            // Test SM access
            $smTest = Shell::run("aws secretsmanager list-secrets --max-results 1{$profileFlag} 2>&1", $smReturn);
            if ($smReturn !== 0) {
                $output->writeln('  <error>Profile authenticated but lacks Secrets Manager access.</error>');
                $output->writeln('');
                $this->showRequiredPolicy($output, $account);
                return Command::FAILURE;
            }

            $selectedProfile = ($newProfile === 'default') ? null : $newProfile;
        } else {
            // Find which profile was selected
            $selectedKey = array_search($selected, $choices);
            $selectedProfile = $selectableMap[$selectedKey] ?? null;

            if ($selectedProfile === '(instance role)' || $selectedProfile === 'default') {
                $selectedProfile = ($selectedProfile === '(instance role)') ? null : null;
            }

            $result = $profileResults[$selectableMap[$selectedKey]] ?? null;
            if ($result) {
                $account = $result['account'];
                $arn = $result['arn'];
            }
        }

        // Save profile to protocol.json
        if ($selectedProfile && $selectedProfile !== 'default' && $selectedProfile !== '(instance role)') {
            Json::write('aws.profile', $selectedProfile, $repoDir);
            putenv("AWS_PROFILE={$selectedProfile}");
        }

        $output->writeln("  <info>✓</info> Using: <fg=white>{$arn}</>");
        $output->writeln("  <info>✓</info> Account: <fg=white>{$account}</>");
        $output->writeln("  <info>✓</info> Secrets Manager access confirmed");
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

        // ── Step 3: Check Secret ─────────────────────────────────────
        $output->writeln('  <fg=white;options=bold>Step 3/3:</> Check Secret');
        $output->writeln('');

        $testCmd = 'aws secretsmanager describe-secret'
            . ' --secret-id ' . escapeshellarg($secretName)
            . ' --region ' . escapeshellarg($region)
            . ' 2>&1';
        $testResult = Shell::run($testCmd, $testReturn);

        if ($testReturn === 0) {
            $output->writeln("  <info>✓</info> Secret exists: <fg=white>{$secretName}</>");
        } elseif (strpos($testResult, 'ResourceNotFoundException') !== false) {
            $output->writeln("  <info>✓</info> Secret will be created on first <fg=cyan>protocol aws:push</>");
        } else {
            $output->writeln("  <comment>⚠</comment> <fg=gray>{$testResult}</>");
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

    /**
     * Discover AWS profiles from ~/.aws/credentials and ~/.aws/config.
     */
    private function discoverProfiles(): array
    {
        $profiles = [];

        // Parse ~/.aws/credentials
        $credFile = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.aws/credentials';
        if (is_file($credFile)) {
            $lines = file($credFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                    $profiles[] = $m[1];
                }
            }
        }

        // Parse ~/.aws/config (profiles are prefixed with "profile ")
        $configFile = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.aws/config';
        if (is_file($configFile)) {
            $lines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if (preg_match('/^\[profile\s+(.+)\]$/', $line, $m)) {
                    if (!in_array($m[1], $profiles)) {
                        $profiles[] = $m[1];
                    }
                } elseif (preg_match('/^\[default\]$/', $line)) {
                    if (!in_array('default', $profiles)) {
                        $profiles[] = 'default';
                    }
                }
            }
        }

        return $profiles;
    }

    /**
     * Display the required IAM policy for Secrets Manager access.
     */
    private function showRequiredPolicy(OutputInterface $output, string $account): void
    {
        $output->writeln('  <fg=white>Attach this IAM policy to the role/user:</>');
        $output->writeln('');
        $output->writeln('  <fg=gray>{</>');
        $output->writeln('    <fg=gray>"Version": "2012-10-17",</>');
        $output->writeln('    <fg=gray>"Statement": [{</>');
        $output->writeln('      <fg=gray>"Effect": "Allow",</>');
        $output->writeln('      <fg=gray>"Action": [</>');
        $output->writeln('        <fg=gray>"secretsmanager:CreateSecret",</>');
        $output->writeln('        <fg=gray>"secretsmanager:PutSecretValue",</>');
        $output->writeln('        <fg=gray>"secretsmanager:GetSecretValue",</>');
        $output->writeln('        <fg=gray>"secretsmanager:DescribeSecret",</>');
        $output->writeln('        <fg=gray>"secretsmanager:ListSecrets"</>');
        $output->writeln('      <fg=gray>],</>');
        $output->writeln("      <fg=gray>\"Resource\": \"arn:aws:secretsmanager:*:{$account}:secret:protocol/*\"</>");
        $output->writeln('    <fg=gray>}]</>');
        $output->writeln('  <fg=gray>}</>');
        $output->writeln('');
    }
}
