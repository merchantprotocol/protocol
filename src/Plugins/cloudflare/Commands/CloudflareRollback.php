<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Commands\Init\DotMenuTrait;
use Gitcd\Helpers\Git;

class CloudflareRollback extends Command
{
    use DotMenuTrait;

    protected static $defaultName = 'cf:rollback';
    protected static $defaultDescription = 'Rollback to a previous Cloudflare Pages deployment';

    protected function configure(): void
    {
        $this->addArgument('deployment-id', InputArgument::OPTIONAL, 'Deployment ID to rollback to (skips interactive selection)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $projectName = CloudflareHelper::projectName($repoDir);
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Rollback ──────────────────────────────────</>');
        $output->writeln("  <fg=gray>Project:</> <fg=white>{$projectName}</>");
        $output->writeln('');

        // Get deployment ID from argument or interactive selection
        $deploymentId = $input->getArgument('deployment-id');

        if (!$deploymentId) {
            $output->writeln("    <fg=gray>Fetching deployments from Cloudflare...</>");
            $output->writeln('');

            $deployments = CloudflareHelper::getDeployments($projectName, null, 10);

            if (count($deployments) < 2) {
                $output->writeln("    <fg=yellow>Not enough deployments to rollback.</> Need at least 2.");
                $output->writeln('');
                return Command::FAILURE;
            }

            // Skip the first one (it's the current live deployment)
            $options = [];
            $labels = [];
            foreach ($deployments as $i => $d) {
                $shortId = $d['short_id'] ?? substr($d['id'], 0, 8);
                $when = $this->timeAgo($d['created_on'] ?? '');
                $commit = substr($d['deployment_trigger']['metadata']['commit_hash'] ?? 'n/a', 0, 7);
                $commitMsg = $d['deployment_trigger']['metadata']['commit_message'] ?? '';
                $commitMsg = substr($commitMsg, 0, 40);
                $label = ($i === 0)
                    ? "{$shortId}  {$when}  {$commit}  {$commitMsg}  (current)"
                    : "{$shortId}  {$when}  {$commit}  {$commitMsg}";
                $options[$d['id']] = $label;
            }

            $keys = array_keys($options);
            $defaultKey = $keys[1] ?? $keys[0]; // Default to second (previous deployment)

            $output->writeln("    <fg=gray>Select a deployment to rollback to:</>");
            $output->writeln('');

            $deploymentId = $this->askWithDots($input, $output, $helper, $options, $defaultKey);
        }

        // Find deployment info
        $deployments = CloudflareHelper::getDeployments($projectName, null, 20);
        $target = null;
        foreach ($deployments as $d) {
            if ($d['id'] === $deploymentId || ($d['short_id'] ?? '') === $deploymentId) {
                $target = $d;
                $deploymentId = $d['id'];
                break;
            }
        }

        if (!$target) {
            $output->writeln("    <fg=red>FAIL:</> Deployment <fg=white>{$deploymentId}</> not found.");
            $output->writeln('');
            return Command::FAILURE;
        }

        $shortId = $target['short_id'] ?? substr($deploymentId, 0, 8);
        $when = $this->timeAgo($target['created_on'] ?? '');
        $commit = substr($target['deployment_trigger']['metadata']['commit_hash'] ?? 'n/a', 0, 7);
        $url = $target['url'] ?? '';

        $output->writeln('');
        $output->writeln("    <fg=white;options=bold>Rolling back to:</>");
        $output->writeln("      ID:     <fg=white>{$shortId}</>");
        $output->writeln("      Commit: <fg=gray>{$commit}</>");
        $output->writeln("      When:   <fg=gray>{$when}</>");
        $output->writeln("      URL:    <fg=cyan>{$url}</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Proceed with rollback? [y/<fg=green>N</>] ', false
        );
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('    <fg=gray>Rollback cancelled.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln("    <fg=gray>Rolling back...</>");

        $result = CloudflareHelper::rollback($projectName, $deploymentId);

        if (!$result || !($result['success'] ?? false)) {
            $errors = $result['errors'] ?? [];
            $errorMsg = !empty($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'API request failed';
            $output->writeln("    <fg=red>FAIL:</> {$errorMsg}");
            $output->writeln('');
            return Command::FAILURE;
        }

        $productionUrl = CloudflareHelper::productionUrl($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌──────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Rollback complete!</>                                <fg=cyan>│</>');
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Restored:</> <fg=white>{$shortId}</>  <fg=gray>({$commit})</>                    <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Live at:</>  <fg=white>{$productionUrl}</>                     <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └──────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function timeAgo(string $iso): string
    {
        if (!$iso) return 'unknown';
        $ts = strtotime($iso);
        if (!$ts) return 'unknown';
        $diff = time() - $ts;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return intval($diff / 60) . 'm ago';
        if ($diff < 86400) return intval($diff / 3600) . 'h ago';
        if ($diff < 604800) return intval($diff / 86400) . 'd ago';
        return date('Y-m-d', $ts);
    }
}
