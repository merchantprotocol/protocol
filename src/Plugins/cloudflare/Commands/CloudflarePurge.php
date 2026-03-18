<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflarePurge extends Command
{
    protected static $defaultName = 'cf:purge';
    protected static $defaultDescription = 'Delete old deployments from Cloudflare Pages to clean up stale files';

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Number of recent deployments to keep', 1)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $projectName = CloudflareHelper::projectName($repoDir);
        $helper = $this->getHelper('question');
        $keep = max(1, (int) $input->getOption('keep'));
        $dryRun = $input->getOption('dry-run');

        $output->writeln('');
        $modeLabel = $dryRun ? 'Purge (dry run)' : 'Purge';
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln("<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE PAGES</> <fg=gray>·</> <fg=yellow>{$modeLabel}</>                     <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Project:</> <fg=white>{$projectName}</>                                  <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        $output->writeln("    <fg=gray>Fetching deployments from Cloudflare...</>");
        $output->writeln('');

        $deployments = CloudflareHelper::getDeployments($projectName, null, 50);

        if (empty($deployments)) {
            $output->writeln("    <fg=yellow>No deployments found.</> Check that wrangler is authenticated and the project name is correct.");
            $output->writeln('');
            return Command::FAILURE;
        }

        $total = count($deployments);

        if ($total <= $keep) {
            $output->writeln("    <fg=green>✓</> Only {$total} deployment(s) found, keeping all. Nothing to purge.");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $keeping = array_slice($deployments, 0, $keep);
        $deleting = array_slice($deployments, $keep);
        $deleteCount = count($deleting);

        $output->writeln("    <fg=white>Found {$total} deployments. Keeping the {$keep} most recent.</>");
        $output->writeln('');

        // Show what we're keeping
        $output->writeln('    <fg=green>Keeping:</>');
        foreach ($keeping as $d) {
            $shortId = $d['short_id'] ?? substr($d['id'], 0, 8);
            $when = $this->timeAgo($d['created_on'] ?? '');
            $env = $d['environment'] ?? 'unknown';
            $output->writeln("      <fg=green>✓</> {$shortId}  <fg=gray>{$when}  {$env}</>");
        }
        $output->writeln('');

        // Show what we're deleting
        $output->writeln("    <fg=red>Deleting ({$deleteCount}):</>");
        foreach (array_slice($deleting, 0, 10) as $d) {
            $shortId = $d['short_id'] ?? substr($d['id'], 0, 8);
            $when = $this->timeAgo($d['created_on'] ?? '');
            $env = $d['environment'] ?? 'unknown';
            $output->writeln("      <fg=red>✕</> {$shortId}  <fg=gray>{$when}  {$env}</>");
        }
        if ($deleteCount > 10) {
            $output->writeln("      <fg=gray>... and " . ($deleteCount - 10) . " more</>");
        }
        $output->writeln('');

        $output->writeln("    <fg=gray>Deleting old deployments removes stale files from the project pool.</>");
        $output->writeln("    <fg=gray>These deployment URLs will no longer be accessible.</>");
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("    <fg=yellow>Dry run</> — no deployments were deleted.");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $question = new ConfirmationQuestion(
            "    Delete {$deleteCount} old deployment(s)? [y/<fg=green>N</>] ", false
        );
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('    <fg=gray>Purge cancelled.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $deleted = 0;
        $failed = 0;

        foreach ($deleting as $d) {
            $shortId = $d['short_id'] ?? substr($d['id'], 0, 8);
            $result = CloudflareHelper::deleteDeployment($projectName, $d['id']);

            if ($result && ($result['success'] ?? false)) {
                $deleted++;
                $output->writeln("      <fg=green>✓</> Deleted {$shortId}");
            } else {
                $failed++;
                $errorMsg = 'unknown error';
                if ($result && !empty($result['errors'])) {
                    $errorMsg = $result['errors'][0]['message'] ?? $errorMsg;
                }
                $output->writeln("      <fg=red>✕</> Failed {$shortId}: {$errorMsg}");
            }
        }

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌──────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
        $output->writeln("<fg=cyan>  │</>   <fg=green;options=bold>✓  Purge complete!</>                                    <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Deleted:</> <fg=white>{$deleted}</>  <fg=gray>Failed:</> <fg=white>{$failed}</>                        <fg=cyan>│</>");
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
