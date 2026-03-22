<?php
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\sulla\SullaHelper;

class SullaStart extends Command
{
    protected static $defaultName = 'sulla:start';
    protected static $defaultDescription = 'Start the Sulla agent in the background';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!SullaHelper::isInstalled()) {
            $output->writeln('');
            $output->writeln("    <fg=red>✖</> Sulla agent is not installed");
            $output->writeln("    <fg=gray>Run:</> <fg=white>protocol sulla:install</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        if (SullaHelper::isRunning()) {
            $pid = SullaHelper::getPid();
            $output->writeln('');
            $output->writeln("    <fg=yellow>!</> Sulla agent is already running (PID: {$pid})");
            $output->writeln("    <fg=gray>Use:</> <fg=white>protocol sulla:stop</> <fg=gray>to stop it first</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $binary = SullaHelper::binaryPath();
        $installDir = SullaHelper::installDir();
        $logFile = $installDir . 'sulla-agent.log';
        $pidFile = $installDir . 'sulla-agent.pid';

        $cmd = sprintf(
            '%s > %s 2>&1 & echo $!',
            escapeshellarg($binary),
            escapeshellarg($logFile)
        );

        $pid = trim(shell_exec($cmd));

        if ($pid && is_numeric($pid)) {
            file_put_contents($pidFile, $pid, LOCK_EX);
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Sulla agent started (PID: {$pid})");
            $output->writeln("    <fg=gray>Log:</> <fg=white>{$logFile}</>");
            $output->writeln('');
        } else {
            $output->writeln('');
            $output->writeln("    <fg=red>✖</> Failed to start sulla agent");
            $output->writeln("    <fg=gray>Check log:</> <fg=white>{$logFile}</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
