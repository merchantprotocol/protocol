<?php
namespace Gitcd;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\IncidentDetector;

class ProtocolApplication extends Application
{
    /**
     * Show an incident alert banner before any command runs (except dashboards).
     */
    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        // Determine which command is being run
        $commandName = $this->getCommandName($input);

        // Skip banner for dashboard/status commands and help/list to avoid noise
        $skipBanner = ['incident:status', 'incident:report', 'incident:snapshot', 'top', 'help', 'list', 'mcp:serve', ''];
        if (!in_array($commandName, $skipBanner)) {
            $this->showIncidentBanner($output);
        }

        return parent::doRun($input, $output);
    }

    private function showIncidentBanner(OutputInterface $output): void
    {
        try {
            $repoDir = Git::getGitLocalFolder();
            $issues = IncidentDetector::detect($repoDir ?: null);
            if (empty($issues)) return;

            $severity = IncidentDetector::highestSeverity($issues);
            if (!in_array($severity, ['P1', 'P2'])) return;

            $count = count($issues);
            $output->writeln('');
            $output->writeln("  <fg=white;bg=red;options=bold> ⚠  ACTIVE INCIDENT — {$severity} ({$count} issue" . ($count > 1 ? 's' : '') . " detected) </>");
            $output->writeln("  <fg=red>Run:</> <fg=yellow;options=bold>protocol incident:status</> <fg=red>for details</>");
            $output->writeln('');
        } catch (\Throwable $e) {
            // Never let the banner crash the application
        }
    }

    public function getHelp(): string
    {
        $version = $this->getVersion();

        return <<<LOGO

<fg=cyan>  ██████╗ ██████╗  ██████╗ ████████╗ ██████╗  ██████╗ ██████╗ ██╗</>
<fg=cyan>  ██╔══██╗██╔══██╗██╔═══██╗╚══██╔══╝██╔═══██╗██╔════╝██╔═══██╗██║</>
<fg=cyan>  ██████╔╝██████╔╝██║   ██║   ██║   ██║   ██║██║     ██║   ██║██║</>
<fg=cyan>  ██╔═══╝ ██╔══██╗██║   ██║   ██║   ██║   ██║██║     ██║   ██║██║</>
<fg=cyan>  ██║     ██║  ██║╚██████╔╝   ██║   ╚██████╔╝╚██████╗╚██████╔╝███████╗</>
<fg=cyan>  ╚═╝     ╚═╝  ╚═╝ ╚═════╝    ╚═╝    ╚═════╝  ╚═════╝ ╚═════╝ ╚══════╝</>

  <fg=white>Release-based deployment & infrastructure management</>
  <fg=white>for Docker applications.</>  <fg=yellow>v{$version}</>

  <fg=gray>Merchant Protocol · merchantprotocol.com</>

<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>

  <fg=yellow>Getting Started:</>

    <fg=green>protocol init</>              Set up a new or existing project
    <fg=green>protocol start</>             Start all services on this node
    <fg=green>protocol status</>            Check node health & services
    <fg=green>protocol release:create</>    Tag and publish a new release
    <fg=green>protocol deploy:push</> <fg=gray><version></>  Deploy a release to all nodes

  <fg=yellow>Migrate from v1 (branch-based):</>

    <fg=green>protocol migrate</>           Interactive migration wizard

<fg=cyan>━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━</>
LOGO;
    }
}
