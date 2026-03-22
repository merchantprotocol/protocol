<?php
/**
 * MCP Server — exposes Protocol CLI commands as MCP tools over stdio.
 *
 * Implements the Model Context Protocol (JSON-RPC 2.0 over stdin/stdout).
 * Sulla agent (or any MCP client) spawns this as a child process:
 *
 *   protocol mcp:serve
 *
 * The server:
 *   1. Reads newline-delimited JSON-RPC requests from stdin
 *   2. Responds with JSON-RPC responses on stdout
 *   3. Exposes every registered Protocol command as an MCP tool
 *   4. Captures command output and returns it as text content
 */
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class McpServe extends Command
{
    protected static $defaultName = 'mcp:serve';
    protected static $defaultDescription = 'Start an MCP (Model Context Protocol) server over stdio';

    /**
     * Commands that should NOT be exposed as MCP tools.
     * Interactive commands, the MCP server itself, and shell-entry commands.
     */
    private const EXCLUDED_COMMANDS = [
        'mcp:serve',        // this command
        'docker:exec',      // interactive shell
        'exec',             // alias for docker:exec
        'help',             // symfony built-in
        'list',             // symfony built-in
        'completion',       // symfony built-in
        '_complete',        // symfony built-in
        'sulla:init',       // interactive wizard
        'sulla:install',    // interactive installer
        'cf:init',          // interactive wizard
        'cf:login',         // opens browser
        'aws:init',         // interactive wizard
        'init',             // interactive wizard
        'config:init',      // interactive wizard
        'plugin:enable',    // interactive
        'plugin:disable',   // interactive
    ];

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // MCP server reads from stdin and writes to stdout.
        // All logging goes to stderr so it doesn't corrupt the JSON-RPC stream.
        $stdin = fopen('php://stdin', 'r');
        if (!$stdin) {
            fwrite(STDERR, "[MCP] Cannot open stdin\n");
            return Command::FAILURE;
        }

        // Set stdin to non-blocking would complicate things; line-buffered is fine.
        fwrite(STDERR, "[MCP] Protocol MCP server started\n");

        while (($line = fgets($stdin)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $request = json_decode($line, true);
            if (!$request || !isset($request['jsonrpc'])) {
                // Not a valid JSON-RPC message — skip
                continue;
            }

            $method = $request['method'] ?? '';
            $params = $request['params'] ?? [];
            $id = $request['id'] ?? null;

            // Notifications (no id) — just acknowledge
            if ($id === null) {
                // notifications/initialized, etc.
                continue;
            }

            switch ($method) {
                case 'initialize':
                    $this->respond($id, [
                        'protocolVersion' => '2024-11-05',
                        'capabilities'    => [
                            'tools' => new \stdClass(),
                        ],
                        'serverInfo' => [
                            'name'    => 'protocol',
                            'version' => PROTOCOL_VERSION,
                        ],
                    ]);
                    break;

                case 'tools/list':
                    $this->respond($id, [
                        'tools' => $this->buildToolList(),
                    ]);
                    break;

                case 'tools/call':
                    $toolName = $params['name'] ?? '';
                    $toolArgs = $params['arguments'] ?? [];
                    $result = $this->executeTool($toolName, $toolArgs);
                    $this->respond($id, $result);
                    break;

                default:
                    $this->respondError($id, -32601, "Method not found: {$method}");
                    break;
            }
        }

        fclose($stdin);
        fwrite(STDERR, "[MCP] Protocol MCP server stopped\n");

        return Command::SUCCESS;
    }

    /**
     * Build the MCP tools list from all registered Symfony commands.
     */
    private function buildToolList(): array
    {
        $app = $this->getApplication();
        $tools = [];

        foreach ($app->all() as $name => $command) {
            // Skip excluded and alias commands
            if (in_array($name, self::EXCLUDED_COMMANDS, true)) continue;
            if ($name !== $command->getName()) continue; // skip aliases

            $tools[] = [
                'name'        => $this->commandToToolName($name),
                'description' => $command->getDescription() ?: "Run protocol {$name}",
                'inputSchema' => $this->buildInputSchema($command),
            ];
        }

        return $tools;
    }

    /**
     * Convert a Symfony command name to an MCP tool name.
     * MCP tool names can't contain colons, so replace with underscore.
     */
    private function commandToToolName(string $commandName): string
    {
        return str_replace(':', '_', $commandName);
    }

    /**
     * Convert an MCP tool name back to a Symfony command name.
     */
    private function toolNameToCommand(string $toolName): string
    {
        return str_replace('_', ':', $toolName);
    }

    /**
     * Build a JSON Schema from a command's arguments and options.
     */
    private function buildInputSchema(Command $command): array
    {
        $properties = [];
        $required = [];

        $definition = $command->getDefinition();

        // Arguments
        foreach ($definition->getArguments() as $arg) {
            $name = $arg->getName();
            $prop = [
                'type'        => 'string',
                'description' => $arg->getDescription() ?: $name,
            ];
            if ($arg->getDefault() !== null) {
                $prop['default'] = (string) $arg->getDefault();
            }
            $properties[$name] = $prop;

            if ($arg->isRequired()) {
                $required[] = $name;
            }
        }

        // Options (only value-accepting ones, skip flags like --help, --verbose)
        foreach ($definition->getOptions() as $opt) {
            $name = $opt->getName();
            // Skip common Symfony options
            if (in_array($name, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'], true)) {
                continue;
            }

            if ($opt->acceptValue()) {
                $prop = [
                    'type'        => 'string',
                    'description' => $opt->getDescription() ?: $name,
                ];
                if ($opt->getDefault() !== null && $opt->getDefault() !== false) {
                    $prop['default'] = (string) $opt->getDefault();
                }
                $properties["--{$name}"] = $prop;
            } else {
                // Boolean flag
                $properties["--{$name}"] = [
                    'type'        => 'boolean',
                    'description' => $opt->getDescription() ?: $name,
                    'default'     => false,
                ];
            }
        }

        $schema = [
            'type'       => 'object',
            'properties' => empty($properties) ? new \stdClass() : $properties,
        ];
        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Execute a Protocol command by name, capturing its output.
     */
    private function executeTool(string $toolName, array $args): array
    {
        $commandName = $this->toolNameToCommand($toolName);
        $app = $this->getApplication();

        try {
            $command = $app->find($commandName);
        } catch (\Exception $e) {
            return [
                'content' => [['type' => 'text', 'text' => "Unknown command: {$commandName}"]],
                'isError' => true,
            ];
        }

        // Don't allow executing excluded commands
        if (in_array($commandName, self::EXCLUDED_COMMANDS, true)) {
            return [
                'content' => [['type' => 'text', 'text' => "Command '{$commandName}' is not available via MCP"]],
                'isError' => true,
            ];
        }

        // Build ArrayInput from MCP arguments
        $inputArgs = [];
        foreach ($args as $key => $value) {
            if (str_starts_with($key, '--')) {
                // Option — pass boolean flags without value
                if ($value === true || $value === 'true') {
                    $inputArgs[$key] = true;
                } elseif ($value === false || $value === 'false') {
                    // skip false flags
                } else {
                    $inputArgs[$key] = $value;
                }
            } else {
                // Positional argument
                $inputArgs[$key] = $value;
            }
        }

        try {
            $arrayInput = new ArrayInput($inputArgs);
            $arrayInput->setInteractive(false);

            $bufferedOutput = new BufferedOutput();
            $exitCode = $command->run($arrayInput, $bufferedOutput);
            $text = $bufferedOutput->fetch();

            // Strip ANSI escape codes for clean text output
            $text = preg_replace('/\033\[[0-9;]*m/', '', $text);
            $text = preg_replace('/\033\[\d*[A-Za-z]/', '', $text);

            if ($exitCode !== 0) {
                return [
                    'content' => [['type' => 'text', 'text' => $text ?: "Command failed with exit code {$exitCode}"]],
                    'isError' => true,
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => $text ?: '(no output)']],
            ];
        } catch (\Exception $e) {
            return [
                'content' => [['type' => 'text', 'text' => "Error: {$e->getMessage()}"]],
                'isError' => true,
            ];
        }
    }

    /**
     * Send a JSON-RPC success response to stdout.
     */
    private function respond($id, $result): void
    {
        $response = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ], JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, $response . "\n");
        fflush(STDOUT);
    }

    /**
     * Send a JSON-RPC error response to stdout.
     */
    private function respondError($id, int $code, string $message): void
    {
        $response = json_encode([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ], JSON_UNESCAPED_SLASHES);
        fwrite(STDOUT, $response . "\n");
        fflush(STDOUT);
    }
}
