<?php
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\sulla\SullaHelper;

class SullaStop extends Command
{
    protected static $defaultName = 'sulla:stop';
    protected static $defaultDescription = 'Stop the running Sulla agent';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pidFile = SullaHelper::installDir() . 'sulla-agent.pid';

        if (!SullaHelper::isRunning()) {
            $output->writeln('');
            $output->writeln("    <fg=gray>Sulla agent is not running.</>");
            $output->writeln('');

            // Clean up stale pid file
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
            return Command::SUCCESS;
        }

        $pid = SullaHelper::getPid();
        $output->writeln('');
        $output->writeln("    <fg=gray>Stopping sulla agent (PID: {$pid})...</>");

        posix_kill($pid, SIGTERM);

        // Wait up to 5 seconds for graceful shutdown
        $waited = 0;
        while ($waited < 5 && SullaHelper::isRunning()) {
            usleep(500000); // 0.5s
            $waited += 0.5;
        }

        if (SullaHelper::isRunning()) {
            // Force kill
            posix_kill($pid, SIGKILL);
            $output->writeln("    <fg=yellow>!</> Force killed after {$waited}s");
        } else {
            $output->writeln("    <fg=green>✓</> Sulla agent stopped");
        }

        if (is_file($pidFile)) {
            unlink($pidFile);
        }

        $output->writeln('');
        return Command::SUCCESS;
    }
}
