<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

class CloudflareDeploy extends Command
{
    protected static $defaultName = 'cf:deploy';
    protected static $defaultDescription = 'Verify, diff, backup, and deploy to Cloudflare Pages';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $projectName = CloudflareHelper::projectName($repoDir);
        $productionUrl = CloudflareHelper::productionUrl($repoDir);
        $helper = $this->getHelper('question');

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE PAGES</> <fg=gray>·</> <fg=yellow>Deploy</>                           <fg=cyan>│</>');
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Project:</> <fg=white>{$projectName}</>                                  <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        // ── Step 1: Verify ──────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [1/4] Verify ──────────────────────────────────────────</>');
        $output->writeln('');

        if (!is_dir($staticDir)) {
            $output->writeln("    <fg=red>FAIL:</> Static output directory does not exist");
            $output->writeln("    <fg=gray>Expected:</> <fg=white>{$staticDir}</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $fileCount = CloudflareHelper::countFiles($staticDir);
        if ($fileCount < CloudflareHelper::MIN_FILES) {
            $output->writeln("    <fg=red>FAIL:</> Only {$fileCount} files found (expected at least " . CloudflareHelper::MIN_FILES . ")");
            $output->writeln('');
            return Command::FAILURE;
        }

        // Handle 404.html
        $fourOhFour = $staticDir . '/404.html';
        if (!file_exists($fourOhFour) && file_exists($staticDir . '/404/index.html')) {
            copy($staticDir . '/404/index.html', $fourOhFour);
            $output->writeln("    <fg=green>✓</> Copied 404/index.html → 404.html");
        }

        if (!file_exists($staticDir . '/index.html')) {
            $output->writeln("    <fg=red>FAIL:</> Missing index.html");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln("    <fg=green>✓</> Verified: {$fileCount} files, key pages present");
        $output->writeln('');

        // ── Step 2: Compare / Confirm ───────────────────────────────
        $output->writeln('<fg=cyan>  ── [2/4] Review Changes ──────────────────────────────────</>');
        $output->writeln('');

        $backup = CloudflareHelper::latestBackup($repoDir);

        if (!$backup) {
            $output->writeln("    <fg=gray>No previous backup found. This appears to be the first deploy.</>");
            $output->writeln('');
            $question = new ConfirmationQuestion(
                '    Deploy without comparison? [y/<fg=green>N</>] ', false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('    <fg=gray>Deploy cancelled.</>');
                $output->writeln('');
                return Command::SUCCESS;
            }
        } else {
            // Diff against backup
            $localSums = CloudflareHelper::checksumMap($staticDir);
            $backupSums = CloudflareHelper::checksumMap($backup);

            $added = [];
            $modified = [];
            $removed = [];

            foreach ($localSums as $rel => $hash) {
                if (!isset($backupSums[$rel])) {
                    $added[] = $rel;
                } elseif ($backupSums[$rel] !== $hash) {
                    $modified[] = $rel;
                }
            }
            foreach ($backupSums as $rel => $hash) {
                if (!isset($localSums[$rel])) {
                    $removed[] = $rel;
                }
            }

            $addedCount = count($added);
            $modifiedCount = count($modified);
            $removedCount = count($removed);

            if ($addedCount === 0 && $modifiedCount === 0 && $removedCount === 0) {
                $output->writeln("    <fg=gray>No changes detected. Output is identical to last deploy.</>");
                $output->writeln('');
                $question = new ConfirmationQuestion(
                    '    Deploy anyway? [y/<fg=green>N</>] ', false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('    <fg=gray>Deploy cancelled.</>');
                    $output->writeln('');
                    return Command::SUCCESS;
                }
            } else {
                $output->writeln('    <fg=cyan>┌────────────────────────────────────────┐</>');
                $output->writeln('    <fg=cyan>│</>  <fg=white;options=bold>Deploy Change Summary</>                 <fg=cyan>│</>');
                $output->writeln('    <fg=cyan>├────────────────────────────────────────┤</>');
                $output->writeln(sprintf('    <fg=cyan>│</>  Added:    <fg=green>%-28s</><fg=cyan>│</>', "{$addedCount} files"));
                $output->writeln(sprintf('    <fg=cyan>│</>  Removed:  <fg=red>%-28s</><fg=cyan>│</>', "{$removedCount} files"));
                $output->writeln(sprintf('    <fg=cyan>│</>  Modified: <fg=yellow>%-28s</><fg=cyan>│</>', "{$modifiedCount} files"));
                $output->writeln('    <fg=cyan>└────────────────────────────────────────┘</>');
                $output->writeln('');

                if ($modifiedCount > 0) {
                    sort($modified);
                    $output->writeln('    <fg=yellow>Modified:</>');
                    foreach (array_slice($modified, 0, 20) as $f) {
                        $output->writeln("      <fg=yellow>~</> {$f}");
                    }
                    if ($modifiedCount > 20) {
                        $output->writeln("      <fg=gray>... and " . ($modifiedCount - 20) . " more</>");
                    }
                    $output->writeln('');
                }

                if ($removedCount > 0) {
                    sort($removed);
                    $output->writeln('    <fg=red>Will be removed from live site:</>');
                    foreach (array_slice($removed, 0, 20) as $f) {
                        $output->writeln("      <fg=red>-</> {$f}");
                    }
                    if ($removedCount > 20) {
                        $output->writeln("      <fg=gray>... and " . ($removedCount - 20) . " more</>");
                    }
                    $output->writeln('');

                    // Warn if removing a large percentage
                    $oldCount = count($backupSums);
                    if ($oldCount > 0) {
                        $removedPct = intval($removedCount * 100 / $oldCount);
                        if ($removedPct > 20) {
                            $output->writeln("    <fg=red;options=bold>WARNING:</> {$removedPct}% of files would be removed!");
                            $output->writeln('');
                        }
                    }
                }

                $question = new ConfirmationQuestion(
                    '    Proceed with deploy? [y/<fg=green>N</>] ', false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('    <fg=gray>Deploy cancelled.</>');
                    $output->writeln('');
                    return Command::SUCCESS;
                }
            }
        }

        $output->writeln('');

        // ── Step 3: Backup ──────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [3/4] Backup ──────────────────────────────────────────</>');
        $output->writeln('');

        $backupDir = CloudflareHelper::backupDir($repoDir);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        $timestamp = date('Ymd-His');
        $backupPath = $backupDir . '/' . CloudflareHelper::BACKUP_PREFIX . '-' . $timestamp;

        Shell::run("cp -r " . escapeshellarg($staticDir) . " " . escapeshellarg($backupPath));
        $backedUpCount = CloudflareHelper::countFiles($backupPath);
        $output->writeln("    <fg=green>✓</> Backup created ({$backedUpCount} files)");
        $output->writeln('');

        // ── Step 4: Deploy ──────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [4/4] Deploy ──────────────────────────────────────────</>');
        $output->writeln('');

        $output->writeln("    Deploying {$fileCount} files to Cloudflare Pages: <fg=white>{$projectName}</>");
        $output->writeln('');

        Shell::passthru("npx wrangler pages deploy " . escapeshellarg($staticDir) . " --project-name=" . escapeshellarg($projectName));

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌──────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Deploy complete!</>                                   <fg=cyan>│</>');
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Live at:</> <fg=white>{$productionUrl}</>                      <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └──────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
