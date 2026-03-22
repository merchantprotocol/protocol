<?php
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\sulla\SullaHelper;
use Gitcd\Commands\Init\DotMenuTrait;

class SullaInit extends Command
{
    use DotMenuTrait;

    protected static $defaultName = 'sulla:init';
    protected static $defaultDescription = 'Setup wizard — configure Enterprise Gateway and MCP servers';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $totalSteps = 3;

        $this->writeBanner($output);

        // ── Check binary ────────────────────────────────────────────
        if (!SullaHelper::isInstalled()) {
            $output->writeln("    <fg=red>✖</> Sulla agent is not installed");
            $output->writeln("    <fg=gray>Run:</> <fg=white>protocol sulla:install</> <fg=gray>first</>");
            $output->writeln('');
            return Command::FAILURE;
        }

        $output->writeln("    <fg=green>✓</> Binary: <fg=gray>" . SullaHelper::binaryPath() . "</>");
        $output->writeln('');

        // ── Step 1: Enterprise Gateway ──────────────────────────────
        $this->writeStep($output, 1, $totalSteps, 'Enterprise Gateway');

        $output->writeln("    <fg=gray>Connect Sulla to your Merchant Protocol Enterprise Gateway.</>");
        $output->writeln("    <fg=gray>You can find your Gateway URL and create an API key in the</>");
        $output->writeln("    <fg=gray>gateway dashboard under API Keys.</>");
        $output->writeln('');

        // Gateway URL
        $existingUrl = SullaHelper::readEnv('SULLA_GATEWAY_URL');
        $defaultUrl = $existingUrl ?: 'ws://localhost:8081/ws/agent';
        $question = new Question(
            "    Gateway URL [<fg=green>{$defaultUrl}</>]: ",
            $defaultUrl
        );
        $gatewayUrl = $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> URL: <fg=white>{$gatewayUrl}</>");
        $output->writeln('');

        // API Key
        $existingKey = SullaHelper::readEnv('SULLA_GATEWAY_API_KEY');
        $maskedKey = $existingKey ? substr($existingKey, 0, 8) . '...' : '';
        $prompt = $maskedKey
            ? "    API Key [<fg=green>{$maskedKey}</>]: "
            : "    API Key: ";
        $question = new Question($prompt, $existingKey);
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $apiKey = $helper->ask($input, $output, $question);

        if (!$apiKey) {
            $output->writeln("    <fg=yellow>!</> No API key entered — the agent won't be able to connect");
        } else {
            $display = substr($apiKey, 0, 8) . '...';
            $output->writeln("    <fg=green>✓</> Key: <fg=white>{$display}</>");
        }
        $output->writeln('');

        // Agent name
        $existingName = SullaHelper::readEnv('SULLA_AGENT_NAME');
        $defaultName = $existingName ?: gethostname() . '-sulla';
        $question = new Question(
            "    Agent Name [<fg=green>{$defaultName}</>]: ",
            $defaultName
        );
        $agentName = $helper->ask($input, $output, $question);
        $output->writeln("    <fg=green>✓</> Name: <fg=white>{$agentName}</>");

        // ── Step 2: MCP Servers ─────────────────────────────────────
        $this->writeStep($output, 2, $totalSteps, 'MCP Servers');

        $output->writeln("    <fg=gray>MCP (Model Context Protocol) servers give Sulla access to</>");
        $output->writeln("    <fg=gray>external tools like file systems, GitHub, databases, etc.</>");
        $output->writeln('');

        // Protocol MCP — auto-configure since this plugin runs inside protocol
        $protocolBin = realpath(WEBROOT_DIR . 'protocol');

        $existingMcp = SullaHelper::readEnv('MCP_SERVERS');
        $mcpServers = $existingMcp;

        if ($protocolBin && is_executable($protocolBin)) {
            $output->writeln("    <fg=green>✓</> Protocol CLI detected: <fg=gray>{$protocolBin}</>");

            // Check if protocol is already in MCP_SERVERS
            $hasProtocol = $existingMcp && str_contains($existingMcp, 'protocol|');

            if (!$hasProtocol) {
                $output->writeln('');
                $question = new ConfirmationQuestion(
                    '    Add Protocol CLI as an MCP server? [<fg=green>Y</>/n] ', true
                );
                if ($helper->ask($input, $output, $question)) {
                    $protocolMcp = "protocol|{$protocolBin} mcp:serve";
                    $mcpServers = $existingMcp
                        ? "{$existingMcp},{$protocolMcp}"
                        : $protocolMcp;
                    $output->writeln("    <fg=green>✓</> Protocol MCP server added");
                }
            } else {
                $output->writeln("    <fg=green>✓</> Protocol MCP already configured");
            }
        } else {
            $output->writeln("    <fg=yellow>!</> Protocol CLI not found — skipping auto-configuration");
            $output->writeln("    <fg=gray>You can add MCP servers manually by editing the .env file</>");
        }

        $output->writeln('');

        // Additional MCP servers
        $question = new ConfirmationQuestion(
            '    Add additional MCP servers? [y/<fg=green>N</>] ', false
        );
        if ($helper->ask($input, $output, $question)) {
            $output->writeln('');
            $output->writeln("    <fg=gray>Format: name|command arg1 arg2</>");
            $output->writeln("    <fg=gray>Example: filesystem|npx -y @modelcontextprotocol/server-filesystem /tmp</>");
            $output->writeln("    <fg=gray>Enter blank line when done.</>");
            $output->writeln('');

            while (true) {
                $question = new Question("    MCP server: ", '');
                $entry = trim($helper->ask($input, $output, $question));
                if (!$entry) break;

                // Validate format
                if (!str_contains($entry, '|')) {
                    $output->writeln("    <fg=red>✖</> Invalid format — must be name|command");
                    continue;
                }

                $mcpServers = $mcpServers ? "{$mcpServers},{$entry}" : $entry;
                $name = explode('|', $entry)[0];
                $output->writeln("    <fg=green>✓</> Added: <fg=white>{$name}</>");

                // Ask for env vars for this server
                $question = new ConfirmationQuestion(
                    "    Does <fg=white>{$name}</> need environment variables? [y/<fg=green>N</>] ", false
                );
                if ($helper->ask($input, $output, $question)) {
                    $output->writeln("    <fg=gray>Enter as VARNAME=value (blank to stop):</>");
                    while (true) {
                        $envQuestion = new Question("    MCP_{$name}_", '');
                        $envEntry = trim($helper->ask($input, $output, $envQuestion));
                        if (!$envEntry) break;

                        $parts = explode('=', $envEntry, 2);
                        if (count($parts) === 2) {
                            SullaHelper::writeEnv("MCP_{$name}_{$parts[0]}", $parts[1]);
                            $output->writeln("    <fg=green>✓</> Set MCP_{$name}_{$parts[0]}");
                        }
                    }
                }
                $output->writeln('');
            }
        }

        // ── Step 3: Save & Verify ───────────────────────────────────
        $this->writeStep($output, 3, $totalSteps, 'Save & Verify');

        // Write .env
        SullaHelper::writeEnv('SULLA_GATEWAY_URL', $gatewayUrl);
        SullaHelper::writeEnv('SULLA_GATEWAY_API_KEY', $apiKey ?: '');
        SullaHelper::writeEnv('SULLA_AGENT_NAME', $agentName);
        SullaHelper::writeEnv('MCP_SERVERS', $mcpServers ?: '');

        $output->writeln("    <fg=green>✓</> Configuration saved to <fg=gray>" . SullaHelper::envPath() . "</>");
        $output->writeln('');

        // Test gateway connection
        $output->writeln("    <fg=gray>Testing gateway connection...</>");

        if ($apiKey && $gatewayUrl) {
            // Convert ws:// to http:// for a quick health check
            $httpUrl = str_replace(['ws://', 'wss://'], ['http://', 'https://'], $gatewayUrl);
            $httpUrl = preg_replace('#/ws/agent$#', '/api/status', $httpUrl);

            $context = stream_context_create([
                'http' => [
                    'header'  => "Authorization: Bearer {$apiKey}\r\n",
                    'timeout' => 5,
                ],
            ]);
            $status = @file_get_contents($httpUrl, false, $context);
            if ($status) {
                $output->writeln("    <fg=green>✓</> Gateway is reachable");
            } else {
                $output->writeln("    <fg=yellow>!</> Could not reach gateway — check URL and API key");
            }
        } else {
            $output->writeln("    <fg=yellow>!</> Skipped — no API key configured");
        }

        // ── Completion ──────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Sulla Agent Configured!</>                           <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>Next steps:</>                                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>1.</> <fg=white>protocol sulla:start</>     <fg=gray>Start the agent</>      <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>2.</> <fg=white>protocol sulla:status</>    <fg=gray>Check agent status</>   <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
        $output->writeln("    <fg=gray>Gateway:</>  <fg=white>{$gatewayUrl}</>");
        $output->writeln("    <fg=gray>Agent:</>    <fg=white>{$agentName}</>");
        $output->writeln("    <fg=gray>MCP:</>      <fg=white>" . ($mcpServers ?: 'none') . "</>");
        $output->writeln("    <fg=gray>Config:</>   <fg=white>" . SullaHelper::envPath() . "</>");
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function writeBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>SULLA AGENT</> <fg=gray>·</> <fg=yellow>Setup Wizard</>                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Configure the Sulla AI agent for this machine</>       <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $this->writeBanner($output);
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }
}
