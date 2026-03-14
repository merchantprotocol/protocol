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
    protected static $defaultDescription = 'Compare local static files against last deployed backup';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $projectName = CloudflareHelper::projectName($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln("  <fg=white;options=bold>Cloudflare Pages · Static Output Status</>");
        $output->writeln("  <fg=gray>Project:</> <fg=white>{$projectName}</>");
        $output->writeln('<fg=cyan>  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>');
        $output->writeln('');

        if (!is_dir($staticDir) || CloudflareHelper::countFiles($staticDir) === 0) {
            $output->writeln("    <fg=yellow>No static output directory found.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $localCount = CloudflareHelper::countFiles($staticDir);
        $output->writeln("    Local files:   <fg=white>{$localCount}</>");

        $backup = CloudflareHelper::latestBackup($repoDir);
        if (!$backup) {
            $output->writeln("    Last backup:   <fg=gray>none found</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>Run</> <fg=cyan>protocol cf:deploy</> <fg=gray>to create a backup and deploy.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $backupCount = CloudflareHelper::countFiles($backup);
        $backupDate = CloudflareHelper::backupDate($backup);
        $output->writeln("    Backup files:  <fg=white>{$backupCount}</> <fg=gray>(deployed {$backupDate})</>");
        $output->writeln('');

        // Build checksum maps and compare
        $localSums = CloudflareHelper::checksumMap($staticDir);
        $backupSums = CloudflareHelper::checksumMap($backup);

        $added = [];
        $modified = [];
        $deleted = [];

        foreach ($localSums as $rel => $hash) {
            if (!isset($backupSums[$rel])) {
                $added[] = $rel;
            } elseif ($backupSums[$rel] !== $hash) {
                $modified[] = $rel;
            }
        }
        foreach ($backupSums as $rel => $hash) {
            if (!isset($localSums[$rel])) {
                $deleted[] = $rel;
            }
        }

        $addedCount = count($added);
        $modifiedCount = count($modified);
        $deletedCount = count($deleted);

        if ($addedCount === 0 && $modifiedCount === 0 && $deletedCount === 0) {
            $output->writeln("    <fg=green>Local files match the last deployed backup. No changes.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('    <fg=cyan>┌────────────────────────────────────────┐</>');
        $output->writeln('    <fg=cyan>│</>  <fg=white;options=bold>Changes Since Last Deploy</>             <fg=cyan>│</>');
        $output->writeln('    <fg=cyan>├────────────────────────────────────────┤</>');
        $output->writeln(sprintf('    <fg=cyan>│</>  Added:    <fg=green>%-28s</><fg=cyan>│</>', "{$addedCount} files"));
        $output->writeln(sprintf('    <fg=cyan>│</>  Modified: <fg=yellow>%-28s</><fg=cyan>│</>', "{$modifiedCount} files"));
        $output->writeln(sprintf('    <fg=cyan>│</>  Deleted:  <fg=red>%-28s</><fg=cyan>│</>', "{$deletedCount} files"));
        $output->writeln('    <fg=cyan>└────────────────────────────────────────┘</>');
        $output->writeln('');

        if ($modifiedCount > 0) {
            sort($modified);
            $output->writeln('    <fg=yellow>Modified files:</>');
            foreach (array_slice($modified, 0, 15) as $f) {
                $output->writeln("      <fg=yellow>~</> {$f}");
            }
            if ($modifiedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($modifiedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

        if ($addedCount > 0) {
            sort($added);
            $output->writeln('    <fg=green>New files:</>');
            foreach (array_slice($added, 0, 15) as $f) {
                $output->writeln("      <fg=green>+</> {$f}");
            }
            if ($addedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($addedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

        if ($deletedCount > 0) {
            sort($deleted);
            $output->writeln('    <fg=red>Deleted files:</>');
            foreach (array_slice($deleted, 0, 15) as $f) {
                $output->writeln("      <fg=red>-</> {$f}");
            }
            if ($deletedCount > 15) {
                $output->writeln("      <fg=gray>... and " . ($deletedCount - 15) . " more</>");
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
