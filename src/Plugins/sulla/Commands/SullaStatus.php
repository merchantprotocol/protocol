<?php
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Plugins\sulla\SullaHelper;

class SullaStatus extends Command
{
    protected static $defaultName = 'sulla:status';
    protected static $defaultDescription = 'Show Sulla agent status and configuration';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>  ── </><fg=white;options=bold>Sulla Agent Status</><fg=cyan> ─────────────────────────────────────</>');
        $output->writeln('');

        // Binary
        $installed = SullaHelper::isInstalled();
        $binaryPath = SullaHelper::binaryPath();
        if ($installed) {
            $output->writeln("    <fg=green>●</> <fg=white>Installed</>    <fg=gray>{$binaryPath}</>");
        } else {
            $output->writeln("    <fg=red>●</> <fg=white>Not installed</>  <fg=gray>Run: protocol sulla:install</>");
        }

        // Process
        $running = SullaHelper::isRunning();
        $pid = SullaHelper::getPid();
        if ($running) {
            $output->writeln("    <fg=green>●</> <fg=white>Running</>      <fg=gray>PID: {$pid}</>");
        } else {
            $output->writeln("    <fg=red>●</> <fg=white>Stopped</>");
        }

        $output->writeln('');

        // Configuration
        $gatewayUrl = SullaHelper::readEnv('SULLA_GATEWAY_URL');
        $apiKey = SullaHelper::readEnv('SULLA_GATEWAY_API_KEY');
        $agentName = SullaHelper::readEnv('SULLA_AGENT_NAME');
        $mcpServers = SullaHelper::readEnv('MCP_SERVERS');

        $output->writeln("    <fg=gray>Gateway:</>   <fg=white>" . ($gatewayUrl ?: '<not set>') . "</>");

        if ($apiKey) {
            $masked = substr($apiKey, 0, 8) . '...';
            $output->writeln("    <fg=gray>API Key:</>   <fg=white>{$masked}</>");
        } else {
            $output->writeln("    <fg=gray>API Key:</>   <fg=yellow><not set></>");
        }

        $output->writeln("    <fg=gray>Agent:</>     <fg=white>" . ($agentName ?: '<not set>') . "</>");
        $output->writeln("    <fg=gray>Config:</>    <fg=white>" . SullaHelper::envPath() . "</>");

        // MCP Servers
        $output->writeln('');
        if ($mcpServers) {
            $servers = array_filter(array_map('trim', explode(',', $mcpServers)));
            $output->writeln("    <fg=gray>MCP Servers:</> <fg=white>" . count($servers) . "</>");
            foreach ($servers as $s) {
                $parts = explode('|', $s, 2);
                $name = $parts[0];
                $cmd = $parts[1] ?? '';
                $output->writeln("      <fg=green>·</> <fg=white>{$name}</> <fg=gray>→ {$cmd}</>");
            }
        } else {
            $output->writeln("    <fg=gray>MCP Servers:</> <fg=yellow>none</>");
        }

        // Log file
        $logFile = SullaHelper::installDir() . 'sulla-agent.log';
        if (is_file($logFile)) {
            $size = filesize($logFile);
            $sizeStr = $size > 1048576
                ? round($size / 1048576, 1) . ' MB'
                : round($size / 1024, 1) . ' KB';
            $output->writeln('');
            $output->writeln("    <fg=gray>Log:</>       <fg=white>{$logFile}</> <fg=gray>({$sizeStr})</>");
        }

        $output->writeln('');

        return Command::SUCCESS;
    }
}
