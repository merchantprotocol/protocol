<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

class CloudflareBackups extends Command
{
    protected static $defaultName = 'cf:backups';
    protected static $defaultDescription = 'List or clean up deployment backups';

    protected function configure(): void
    {
        $this->addOption('clean', null, InputOption::VALUE_NONE, 'Remove all backups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $backups = CloudflareHelper::allBackups($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Backups ───────────────────────────────────</>');
        $output->writeln('');

        if (empty($backups)) {
            $output->writeln('    <fg=gray>No backups found.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        if ($input->getOption('clean')) {
            $count = count($backups);
            $output->writeln("    Found <fg=white>{$count}</> backup(s):");
            $output->writeln('');
            foreach ($backups as $b) {
                $date = CloudflareHelper::backupDate($b);
                $files = CloudflareHelper::countFiles($b);
                $output->writeln("      <fg=white>" . basename($b) . "</>  <fg=gray>{$date}  ({$files} files)</>");
            }
            $output->writeln('');

            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                "    Remove all {$count} backup(s)? [y/<fg=green>N</>] ", false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('    <fg=gray>Cancelled.</>');
                $output->writeln('');
                return Command::SUCCESS;
            }

            foreach ($backups as $b) {
                Shell::run("rm -rf " . escapeshellarg($b));
            }
            $output->writeln("    <fg=green>✓</> Removed {$count} backup(s).");
            $output->writeln('');
            return Command::SUCCESS;
        }

        // List mode
        foreach ($backups as $b) {
            $date = CloudflareHelper::backupDate($b);
            $files = CloudflareHelper::countFiles($b);
            $output->writeln("    <fg=white>" . basename($b) . "</>  <fg=gray>{$date}  ({$files} files)</>");
        }
        $output->writeln('');
        $output->writeln("    <fg=gray>To remove all backups:</> <fg=cyan>protocol cf:backups --clean</>");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
