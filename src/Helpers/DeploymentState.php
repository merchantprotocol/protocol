<?php
/**
 * Unified Deployment State Manager.
 *
 * Provides a single interface for tracking deployment state across all
 * strategies (branch, release, blue-green). Every strategy writes the
 * same keys so that start/stop/status commands work without knowing
 * which strategy produced the state.
 *
 * State is stored in protocol.lock under the `deploy` namespace:
 *
 *   deploy.strategy      - "branch", "release", or "bluegreen"
 *   deploy.current        - { version, dir, deployed_at }
 *   deploy.previous       - { version, dir, deployed_at }
 *   deploy.next           - { version, dir, deployed_at }  (blue-green shadow)
 *   deploy.watcher_pid    - int|null
 *
 * Backward-compatible: reads old keys (release.current, bluegreen.active_version,
 * deployment.branch, slave.pid, release.slave.pid) as fallbacks.
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

class DeploymentState
{
    // ─── Read ────────────────────────────────────────────────────────

    /**
     * Get the current deployment strategy.
     */
    public static function strategy(string $repoDir): string
    {
        // New key first
        $strategy = JsonLock::read('deploy.strategy', null, $repoDir);
        if ($strategy) {
            return $strategy;
        }

        // Fall back to protocol.json
        return Json::read('deployment.strategy', 'branch', $repoDir);
    }

    /**
     * Get the currently running deployment.
     *
     * @return array|null  ['version' => string, 'dir' => string, 'deployed_at' => string|null]
     */
    public static function current(string $repoDir): ?array
    {
        // New unified key
        $current = JsonLock::read('deploy.current', null, $repoDir);
        if ($current && is_array($current) && !empty($current['version'])) {
            return $current;
        }

        // ── Backward compatibility ──

        // Blue-green: bluegreen.active_version
        $bgActive = JsonLock::read('bluegreen.active_version', null, $repoDir);
        if ($bgActive) {
            return [
                'version' => $bgActive,
                'dir' => BlueGreen::getReleaseDir($repoDir, $bgActive),
                'deployed_at' => JsonLock::read('bluegreen.promoted_at', null, $repoDir),
            ];
        }

        // Release: release.current
        $relCurrent = JsonLock::read('release.current', null, $repoDir);
        if ($relCurrent) {
            return [
                'version' => $relCurrent,
                'dir' => $repoDir,
                'deployed_at' => JsonLock::read('release.deployed_at', null, $repoDir),
            ];
        }

        // Branch: deployment.branch from node config or protocol.json
        $branch = self::resolveBranch($repoDir);
        if ($branch) {
            $releasesDir = self::resolveReleasesDir($repoDir);
            $dir = $releasesDir
                ? rtrim($releasesDir, '/') . '/' . $branch . '/'
                : $repoDir;
            // Only return if the directory actually exists
            if (is_dir($dir)) {
                return [
                    'version' => $branch,
                    'dir' => $dir,
                    'deployed_at' => null,
                ];
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
        // New unified key
        $previous = JsonLock::read('deploy.previous', null, $repoDir);
        if ($previous && is_array($previous) && !empty($previous['version'])) {
            return $previous;
        }

        // Blue-green fallback
        $bgPrevious = JsonLock::read('bluegreen.previous_version', null, $repoDir);
        if ($bgPrevious) {
            return [
                'version' => $bgPrevious,
                'dir' => BlueGreen::getReleaseDir($repoDir, $bgPrevious),
                'deployed_at' => null,
            ];
        }

        // Release fallback
        $relPrevious = JsonLock::read('release.previous', null, $repoDir);
        if ($relPrevious) {
            return [
                'version' => $relPrevious,
                'dir' => $repoDir,
                'deployed_at' => null,
            ];
        }

        return null;
    }

    /**
     * Get the next deployment (blue-green shadow build).
     *
     * @return array|null  ['version' => string, 'dir' => string, 'deployed_at' => string|null]
     */
    public static function next(string $repoDir): ?array
    {
        // New unified key
        $next = JsonLock::read('deploy.next', null, $repoDir);
        if ($next && is_array($next) && !empty($next['version'])) {
            return $next;
        }

        // Blue-green fallback
        $bgShadow = JsonLock::read('bluegreen.shadow_version', null, $repoDir);
        if ($bgShadow) {
            return [
                'version' => $bgShadow,
                'dir' => BlueGreen::getReleaseDir($repoDir, $bgShadow),
                'deployed_at' => null,
            ];
        }

        return null;
    }

    /**
     * Get the watcher process PID.
     */
    public static function watcherPid(string $repoDir): ?int
    {
        // New key
        $pid = JsonLock::read('deploy.watcher_pid', null, $repoDir);
        if ($pid) {
            return (int) $pid;
        }

        // Fallback: release.slave.pid or slave.pid
        $pid = JsonLock::read('release.slave.pid', null, $repoDir);
        if ($pid) {
            return (int) $pid;
        }

        $pid = JsonLock::read('slave.pid', null, $repoDir);
        if ($pid) {
            return (int) $pid;
        }

        return null;
    }

    /**
     * Check if the watcher process is running.
     */
    public static function isWatcherRunning(string $repoDir): bool
    {
        $pid = self::watcherPid($repoDir);
        return $pid && Shell::isRunning($pid);
    }

    // ─── Write ───────────────────────────────────────────────────────

    /**
     * Set the current deployment. Moves existing current to previous.
     */
    public static function setCurrent(string $repoDir, string $version, string $dir): void
    {
        // Move current → previous
        $existing = self::current($repoDir);
        if ($existing && $existing['version'] !== $version) {
            JsonLock::write('deploy.previous', $existing, $repoDir);

            // Legacy keys
            JsonLock::write('release.previous', $existing['version'], $repoDir);
            JsonLock::write('bluegreen.previous_version', $existing['version'], $repoDir);
        }

        $entry = [
            'version' => $version,
            'dir' => rtrim($dir, '/') . '/',
            'deployed_at' => date('Y-m-d\TH:i:sP'),
        ];
        JsonLock::write('deploy.current', $entry, $repoDir);

        // Legacy keys (dual-write for one release cycle)
        JsonLock::write('release.current', $version, $repoDir);
        JsonLock::write('release.deployed_at', $entry['deployed_at'], $repoDir);
        JsonLock::write('bluegreen.active_version', $version, $repoDir);

        // Strategy
        $strategy = Json::read('deployment.strategy', 'branch', $repoDir);
        JsonLock::write('deploy.strategy', $strategy, $repoDir);

        JsonLock::save($repoDir);
    }

    /**
     * Set the next (shadow/staging) deployment.
     */
    public static function setNext(string $repoDir, string $version, string $dir): void
    {
        $entry = [
            'version' => $version,
            'dir' => rtrim($dir, '/') . '/',
            'deployed_at' => null,
        ];
        JsonLock::write('deploy.next', $entry, $repoDir);

        // Legacy key
        JsonLock::write('bluegreen.shadow_version', $version, $repoDir);

        JsonLock::save($repoDir);
    }

    /**
     * Clear the next deployment slot.
     */
    public static function clearNext(string $repoDir): void
    {
        JsonLock::write('deploy.next', null, $repoDir);
        JsonLock::write('bluegreen.shadow_version', null, $repoDir);
        JsonLock::save($repoDir);
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

        self::setCurrent($repoDir, $next['version'], $next['dir']);
        self::clearNext($repoDir);

        JsonLock::write('bluegreen.promoted_at', date('Y-m-d\TH:i:sP'), $repoDir);
        JsonLock::save($repoDir);

        return true;
    }

    /**
     * Rollback: previous → current, current → previous.
     */
    public static function rollback(string $repoDir): bool
    {
        $previous = self::previous($repoDir);
        if (!$previous) {
            return false;
        }

        $current = self::current($repoDir);

        // Set previous as the new current
        $entry = [
            'version' => $previous['version'],
            'dir' => $previous['dir'],
            'deployed_at' => date('Y-m-d\TH:i:sP'),
        ];
        JsonLock::write('deploy.current', $entry, $repoDir);
        JsonLock::write('release.current', $previous['version'], $repoDir);
        JsonLock::write('release.deployed_at', $entry['deployed_at'], $repoDir);
        JsonLock::write('bluegreen.active_version', $previous['version'], $repoDir);

        // Set current as the new previous
        if ($current) {
            JsonLock::write('deploy.previous', $current, $repoDir);
            JsonLock::write('release.previous', $current['version'], $repoDir);
            JsonLock::write('bluegreen.previous_version', $current['version'], $repoDir);
        }

        JsonLock::save($repoDir);
        return true;
    }

    /**
     * Set the watcher PID.
     */
    public static function setWatcherPid(string $repoDir, ?int $pid): void
    {
        JsonLock::write('deploy.watcher_pid', $pid, $repoDir);

        // Legacy dual-write
        $strategy = self::strategy($repoDir);
        if ($strategy === 'branch') {
            JsonLock::write('slave.pid', $pid, $repoDir);
        } else {
            JsonLock::write('release.slave.pid', $pid, $repoDir);
        }

        JsonLock::save($repoDir);
    }

    /**
     * Set the strategy.
     */
    public static function setStrategy(string $repoDir, string $strategy): void
    {
        JsonLock::write('deploy.strategy', $strategy, $repoDir);
        Json::write('deployment.strategy', $strategy, $repoDir);
        Json::save($repoDir);
        JsonLock::save($repoDir);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Get all directories that might have running containers.
     * Used by stop to ensure nothing is left behind.
     */
    public static function allKnownDirs(string $repoDir): array
    {
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
        $projectName = NodeConfig::findByRepoDir($repoDir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repoDir);
            if ($match) {
                $projectName = $match[0];
            }
        }

        if ($projectName) {
            $nodeData = NodeConfig::load($projectName);
            $releasesDir = $nodeData['bluegreen']['releases_dir'] ?? null;

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
        // This ensures `protocol stop` finds containers regardless of which
        // strategy created them (release or bluegreen).
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
     * Resolve the current branch from node config or git.
     */
    private static function resolveBranch(string $repoDir): ?string
    {
        $projectName = NodeConfig::findByRepoDir($repoDir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repoDir);
            if ($match) {
                $projectName = $match[0];
            }
        }

        if ($projectName) {
            $nodeData = NodeConfig::load($projectName);
            $branch = $nodeData['deployment']['branch'] ?? null;
            if ($branch) {
                return $branch;
            }
        }

        // Fall back to protocol.json
        $branch = Json::read('deployment.branch', null, $repoDir);
        if ($branch) {
            return $branch;
        }

        return null;
    }

    /**
     * Resolve the releases directory from node config or protocol.json.
     */
    private static function resolveReleasesDir(string $repoDir): ?string
    {
        $projectName = NodeConfig::findByRepoDir($repoDir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repoDir);
            if ($match) {
                $projectName = $match[0];
            }
        }

        if ($projectName) {
            $nodeData = NodeConfig::load($projectName);
            $dir = $nodeData['bluegreen']['releases_dir'] ?? null;
            if ($dir) {
                return $dir;
            }
        }

        if (class_exists(BlueGreen::class)) {
            return BlueGreen::getReleasesDir($repoDir);
        }

        return null;
    }
}
