<?php
/**
 * Standalone SOC2 compliance check command.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Soc2Check;

class Soc2CheckCommand extends Command
{
    protected static $defaultName = 'soc2:check';
    protected static $defaultDescription = 'Run SOC2 Type II compliance checks';

    protected function configure(): void
    {
        $this
            ->setHelp('Validates encrypted secrets, audit logging, release-based deployment, git integrity, reboot recovery, and key permissions against SOC2 Type II requirements.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  SOC2 Type II Compliance Check</>');
        $output->writeln('');

        $check = new Soc2Check($repo_dir);
        $check->runAll();

        $results = $check->getResults();

        $tableRows = [];
        foreach ($results as $r) {
            $statusIcon = match ($r['status']) {
                'pass' => '<fg=green>PASS</>',
                'warn' => '<fg=yellow>WARN</>',
                'fail' => '<fg=red>FAIL</>',
                default => $r['status'],
            };
            $tableRows[] = [$statusIcon, $r['name'], $r['message']];
        }

        $table = new Table($output);
        $table->setHeaders(['Status', 'Check', 'Detail']);
        $table->setRows($tableRows);
        $table->render();

        $output->writeln('');

        if ($check->passed()) {
            $output->writeln('  <fg=green>✓</> All SOC2 compliance checks passed.');
        } else {
            $output->writeln('  <fg=red>✗</> Compliance issues detected. Review the results above.');
        }

        $output->writeln('');

        return $check->passed() ? Command::SUCCESS : Command::FAILURE;
    }
}
