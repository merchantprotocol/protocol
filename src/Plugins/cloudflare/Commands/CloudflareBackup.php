<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

class CloudflareBackup extends Command
{
    protected static $defaultName = 'cf:backup';
    protected static $defaultDescription = 'Create a backup of current static output';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $staticDir = CloudflareHelper::staticDir($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Backup ────────────────────────────────────</>');
        $output->writeln('');

        if (!is_dir($staticDir) || CloudflareHelper::countFiles($staticDir) === 0) {
            $output->writeln("    <fg=red>Nothing to back up — static output is empty or missing.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $backupDir = CloudflareHelper::backupDir($repoDir);
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Ymd-His');
        $backupPath = $backupDir . '/' . CloudflareHelper::BACKUP_PREFIX . '-' . $timestamp;

        Shell::run("cp -r " . escapeshellarg($staticDir) . " " . escapeshellarg($backupPath));

        $fileCount = CloudflareHelper::countFiles($backupPath);
        $output->writeln("    <fg=green>✓</> Backup created: <fg=white>" . basename($backupPath) . "</> ({$fileCount} files)");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
