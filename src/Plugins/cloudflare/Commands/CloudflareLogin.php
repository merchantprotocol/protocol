<?php
namespace Gitcd\Plugins\cloudflare\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\cloudflare\CloudflareHelper;

class CloudflareLogin extends Command
{
    protected static $defaultName = 'cf:login';
    protected static $defaultDescription = 'Authenticate with Cloudflare via wrangler OAuth login';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>CLOUDFLARE</> <fg=gray>·</> <fg=yellow>Login</>                                <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        // Check current auth status
        $token = CloudflareHelper::getOAuthToken();
        if ($token) {
            $output->writeln("    <fg=gray>Existing OAuth token found — re-authenticating...</>");
        } else {
            $output->writeln("    <fg=gray>No OAuth token found — opening browser to authenticate...</>");
        }
        $output->writeln('');

        // Request all available scopes so Protocol has full access
        $scopes = [
            'account:read',
            'user:read',
            'workers:write',
            'workers_kv:write',
            'workers_routes:write',
            'workers_scripts:write',
            'workers_tail:read',
            'd1:write',
            'pages:write',
            'zone:read',
            'ssl_certs:write',
            'ai:write',
            'queues:write',
            'pipelines:write',
            'secrets_store:write',
            'offline_access',
        ];
        $scopeArgs = implode(' ', array_map(fn($s) => '--scopes ' . escapeshellarg($s), $scopes));

        $output->writeln("    <fg=gray>Requesting all available permissions...</>");
        $output->writeln('');

        // Run wrangler login interactively with all scopes
        $process = proc_open(
            "npx wrangler login {$scopeArgs}",
            [STDIN, STDOUT, STDERR],
            $pipes
        );

        if (!is_resource($process)) {
            $output->writeln("  <fg=red>✖</>  Failed to launch wrangler login");
            $output->writeln('');
            return Command::FAILURE;
        }

        $exitCode = proc_close($process);

        $output->writeln('');

        if ($exitCode === 0) {
            // Verify the new token works
            $newToken = CloudflareHelper::getOAuthToken();
            if ($newToken) {
                $output->writeln("  <fg=green>✓</>  Authenticated with Cloudflare");
            } else {
                $output->writeln("  <fg=yellow>⚠</>  wrangler login succeeded but no token found — try again");
            }
        } else {
            $output->writeln("  <fg=red>✖</>  Login failed (exit code {$exitCode})");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln('');
        return Command::SUCCESS;
    }
}
