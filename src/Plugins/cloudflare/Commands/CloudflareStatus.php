<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflareStatus extends Command
{
    protected static $defaultName = 'cf:status';
    protected static $defaultDescription = 'Compare local static files against live Cloudflare deployment';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $projectName = CloudflareHelper::projectName($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln("  <fg=white;options=bold>Cloudflare Pages · Status</>");
        $output->writeln("  <fg=gray>Project:</> <fg=white>{$projectName}</>");
        $output->writeln('<fg=cyan>  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('');

        if (!is_dir($staticDir) || CloudflareHelper::countFiles($staticDir) === 0) {
            $output->writeln("    <fg=yellow>No static output directory found.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $localCount = CloudflareHelper::countFiles($staticDir);
        $output->writeln("    Local files: <fg=white>{$localCount}</>");
        $output->writeln('');

        $output->writeln("    <fg=gray>Fetching deployed file manifest from Cloudflare...</>");

        $deployments = CloudflareHelper::getDeployments($projectName, null, 1);
        if (empty($deployments)) {
            $output->writeln("    <fg=gray>No deployments found on Cloudflare.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $latest = $deployments[0];
        $deployedSums = CloudflareHelper::getDeployedFiles($projectName, $latest['id']);
        $deployedCount = count($deployedSums);
        $shortId = $latest['short_id'] ?? substr($latest['id'], 0, 8);
        $when = $this->timeAgo($latest['created_on'] ?? '');

        $output->writeln("    Deployed:    <fg=white>{$deployedCount}</> files <fg=gray>({$shortId}, {$when})</>");
        $output->writeln('');

        if (empty($deployedSums)) {
            $output->writeln("    <fg=yellow>Could not fetch deployed file manifest.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $localSums = CloudflareHelper::checksumMap($staticDir);
        $diff = CloudflareHelper::diffAgainstDeployed($localSums, $deployedSums);

        $addedCount = count($diff['added']);
        $modifiedCount = count($diff['modified']);
        $removedCount = count($diff['removed']);

        if ($addedCount === 0 && $modifiedCount === 0 && $removedCount === 0) {
            $output->writeln("    <fg=green>Local files match the live deployment. No changes.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('    <fg=cyan>┌────────────────────────────────────────┐</>');
        $output->writeln('    <fg=cyan>│</>  <fg=white;options=bold>Changes vs Live Deployment</>           <fg=cyan>│</>');
        $output->writeln('    <fg=cyan>├────────────────────────────────────────┤</>');
        $output->writeln(sprintf('    <fg=cyan>│</>  Added:    <fg=green>%-28s</><fg=cyan>│</>', "{$addedCount} files"));
        $output->writeln(sprintf('    <fg=cyan>│</>  Modified: <fg=yellow>%-28s</><fg=cyan>│</>', "{$modifiedCount} files"));
        $output->writeln(sprintf('    <fg=cyan>│</>  Deleted:  <fg=red>%-28s</><fg=cyan>│</>', "{$removedCount} files"));
        $output->writeln('    <fg=cyan>└────────────────────────────────────────┘</>');
        $output->writeln('');

        if ($modifiedCount > 0) {
            sort($diff['modified']);
            $output->writeln('    <fg=yellow>Modified files:</>');
            foreach (array_slice($diff['modified'], 0, 15) as $f) {
                $output->writeln("      <fg=yellow>~</> {$f}");
            }
            if ($modifiedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($modifiedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

        if ($addedCount > 0) {
            sort($diff['added']);
            $output->writeln('    <fg=green>New files:</>');
            foreach (array_slice($diff['added'], 0, 15) as $f) {
                $output->writeln("      <fg=green>+</> {$f}");
            }
            if ($addedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($addedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

        if ($removedCount > 0) {
            sort($diff['removed']);
            $output->writeln('    <fg=red>Deleted from live:</>');
            foreach (array_slice($diff['removed'], 0, 15) as $f) {
                $output->writeln("      <fg=red>-</> {$f}");
            }
            if ($removedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($removedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

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
