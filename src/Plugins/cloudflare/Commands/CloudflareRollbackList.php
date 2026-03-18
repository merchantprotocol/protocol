<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflareRollbackList extends Command
{
    protected static $defaultName = 'cf:rollback:list';
    protected static $defaultDescription = 'List Cloudflare Pages deployments available for rollback';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $projectName = CloudflareHelper::projectName($repoDir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ── Cloudflare · Deployments ───────────────────────────────</>');
        $output->writeln("  <fg=gray>Project:</> <fg=white>{$projectName}</>");
        $output->writeln('');

        $output->writeln("    <fg=gray>Fetching deployments from Cloudflare...</>");
        $output->writeln('');

        $deployments = CloudflareHelper::getDeployments($projectName, null, 20);

        if (empty($deployments)) {
            $output->writeln("    <fg=yellow>No deployments found.</> Check that wrangler is authenticated and the project name is correct.");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '    <fg=white>%-4s  %-10s  %-12s  %-8s  %-20s  %s</>',
            '#', 'Short ID', 'Environment', 'Source', 'When', 'URL'
        ));
        $output->writeln('    ' . str_repeat('─', 90));

        foreach ($deployments as $i => $d) {
            $shortId = $d['short_id'] ?? substr($d['id'], 0, 8);
            $env = $d['environment'] ?? 'unknown';
            $source = $d['deployment_trigger']['metadata']['commit_hash'] ?? 'n/a';
            $source = substr($source, 0, 7);
            $when = $this->timeAgo($d['created_on'] ?? '');
            $url = $d['url'] ?? '';

            $envColor = ($env === 'production') ? 'green' : 'yellow';
            $marker = ($i === 0) ? ' <fg=green>← live</>' : '';

            $output->writeln(sprintf(
                "    <fg=gray>%-4s</>  <fg=white>%-10s</>  <fg=%s>%-12s</>  <fg=gray>%-8s</>  <fg=gray>%-20s</>  <fg=cyan>%s</>{$marker}",
                $i + 1, $shortId, $envColor, $env, $source, $when, $url
            ));
        }

        $output->writeln('');
        $output->writeln("    <fg=gray>To rollback:</> <fg=cyan>protocol cf:rollback</>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function timeAgo(string $iso): string
    {
        if (!$iso) {
            return 'unknown';
        }
        $ts = strtotime($iso);
        if (!$ts) {
            return 'unknown';
        }
        $diff = time() - $ts;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return intval($diff / 60) . 'm ago';
        if ($diff < 86400) return intval($diff / 3600) . 'h ago';
        if ($diff < 604800) return intval($diff / 86400) . 'd ago';
        return date('Y-m-d', $ts);
    }
}
