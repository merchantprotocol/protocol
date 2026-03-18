<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflareVerify extends Command
{
    protected static $defaultName = 'cf:verify';
    protected static $defaultDescription = 'Verify static output is complete and ready to deploy';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);
        $minFiles = CloudflareHelper::minFiles($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Verify Static Output ──────────────────────</>');
        $output->writeln('');

        if (!is_dir($staticDir)) {
            $output->writeln("    <fg=red>FAIL:</> Static output directory does not exist");
            $output->writeln("    <fg=gray>Expected:</> <fg=white>{$staticDir}</>");
            $output->writeln('');
            $output->writeln("    <fg=gray>Generate static files first, then run this command again.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $count = CloudflareHelper::countFiles($staticDir);
        $errors = 0;

        if ($count < $minFiles) {
            $output->writeln("    <fg=red>FAIL:</> Only {$count} files found (expected at least {$minFiles})");
            $errors++;
        }

        // Check for 404.html — copy from 404/index.html if needed
        $fourOhFour = $staticDir . '/404.html';
        $fourOhFourIndex = $staticDir . '/404/index.html';
        if (!file_exists($fourOhFour)) {
            if (file_exists($fourOhFourIndex)) {
                copy($fourOhFourIndex, $fourOhFour);
                $output->writeln("    <fg=green>✓</> Copied 404/index.html → 404.html");
            } else {
                $output->writeln("    <fg=yellow>!</> No 404.html found — consider generating one");
            }
        }

        // Check for index.html
        if (!file_exists($staticDir . '/index.html')) {
            $output->writeln("    <fg=red>FAIL:</> Missing index.html");
            $errors++;
        }

        if ($errors > 0) {
            $output->writeln('');
            $output->writeln("    <fg=red>Verification failed with {$errors} error(s).</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln("    <fg=green>✓</> Verified: {$count} files, key pages present");
        $output->writeln('');
        return Command::SUCCESS;
    }
}
