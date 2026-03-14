<?php
/**
 * Schedule or remove automated Docker cleanup via cron.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Crontab;

class DockerCleanupSchedule extends Command
{
    protected static $defaultName = 'docker:cleanup:schedule';
    protected static $defaultDescription = 'Enable or disable scheduled Docker cleanup via cron';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Enable automated Docker cleanup on a cron schedule.

            Usage:
              protocol docker:cleanup:schedule on                  Enable daily cleanup at 3am
              protocol docker:cleanup:schedule on --cron="0 */6 * * *"  Every 6 hours
              protocol docker:cleanup:schedule off                 Disable scheduled cleanup
              protocol docker:cleanup:schedule status              Check if scheduled
            HELP)
            ->addArgument('action', InputArgument::REQUIRED, '"on", "off", or "status"')
            ->addOption('cron', null, InputOption::VALUE_OPTIONAL, 'Cron schedule expression', '0 3 * * *')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower(trim($input->getArgument('action')));
        $schedule = $input->getOption('cron');
        $repo_dir = Dir::realpath($input->getOption('dir'));

        $output->writeln('');

        switch ($action) {
            case 'on':
            case 'enable':
                Crontab::addDockerCleanup($repo_dir, $schedule);

                if (Crontab::hasDockerCleanup($repo_dir)) {
                    $output->writeln("  <fg=green>✓</> Docker cleanup scheduled: <fg=white>{$schedule}</>");
                    $output->writeln("  <fg=gray>Runs:</> <fg=white>protocol docker:cleanup</>");
                    $output->writeln('');
                    $output->writeln("  <fg=gray>To also prune volumes, edit the cron to add \"full\":</>");
                    $output->writeln("  <fg=gray>  crontab -e</>");
                } else {
                    $output->writeln("  <fg=red>✗</> Failed to install cron job.");
                    return Command::FAILURE;
                }
                break;

            case 'off':
            case 'disable':
                if (!Crontab::hasDockerCleanup($repo_dir)) {
                    $output->writeln("  <fg=gray>No scheduled Docker cleanup found. Nothing to remove.</>");
                } else {
                    Crontab::removeDockerCleanup($repo_dir);
                    $output->writeln("  <fg=green>✓</> Docker cleanup schedule removed.");
                }
                break;

            case 'status':
                if (Crontab::hasDockerCleanup($repo_dir)) {
                    $output->writeln("  <fg=green>●</> Docker cleanup is <fg=green>scheduled</>");
                } else {
                    $output->writeln("  <fg=gray>●</> Docker cleanup is <fg=yellow>not scheduled</>");
                    $output->writeln("  <fg=gray>Enable with:</> <fg=white>protocol docker:cleanup:schedule on</>");
                }
                break;

            default:
                $output->writeln("  <fg=red>Unknown action \"{$action}\".</> Use \"on\", \"off\", or \"status\".");
                return Command::FAILURE;
        }

        $output->writeln('');
        return Command::SUCCESS;
    }
}
