<?php
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;

class Lifecycle
{
    /**
     * Run post_start lifecycle hooks from protocol.json.
     *
     * Command format:
     *   "exec:<service> <command>" — runs inside the named compose service
     *   "<command>"                — runs on the host
     */
    public static function runPostStart(string $repoDir, ?callable $logger = null, ?string $envFile = null, string $hookKey = 'lifecycle.post_start'): void
    {
        $hooks = Json::read($hookKey, [], $repoDir);
        if (!is_array($hooks) || empty($hooks)) {
            return;
        }

        $dockerCommand = Docker::getDockerCommand();

        foreach ($hooks as $i => $hook) {
            $hook = trim($hook);
            if ($hook === '') {
                continue;
            }

            if (str_starts_with($hook, 'exec:')) {
                // Parse "exec:<service> <command>"
                $rest = substr($hook, 5);
                $spacePos = strpos($rest, ' ');
                if ($spacePos === false) {
                    if ($logger) $logger("[hook {$i}] Invalid exec format (no command): {$hook}");
                    continue;
                }
                $service = substr($rest, 0, $spacePos);
                $command = substr($rest, $spacePos + 1);

                $envFlag = '';
                if ($envFile && is_file($envFile)) {
                    $envFlag = ' --env-file ' . escapeshellarg($envFile);
                }

                $cmd = "cd " . escapeshellarg(rtrim($repoDir, '/'))
                    . " && {$dockerCommand}{$envFlag} exec -T "
                    . escapeshellarg($service) . " " . $command . " 2>&1";

                if ($logger) $logger("[hook {$i}] exec:{$service} {$command}");
                Shell::run($cmd);
            } else {
                // Host command
                if ($logger) $logger("[hook {$i}] host: {$hook}");
                Shell::run("cd " . escapeshellarg(rtrim($repoDir, '/')) . " && {$hook} 2>&1");
            }
        }
    }
}
