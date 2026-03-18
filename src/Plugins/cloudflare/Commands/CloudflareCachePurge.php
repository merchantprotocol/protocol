<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;
use Gitcd\Helpers\Git;

class CloudflareCachePurge extends Command
{
    protected static $defaultName = 'cf:cache-purge';
    protected static $defaultDescription = 'Purge the Cloudflare CDN cache for specific URLs or the entire zone';

    protected function configure(): void
    {
        $this
            ->addArgument('urls', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Specific URLs to purge (omit to purge everything)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Purge the entire cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        $productionUrl = CloudflareHelper::productionUrl($repoDir);
        $urls = $input->getArgument('urls');
        $purgeAll = $input->getOption('all');

        // Extract the root domain from the production URL for zone lookup
        $host = parse_url($productionUrl, PHP_URL_HOST);
        if (!$host) {
            $output->writeln('');
            $output->writeln("  <fg=red>✖</>  Cannot determine domain from production_url: {$productionUrl}");
            $output->writeln('');
            return Command::FAILURE;
        }

        // Get the root domain (e.g. merchantprotocol.com from protocol.merchantprotocol.com)
        $parts = explode('.', $host);
        $rootDomain = count($parts) > 2
            ? implode('.', array_slice($parts, -2))
            : $host;

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln("<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE</> <fg=gray>·</> <fg=yellow>Cache Purge</>                           <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Domain:</> <fg=white>{$rootDomain}</>                                  <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        // Look up zone ID
        $output->writeln("    <fg=gray>Looking up zone ID for {$rootDomain}...</>");
        $zoneId = CloudflareHelper::getZoneId($rootDomain);

        if (!$zoneId) {
            $output->writeln('');
            $output->writeln("  <fg=red>✖</>  Could not find Cloudflare zone for {$rootDomain}");
            $output->writeln("      <fg=gray>Ensure your OAuth token has Zone:Read permission.</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln("    <fg=green>✓</> Zone found: <fg=gray>{$zoneId}</>");
        $output->writeln('');

        if (!empty($urls)) {
            // Purge specific URLs
            $output->writeln("    <fg=white>Purging " . count($urls) . " URL(s):</>");
            foreach ($urls as $url) {
                $output->writeln("      <fg=gray>→</> {$url}");
            }
            $output->writeln('');

            $result = CloudflareHelper::purgeCache($zoneId, $urls);
        } elseif ($purgeAll) {
            // Purge everything
            $output->writeln("    <fg=yellow>Purging entire cache for {$rootDomain}...</>");
            $output->writeln('');

            $result = CloudflareHelper::purgeCache($zoneId, null);
        } else {
            $output->writeln("  <fg=yellow>⚠</>  Specify URLs to purge, or use <fg=white>--all</> to purge everything.");
            $output->writeln('');
            $output->writeln("    <fg=gray>Examples:</>");
            $output->writeln("      <fg=white>protocol cf:cache-purge {$productionUrl}/install.sh</>");
            $output->writeln("      <fg=white>protocol cf:cache-purge --all</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        if ($result && ($result['success'] ?? false)) {
            $output->writeln("  <fg=green>✓</>  Cache purged successfully!");
        } else {
            $errorMsg = 'unknown error';
            if ($result && !empty($result['errors'])) {
                $errorMsg = $result['errors'][0]['message'] ?? $errorMsg;
            }
            $output->writeln("  <fg=red>✖</>  Cache purge failed: {$errorMsg}");
            $output->writeln('');
            if (stripos($errorMsg, 'auth') !== false) {
                $output->writeln("      <fg=gray>The wrangler OAuth token lacks Cache Purge permission.</>");
                $output->writeln("      <fg=gray>Create an API token at: Cloudflare Dashboard → My Profile → API Tokens</>");
                $output->writeln("      <fg=gray>Required permission: Zone → Cache Purge → Edit</>");
                $output->writeln('');
                $output->writeln("      <fg=white>export CLOUDFLARE_API_TOKEN=\"your-token-here\"</>");
                $output->writeln("      <fg=white>protocol cf:cache-purge ...</>");
                $output->writeln('');
            }
            return Command::FAILURE;
        }

        $output->writeln('');
        return Command::SUCCESS;
    }
}
