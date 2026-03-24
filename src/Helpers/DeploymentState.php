<?php
/**
 * Unified Deployment State Manager.
 *
 * Provides a single interface for tracking deployment state across all
 * strategies (branch, release, blue-green).
 *
 * State is stored in two locations:
 *
 *   NodeConfig (~/.protocol/.node/nodes/<project>.json):
 *     deployment.strategy   - "none", "branch", "release", or "bluegreen"
 *     release.target        - version we want deployed
 *     release.active        - version that is successfully deployed
 *     release.previous      - rollback target
 *     release.releases_dir  - path to releases directory
 *     bluegreen.shadow_version - shadow build (bluegreen only)
 *
 *   Per-release deployment.json (<release_dir>/<version>/.protocol/deployment.json):
 *     watcher_pid, deployed_at, port_http, port_https, container_name, status
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;
use Gitcd\Helpers\Log;

class DeploymentState
{
    // ─── Read ────────────────────────────────────────────────────────

    /**
     * Get the current deployment strategy.
     *
     * Strategy is node-specific (stored in NodeConfig), never from protocol.json.
     * No node config means local dev = "none".
     */
    public static function strategy(string $repoDir): string
    {
        $project = self::resolveProjectName($repoDir);
        if ($project) {
            $strategy = NodeConfig::read($project, 'deployment.strategy');
            if ($strategy) {
                return $strategy;
            }
        }

        return 'none';
    }

    /**
     * Get the currently running deployment.
     *
     * @return array|null  ['version' => string, 'dir' => string, 'deployed_at' => string|null]
     */
    public static function current(string $repoDir): ?array
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return null;
        }

        $nodeData = NodeConfig::load($project);
        $strategy = $nodeData['deployment']['strategy'] ?? 'none';
        $active = $nodeData['release']['active'] ?? null;
        $releasesDir = self::resolveReleasesDir($repoDir, $nodeData);

        if ($active && $releasesDir) {
            $dir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($active) . '/';
            $deployedAt = self::readDeploymentJson($dir, 'deployed_at');
            return [
                'version' => $active,
                'dir' => $dir,
                'deployed_at' => $deployedAt,
            ];
        }

        // Branch strategy: use branch name as version
        if ($strategy === 'branch') {
            $branch = $nodeData['deployment']['branch'] ?? null;
            if ($branch && $releasesDir) {
                $dir = rtrim($releasesDir, '/') . '/' . $branch . '/';
                if (is_dir($dir)) {
                    return [
                        'version' => $branch,
                        'dir' => $dir,
                        'deployed_at' => null,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Get the previous deployment (for rollback and stop).
     *
     * @return array|null  ['version' => string, 'dir' => string, 'deployed_at' => string|null]
     */
    public static function previous(string $repoDir): ?array
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return null;
        }

        $nodeData = NodeConfig::load($project);
        $previous = $nodeData['release']['previous'] ?? null;
        $releasesDir = self::resolveReleasesDir($repoDir, $nodeData);

        if ($previous && $releasesDir) {
            $dir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($previous) . '/';
            $deployedAt = self::readDeploymentJson($dir, 'deployed_at');
            return [
                'version' => $previous,
                'dir' => $dir,
                'deployed_at' => $deployedAt,
            ];
        }

        return null;
    }

    /**
     * Get the next deployment (blue-green shadow build, or pending target).
     *
     * @return array|null  ['version' => string, 'dir' => string, 'deployed_at' => string|null]
     */
    public static function next(string $repoDir): ?array
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return null;
        }

        $nodeData = NodeConfig::load($project);
        $releasesDir = self::resolveReleasesDir($repoDir, $nodeData);
        $strategy = $nodeData['deployment']['strategy'] ?? 'none';

        // Bluegreen: shadow version
        if ($strategy === 'bluegreen') {
            $shadow = $nodeData['bluegreen']['shadow_version'] ?? null;
            if ($shadow && $releasesDir) {
                $dir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($shadow) . '/';
                return [
                    'version' => $shadow,
                    'dir' => $dir,
                    'deployed_at' => null,
                ];
            }
        }

        // Any strategy: target that hasn't been deployed yet
        $target = $nodeData['release']['target'] ?? null;
        $active = $nodeData['release']['active'] ?? null;
        if ($target && $target !== $active && $releasesDir) {
            $dir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($target) . '/';
            return [
                'version' => $target,
                'dir' => $dir,
                'deployed_at' => null,
            ];
        }

        return null;
    }

    /**
     * Get the watcher process PID from the active release's deployment.json.
     */
    public static function watcherPid(string $repoDir): ?int
    {
        $current = self::current($repoDir);
        if (!$current || empty($current['dir'])) {
            return null;
        }

        $pid = self::readDeploymentJson($current['dir'], 'watcher_pid');
        return $pid ? (int) $pid : null;
    }

    /**
     * Check if the watcher process is running.
     */
    public static function isWatcherRunning(string $repoDir): bool
    {
        $pid = self::watcherPid($repoDir);
        return $pid && Shell::isRunning($pid);
    }

    /**
     * Get the target version (what we want deployed).
     */
    public static function target(string $repoDir): ?string
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return null;
        }
        return NodeConfig::read($project, 'release.target');
    }

    /**
     * Get the secrets mode for this deployment.
     *
     * Production nodes (those with a NodeConfig entry) read exclusively
     * from NodeConfig (~/.protocol/.node/nodes/<project>.json) and never
     * fall back to the repo-level protocol.json.
     *
     * Development/staging (no NodeConfig) reads from protocol.json.
     *
     * @return string  "file", "encrypted", or "aws"
     */
    public static function secretsMode(string $repoDir): string
    {
        $project = self::resolveProjectName($repoDir);
        if ($project) {
            $mode = NodeConfig::read($project, 'deployment.secrets');
            if ($mode) {
                return $mode;
            }
        }

        return Json::read('deployment.secrets', 'file', $repoDir);
    }

    // ─── Write ───────────────────────────────────────────────────────

    /**
     * Set the current deployment. Moves existing active to previous.
     */
    public static function setCurrent(string $repoDir, string $version, string $dir): void
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return;
        }

        Log::info('deployment', "setCurrent: version={$version} project={$project}");

        NodeConfig::modify($project, function (array $nodeData) use ($version) {
            // Move active → previous
            $existingActive = $nodeData['release']['active'] ?? null;
            if ($existingActive && $existingActive !== $version) {
                $nodeData['release']['previous'] = $existingActive;
            }

            $nodeData['release']['active'] = $version;

            // Update versions list
            $versions = $nodeData['release']['versions'] ?? [];
            if (!in_array($version, $versions, true)) {
                $versions[] = $version;
                $nodeData['release']['versions'] = $versions;
            }

            return $nodeData;
        });

        // Write deployed_at + status to per-release deployment.json
        self::writeDeploymentJson($dir, [
            'deployed_at' => date('Y-m-d\TH:i:sP'),
            'status' => 'active',
        ]);
    }

    /**
     * Set the target version (what we want deployed).
     * Called immediately when watcher detects a new release.
     */
    public static function setTarget(string $repoDir, string $version): void
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return;
        }

        Log::info('deployment', "setTarget: version={$version} project={$project}");

        NodeConfig::modify($project, function (array $nodeData) use ($version) {
            $nodeData['release']['target'] = $version;
            return $nodeData;
        });
    }

    /**
     * Set the next (shadow/staging) deployment (bluegreen only).
     */
    public static function setNext(string $repoDir, string $version, string $dir): void
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return;
        }

        Log::info('deployment', "setNext: shadow_version={$version} project={$project}");

        NodeConfig::modify($project, function (array $nodeData) use ($version) {
            $nodeData['bluegreen']['shadow_version'] = $version;
            return $nodeData;
        });
    }

    /**
     * Clear the next deployment slot.
     */
    public static function clearNext(string $repoDir): void
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return;
        }

        Log::debug('deployment', "clearNext: clearing shadow_version project={$project}");

        NodeConfig::modify($project, function (array $nodeData) {
            $nodeData['bluegreen']['shadow_version'] = null;
            return $nodeData;
        });
    }

    /**
     * Promote next → current, current → previous.
     */
    public static function promoteNext(string $repoDir): bool
    {
        $next = self::next($repoDir);
        if (!$next) {
            return false;
        }

        Log::info('deployment', "promoteNext: promoting {$next['version']}");

        self::setCurrent($repoDir, $next['version'], $next['dir']);
        self::clearNext($repoDir);

        $project = self::resolveProjectName($repoDir);
        if ($project) {
            NodeConfig::modify($project, function (array $nodeData) {
                $nodeData['bluegreen']['promoted_at'] = date('Y-m-d\TH:i:sP');
                return $nodeData;
            });
        }

        return true;
    }

    /**
     * Rollback: previous → current, current → previous.
     */
    public static function rollback(string $repoDir): bool
    {
        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return false;
        }

        $nodeData = NodeConfig::load($project);
        $active = $nodeData['release']['active'] ?? null;
        $previous = $nodeData['release']['previous'] ?? null;

        if (!$previous) {
            return false;
        }

        Log::info('deployment', "rollback: {$active} → standby, {$previous} → active, project={$project}");

        NodeConfig::modify($project, function (array $nd) use ($active, $previous) {
            $nd['release']['active'] = $previous;
            $nd['release']['previous'] = $active;
            return $nd;
        });

        // Update deployment.json statuses
        $releasesDir = self::resolveReleasesDir($repoDir, $nodeData);
        if ($releasesDir) {
            if ($previous) {
                $prevDir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($previous) . '/';
                self::writeDeploymentJson($prevDir, [
                    'deployed_at' => date('Y-m-d\TH:i:sP'),
                    'status' => 'active',
                ]);
            }
            if ($active) {
                $activeDir = rtrim($releasesDir, '/') . '/' . BlueGreen::sanitizeVersion($active) . '/';
                self::writeDeploymentJson($activeDir, ['status' => 'standby']);
            }
        }

        return true;
    }

    /**
     * Set the watcher PID in the active release's deployment.json.
     */
    public static function setWatcherPid(string $repoDir, ?int $pid): void
    {
        Log::debug('deployment', "setWatcherPid: pid=" . ($pid ?? 'null'));

        $current = self::current($repoDir);
        if ($current && !empty($current['dir'])) {
            self::writeDeploymentJson($current['dir'], ['watcher_pid' => $pid]);
        }
    }

    /**
     * Set the strategy.
     */
    public static function setStrategy(string $repoDir, string $strategy): void
    {
        Log::info('deployment', "setStrategy: strategy={$strategy}");

        $project = self::resolveProjectName($repoDir);
        if ($project) {
            NodeConfig::modify($project, function (array $nodeData) use ($strategy) {
                $nodeData['deployment']['strategy'] = $strategy;
                return $nodeData;
            });
        }

        Json::write('deployment.strategy', $strategy, $repoDir);
        Json::save($repoDir);
    }

    /**
     * Set the secrets mode for this deployment.
     *
     * Writes to NodeConfig for production nodes, and always to
     * the repo-level protocol.json for dev/staging.
     *
     * @param string $mode  "file", "encrypted", or "aws"
     */
    public static function setSecretsMode(string $repoDir, string $mode): void
    {
        Log::info('deployment', "setSecretsMode: mode={$mode}");

        $project = self::resolveProjectName($repoDir);
        if ($project) {
            NodeConfig::modify($project, function (array $nodeData) use ($mode) {
                $nodeData['deployment']['secrets'] = $mode;
                return $nodeData;
            });
        }

        Json::write('deployment.secrets', $mode, $repoDir);
        Json::save($repoDir);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Get all directories that might have running containers.
     * Used by stop to ensure nothing is left behind.
     */
    public static function allKnownDirs(string $repoDir): array
    {
        // No deployment strategy — repo dir is the only dir
        $strategy = self::strategy($repoDir);
        if ($strategy === 'none') {
            $repoDir = rtrim($repoDir, '/') . '/';
            if (is_file($repoDir . 'docker-compose.yml')) {
                return [$repoDir];
            }
            return [];
        }

        $dirs = [];

        $current = self::current($repoDir);
        if ($current && !empty($current['dir'])) {
            $dirs[] = $current['dir'];
        }

        $previous = self::previous($repoDir);
        if ($previous && !empty($previous['dir'])) {
            $dirs[] = $previous['dir'];
        }

        $next = self::next($repoDir);
        if ($next && !empty($next['dir'])) {
            $dirs[] = $next['dir'];
        }

        // Also check node config fallbacks
        $project = self::resolveProjectName($repoDir);
        if ($project) {
            $nodeData = NodeConfig::load($project);
            $releasesDir = self::resolveReleasesDir($repoDir, $nodeData);

            $branch = $nodeData['deployment']['branch'] ?? null;
            if ($branch && $releasesDir) {
                $dirs[] = rtrim($releasesDir, '/') . '/' . $branch . '/';
            }

            $nodeRepoDir = $nodeData['repo_dir'] ?? null;
            if ($nodeRepoDir) {
                $dirs[] = rtrim($nodeRepoDir, '/') . '/';
            }
        }

        // Scan ALL release directories for running containers.
        $strategy = self::strategy($repoDir);
        if (in_array($strategy, ['release', 'bluegreen'], true)) {
            if (class_exists(BlueGreen::class)) {
                $releases = BlueGreen::listReleases($repoDir);
                foreach ($releases as $release) {
                    $dirs[] = BlueGreen::getReleaseDir($repoDir, $release);
                }
            }
        }

        // Deduplicate and filter to existing directories with docker-compose
        $unique = [];
        foreach (array_unique($dirs) as $dir) {
            $dir = rtrim($dir, '/') . '/';
            if (is_file($dir . 'docker-compose.yml')) {
                $unique[] = $dir;
            }
        }

        return $unique;
    }

    /**
     * Resolve the project name from a repo directory.
     */
    public static function resolveProjectName(string $repoDir): ?string
    {
        $project = NodeConfig::findByRepoDir($repoDir);
        if ($project) {
            return $project;
        }

        $match = NodeConfig::findByActiveDir($repoDir);
        if ($match) {
            return $match[0];
        }

        return null;
    }

    /**
     * Resolve the releases directory from node config or protocol.json.
     */
    private static function resolveReleasesDir(string $repoDir, array $nodeData = []): ?string
    {
        // Primary: release.releases_dir
        $dir = $nodeData['release']['releases_dir'] ?? null;
        if ($dir) {
            return rtrim($dir, '/') . '/';
        }

        // Migration fallback: bluegreen.releases_dir
        $dir = $nodeData['bluegreen']['releases_dir'] ?? null;
        if ($dir) {
            return rtrim($dir, '/') . '/';
        }

        if (class_exists(BlueGreen::class)) {
            return BlueGreen::getReleasesDir($repoDir);
        }

        return null;
    }

    /**
     * Read a value from a release's .protocol/deployment.json.
     */
    public static function readDeploymentJson(string $releaseDir, ?string $key = null)
    {
        $file = rtrim($releaseDir, '/') . '/.protocol/deployment.json';
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? null;
    }

    /**
     * Write/merge values into a release's .protocol/deployment.json.
     * Uses file locking to prevent read-modify-write races.
     */
    public static function writeDeploymentJson(string $releaseDir, array $values): void
    {
        $protocolDir = rtrim($releaseDir, '/') . '/.protocol';
        if (!is_dir($protocolDir)) {
            @mkdir($protocolDir, 0755, true);
        }

        $file = $protocolDir . '/deployment.json';
        $lockFile = $file . '.lock';

        $lockHandle = fopen($lockFile, 'c');
        if (!$lockHandle) {
            // Fallback to non-atomic write
            $existing = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
            $merged = array_merge($existing, $values);
            file_put_contents($file, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            return;
        }

        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            $existing = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
            $merged = array_merge($existing, $values);
            file_put_contents($file, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            return;
        }

        try {
            $existing = [];
            if (is_file($file)) {
                $existing = json_decode(file_get_contents($file), true) ?: [];
            }

            $merged = array_merge($existing, $values);
            file_put_contents(
                $file,
                json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    // ─── Migration ──────────────────────────────────────────────────

    /**
     * Migrate from protocol.lock to NodeConfig + deployment.json.
     *
     * Call this from protocol start or init. It's idempotent — safe to
     * call repeatedly. Does nothing if no protocol.lock exists or if
     * NodeConfig already has release.active set.
     */
    public static function migrateFromLockFile(string $repoDir): void
    {
        $lockFile = rtrim($repoDir, '/') . '/protocol.lock';
        if (!is_file($lockFile)) {
            return;
        }

        $project = self::resolveProjectName($repoDir);
        if (!$project) {
            return;
        }

        $nodeData = NodeConfig::load($project);

        // Skip if already migrated
        if (!empty($nodeData['release']['active'])) {
            // Clean up the lock file
            @unlink($lockFile);
            return;
        }

        $lockData = json_decode(file_get_contents($lockFile), true);
        if (!is_array($lockData)) {
            @unlink($lockFile);
            return;
        }

        Log::info('deployment', "migrateFromLockFile: migrating protocol.lock for project={$project}");

        // Migrate deployment state
        $current = $lockData['release']['current']
            ?? $lockData['deploy']['current']['version']
            ?? $lockData['bluegreen']['active_version']
            ?? null;
        $previous = $lockData['release']['previous']
            ?? $lockData['deploy']['previous']['version']
            ?? $lockData['bluegreen']['previous_version']
            ?? null;
        $strategy = $lockData['deploy']['strategy']
            ?? $lockData['deployment']['strategy']
            ?? $nodeData['deployment']['strategy']
            ?? 'none';

        if ($current) {
            $nodeData['release']['active'] = $current;
            $nodeData['release']['target'] = $current;
        }
        if ($previous) {
            $nodeData['release']['previous'] = $previous;
        }
        $nodeData['deployment']['strategy'] = $strategy;

        // Migrate releases_dir from bluegreen to release namespace
        if (empty($nodeData['release']['releases_dir']) && !empty($nodeData['bluegreen']['releases_dir'])) {
            $nodeData['release']['releases_dir'] = $nodeData['bluegreen']['releases_dir'];
        }
        if (empty($nodeData['release']['git_remote']) && !empty($nodeData['bluegreen']['git_remote'])) {
            $nodeData['release']['git_remote'] = $nodeData['bluegreen']['git_remote'];
        }

        // Migrate watcher PID
        $pid = $lockData['deploy']['watcher_pid']
            ?? $lockData['release']['slave']['pid']
            ?? $lockData['slave']['pid']
            ?? null;
        $nodeData['deploy']['watcher_pid'] = $pid;

        // Migrate configuration state
        if (isset($lockData['configuration'])) {
            $nodeData['configuration'] = array_merge(
                $nodeData['configuration'] ?? [],
                $lockData['configuration']
            );
        }

        NodeConfig::save($project, $nodeData);

        Log::info('deployment', "migrateFromLockFile: migrated active={$current} previous={$previous} strategy={$strategy} project={$project}");

        // Rename .env.bluegreen → .env.deployment in release dirs
        $releasesDir = $nodeData['release']['releases_dir']
            ?? $nodeData['bluegreen']['releases_dir']
            ?? null;
        if ($releasesDir && is_dir($releasesDir)) {
            foreach (glob(rtrim($releasesDir, '/') . '/*/') as $dir) {
                $oldEnv = $dir . '.env.bluegreen';
                $newEnv = $dir . '.env.deployment';
                if (is_file($oldEnv) && !is_file($newEnv)) {
                    rename($oldEnv, $newEnv);
                }

                // Create deployment.json from .env.deployment if missing
                $deployJson = $dir . '.protocol/deployment.json';
                if (!is_file($deployJson) && is_file($newEnv)) {
                    $envContent = file_get_contents($newEnv);
                    $envData = [];
                    foreach (explode("\n", $envContent) as $line) {
                        if (preg_match('/^([A-Z_]+)=(.+)$/', trim($line), $m)) {
                            $envData[strtolower($m[1])] = $m[2];
                        }
                    }
                    self::writeDeploymentJson($dir, [
                        'version' => basename(rtrim($dir, '/')),
                        'compose_project_name' => $envData['compose_project_name'] ?? null,
                        'port_http' => (int) ($envData['protocol_port_http'] ?? 80),
                        'port_https' => (int) ($envData['protocol_port_https'] ?? 443),
                        'container_name' => $envData['container_name'] ?? null,
                        'docker_hostname' => $envData['docker_hostname'] ?? null,
                        'status' => 'migrated',
                    ]);
                }
            }
        }

        // Remove the lock file
        @unlink($lockFile);
    }
}
