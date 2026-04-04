<?php
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;

class Lifecycle
{
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
        if (!is_array($hooks) || empty($hooks)) {
            return;
        }

        foreach ($hooks as $i => $hook) {
            $hook = trim($hook);
            if ($hook === '') {
                continue;
            }

            // All hooks run inside the active container via protocol exec
            $cmd = "protocol exec -T -d " . escapeshellarg(rtrim($repoDir, '/'))
                . " " . escapeshellarg($hook) . " 2>&1";

            if ($logger) $logger("[hook {$i}] exec: {$hook}");
            Shell::run($cmd);
        }
    }
}
