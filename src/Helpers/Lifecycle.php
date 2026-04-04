<?php
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\ContainerName;

class Lifecycle
{
    /** Maximum total wait time for container readiness (seconds). */
    public const MAX_WAIT = 120;

    /**
     * Wait for the container to be running using Fibonacci backoff.
     *
     * Sequence: 1, 1, 2, 3, 5, 8, 13, 21, 34, 55 …
     * Gives up after MAX_WAIT seconds total elapsed.
     *
     * @return bool true if container became ready, false on timeout
     */
    public static function waitForContainer(string $repoDir, ?callable $logger = null): bool
    {
        $containerName = ContainerName::resolveFromDir($repoDir);
        if (!$containerName) {
            // Fallback: grab names from compose file
            $names = Docker::getContainerNamesFromDockerComposeFile($repoDir);
            $containerName = $names[0] ?? null;
        }

        if (!$containerName) {
            if ($logger) $logger("No container name resolved — skipping readiness wait");
            return true;
        }

        if ($logger) $logger("Waiting for container '{$containerName}' to be ready (max " . self::MAX_WAIT . "s)…");

        $elapsed = 0;
        $a = 1;
        $b = 1;

        while ($elapsed < self::MAX_WAIT) {
            if (Docker::isDockerContainerRunning($containerName)) {
                if ($logger) $logger("Container '{$containerName}' is running after {$elapsed}s");
                return true;
            }

            $delay = min($a, self::MAX_WAIT - $elapsed);
            if ($delay <= 0) {
                break;
            }

            if ($logger) $logger("Container not ready, retrying in {$delay}s (elapsed {$elapsed}s)…");
            sleep($delay);
            $elapsed += $delay;

            // Fibonacci step
            $next = $a + $b;
            $a = $b;
            $b = $next;
        }

        // Final check after the last sleep
        if (Docker::isDockerContainerRunning($containerName)) {
            if ($logger) $logger("Container '{$containerName}' is running after {$elapsed}s");
            return true;
        }

        if ($logger) $logger("Container '{$containerName}' not ready after {$elapsed}s — giving up");
        return false;
    }

    /**
     * Run post_start lifecycle hooks from protocol.json.
     *
     * Every hook command is executed inside the active container via
     * `protocol exec -T` which uses ContainerName::resolveActive() to
     * find the correct running container regardless of deployment strategy.
     *
     * Hook format in protocol.json:
     *   "lifecycle": {
     *     "post_start": [
     *       "npm install --production",
     *       "php artisan migrate --force"
     *     ]
     *   }
     */
    public static function runPostStart(string $repoDir, ?callable $logger = null, ?string $envFile = null, string $hookKey = 'lifecycle.post_start'): void
    {
        $hooks = Json::read($hookKey, [], $repoDir);

        if ($logger) {
            $logger("hookKey={$hookKey} repoDir={$repoDir}");
            $logger("raw hooks value: " . json_encode($hooks));
        }

        if (!is_array($hooks) || empty($hooks)) {
            if ($logger) $logger("No hooks to run (empty or not an array)");
            return;
        }

        // Log container resolution so we can see what exec will target
        $containerName = ContainerName::resolveActive($repoDir);
        if ($logger) {
            $logger("resolveActive container=" . ($containerName ?: '(none)'));
            if (!$containerName) {
                $fallbackNames = Docker::getContainerNamesFromDockerComposeFile($repoDir);
                $logger("fallback compose containers=" . json_encode($fallbackNames));
            }
            $isRunning = $containerName ? Docker::isDockerContainerRunning($containerName) : false;
            $logger("container running=" . ($isRunning ? 'yes' : 'no'));
        }

        foreach ($hooks as $i => $hook) {
            $hook = trim($hook);
            if ($hook === '') {
                if ($logger) $logger("[hook {$i}] skipped (empty string)");
                continue;
            }

            // All hooks run inside the active container via protocol exec
            $cmd = "protocol exec -T -d " . escapeshellarg(rtrim($repoDir, '/'))
                . " " . escapeshellarg($hook) . " 2>&1";

            if ($logger) $logger("[hook {$i}] cmd: {$cmd}");

            $output = Shell::run($cmd, $exitCode);

            if ($logger) {
                $logger("[hook {$i}] exit_code={$exitCode}");
                if ($output !== null && trim($output) !== '') {
                    // Log output line by line to keep log readable
                    foreach (explode("\n", trim($output)) as $line) {
                        $logger("[hook {$i}] output: {$line}");
                    }
                } else {
                    $logger("[hook {$i}] output: (empty)");
                }
            }

            if ($exitCode !== 0 && $logger) {
                $logger("[hook {$i}] WARNING: hook exited with non-zero code {$exitCode}");
            }
        }

        if ($logger) $logger("All {$hookKey} hooks complete");
    }
}
