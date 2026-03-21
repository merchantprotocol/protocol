<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

class CloudflareDeploy extends Command
{
    protected static $defaultName = 'cf:deploy';
    protected static $defaultDescription = 'Prepare, verify, review, and deploy to Cloudflare Pages';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without making changes or deploying');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $projectName = CloudflareHelper::projectName($repoDir);
        $productionUrl = CloudflareHelper::productionUrl($repoDir);
        $helper = $this->getHelper('question');
        $dryRun = $input->getOption('dry-run');

        $output->writeln('');
        $modeLabel = $dryRun ? 'Deploy (dry run)' : 'Deploy';
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln("<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE PAGES</> <fg=gray>·</> <fg=yellow>{$modeLabel}</>                      <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Project:</> <fg=white>{$projectName}</>                                  <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        // ── Step 1: Prepare ─────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [1/4] Prepare ─────────────────────────────────────────</>');
        $output->writeln('');

        $prepareCommand = $this->getApplication()->find('cf:prepare');
        $prepareArgs = $dryRun ? new ArrayInput(['--dry-run' => true]) : new ArrayInput([]);
        $prepareResult = $prepareCommand->run($prepareArgs, $output);
        if ($prepareResult !== Command::SUCCESS) {
            return Command::FAILURE;
        }

        // ── Step 2: Verify ──────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [2/4] Verify ──────────────────────────────────────────</>');
        $output->writeln('');

        if (!is_dir($staticDir)) {
            $output->writeln("    <fg=red>FAIL:</> Static output directory does not exist");
            $output->writeln("    <fg=gray>Expected:</> <fg=white>{$staticDir}</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $fileCount = CloudflareHelper::countFiles($staticDir);
        $minFiles = CloudflareHelper::minFiles($repoDir);
        if ($fileCount < $minFiles) {
            $output->writeln("    <fg=red>FAIL:</> Only {$fileCount} files found (expected at least {$minFiles})");
            $output->writeln('');
            return Command::FAILURE;
        }

        if (!file_exists($staticDir . '/index.html')) {
            $output->writeln("    <fg=red>FAIL:</> Missing index.html");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln("    <fg=green>✓</> Verified: {$fileCount} files, key pages present");
        $output->writeln('');

        // ── Step 3: Review Changes (compare against Cloudflare) ─────
        $output->writeln('<fg=cyan>  ── [3/4] Review Changes ──────────────────────────────────</>');
        $output->writeln('');

        $output->writeln("    <fg=gray>Comparing local files against live deployment...</>");

        $localSums = CloudflareHelper::checksumMap($staticDir);

        $accountId = CloudflareHelper::getAccountId();
        if (!$accountId) {
            $output->writeln("    <fg=yellow>!</> Could not authenticate with Cloudflare API (auto-refresh failed).");
            $output->writeln("    <fg=gray>Run:</> <fg=white>npx wrangler login</> <fg=gray>if the refresh token has also expired.</>");
            $output->writeln("    <fg=gray>Check ~/.protocol/.node/cloudflare-deploy.log for details.</>");
            $output->writeln('');
            if (!$dryRun) {
                $question = new ConfirmationQuestion(
                    '    Deploy without comparison? [y/<fg=green>N</>] ', false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('    <fg=gray>Deploy cancelled.</>');
                    $output->writeln('');
                    return Command::FAILURE;
                }
            }
            $deployedSums = [];
        } else {
            $deployedSums = CloudflareHelper::getLatestDeployedFiles($projectName, $accountId);
        }

        if (empty($deployedSums)) {
            $output->writeln("    <fg=gray>No previous deployment found on Cloudflare. This appears to be the first deploy.</>");
            $output->writeln('');
            if (!$dryRun) {
                $question = new ConfirmationQuestion(
                    '    Deploy without comparison? [y/<fg=green>N</>] ', false
                );
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('    <fg=gray>Deploy cancelled.</>');
                    $output->writeln('');
                    return Command::SUCCESS;
                }
            }
        } else {
            $diff = CloudflareHelper::diffAgainstDeployed($localSums, $deployedSums);
            $added = $diff['added'];
            $modified = $diff['modified'];
            $removed = $diff['removed'];

            $addedCount = count($added);
            $modifiedCount = count($modified);
            $removedCount = count($removed);

            if ($addedCount === 0 && $modifiedCount === 0 && $removedCount === 0) {
                $output->writeln('');
                $output->writeln("    <fg=gray>No changes detected. Local files match what's deployed on Cloudflare.</>");
                $output->writeln('');
                if (!$dryRun) {
                    $question = new ConfirmationQuestion(
                        '    Deploy anyway? [y/<fg=green>N</>] ', false
                    );
                    if (!$helper->ask($input, $output, $question)) {
                        $output->writeln('    <fg=gray>Deploy cancelled.</>');
                        $output->writeln('');
                        return Command::SUCCESS;
                    }
                }
            } else {
                $output->writeln('');
                $output->writeln('    <fg=cyan>┌────────────────────────────────────────┐</>');
                $output->writeln('    <fg=cyan>│</>  <fg=white;options=bold>Deploy Change Summary</>                 <fg=cyan>│</>');
                $output->writeln('    <fg=cyan>├────────────────────────────────────────┤</>');
                $output->writeln(sprintf('    <fg=cyan>│</>  Added:      <fg=green>%-26s</><fg=cyan>│</>', "{$addedCount} files"));
                $output->writeln(sprintf('    <fg=cyan>│</>  Modified:   <fg=yellow>%-26s</><fg=cyan>│</>', "{$modifiedCount} files"));
                $output->writeln(sprintf('    <fg=cyan>│</>  Untouched:  <fg=gray>%-26s</><fg=cyan>│</>', "{$removedCount} files"));
                $output->writeln('    <fg=cyan>└────────────────────────────────────────┘</>');
                $output->writeln('');

                if ($addedCount > 0) {
                    sort($added);
                    $output->writeln('    <fg=green>Added:</>');
                    foreach (array_slice($added, 0, 20) as $f) {
                        $output->writeln("      <fg=green>+</> {$f}");
                    }
                    if ($addedCount > 20) {
                        $output->writeln("      <fg=gray>... and " . ($addedCount - 20) . " more</>");
                    }
                    $output->writeln('');
                }

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
                    $output->writeln('    <fg=gray>Untouched (remain in project pool):</>');
                    foreach (array_slice($removed, 0, 5) as $f) {
                        $output->writeln("      <fg=gray>·</> {$f}");
                    }
                    if ($removedCount > 5) {
                        $output->writeln("      <fg=gray>... and " . ($removedCount - 5) . " more</>");
                    }
                    $output->writeln('');
                }

                if (!$dryRun) {
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
        }

        $output->writeln('');

        // ── Step 4: Deploy ──────────────────────────────────────────
        $output->writeln('<fg=cyan>  ── [4/4] Deploy ──────────────────────────────────────────</>');
        $output->writeln('');

        if ($dryRun) {
            $output->writeln("    <fg=yellow>→</> Would deploy {$fileCount} files to Cloudflare Pages: <fg=white>{$projectName}</>");
            $output->writeln('');
            $output->writeln('<fg=cyan>  ┌──────────────────────────────────────────────────────────┐</>');
            $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=yellow;options=bold>Dry run complete</> — no changes were made              <fg=cyan>│</>');
            $output->writeln("<fg=cyan>  │</>   <fg=gray>Target:</> <fg=white>{$productionUrl}</>                       <fg=cyan>│</>");
            $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  └──────────────────────────────────────────────────────────┘</>');
            $output->writeln('');
        } else {
            $output->writeln("    Deploying {$fileCount} files to Cloudflare Pages: <fg=white>{$projectName}</>");
            $output->writeln('');

            Shell::passthru("npx wrangler pages deploy " . escapeshellarg($staticDir) . " --project-name=" . escapeshellarg($projectName));

            $output->writeln('');
            $output->writeln('<fg=cyan>  ┌──────────────────────────────────────────────────────────┐</>');
            $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Deploy complete!</>                                   <fg=cyan>│</>');
            $output->writeln("<fg=cyan>  │</>   <fg=gray>Live at:</> <fg=white>{$productionUrl}</>                      <fg=cyan>│</>");
            $output->writeln("<fg=cyan>  │</>   <fg=gray>Rollback:</> <fg=white>protocol cf:rollback</>                  <fg=cyan>│</>");
            $output->writeln('<fg=cyan>  │</>                                                          <fg=cyan>│</>');
            $output->writeln('<fg=cyan>  └──────────────────────────────────────────────────────────┘</>');
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
