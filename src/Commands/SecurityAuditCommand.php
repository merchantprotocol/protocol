<?php
/**
 * Standalone security audit command.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\SecurityAudit;
use Gitcd\Utils\NodeConfig;

class SecurityAuditCommand extends Command
{
    protected static $defaultName = 'security:audit';
    protected static $defaultDescription = 'Run a security audit against the codebase and server';

    protected function configure(): void
    {
        $this
            ->setHelp('Scans for malicious code patterns, checks file permissions, audits dependencies, inspects Docker configuration, and flags suspicious processes.')
            ->addArgument('project', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));

        $projectArg = $input->getArgument('project');
        $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        if ($resolved) {
            [, , $activeDir] = $resolved;
            $repo_dir = $activeDir;
        }

        if (!$resolved) {
            Git::checkInitializedRepo($output, $repo_dir);
        }

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  Security Audit</>');
        $output->writeln('');

        $audit = new SecurityAudit($repo_dir);
        $audit->runAll();

        $results = $audit->getResults();

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

        if ($audit->passed()) {
            $output->writeln('  <fg=green>✓</> All security checks passed.');
        } else {
            $output->writeln('  <fg=red>✗</> Security issues detected. Review the results above.');
        }

        $output->writeln('');

        return $audit->passed() ? Command::SUCCESS : Command::FAILURE;
    }
}
