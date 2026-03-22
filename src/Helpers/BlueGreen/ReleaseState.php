<?php
/**
 * Release State Management.
 *
 * Manages version state (active, shadow, previous) stored in protocol.lock,
 * and queries release metadata from protocol.json.
 *
 * STRATEGY OVERVIEW
 * -----------------
 * Three deployment strategies exist. This class uses `deployment.strategy`
 * (from node config or protocol.json) to decide which features are active:
 *
 *   "branch"    — Legacy git-polling. In-place checkout + rebuild in repo_dir.
 *                 Does NOT use release directories or BlueGreen infrastructure.
 *
 *   "release"   — Simple tag-based deployment. Clones each tag into its own
 *                 release directory (releases_dir/vX.Y.Z/). Only ONE container
 *                 runs at a time on production ports (80/443). No shadow ports,
 *                 no health checks, no dual containers. Stop old, start new.
 *
 *   "bluegreen" — Full zero-downtime deployment. Builds new version on shadow
 *                 ports (18080-18280), runs health checks, then promotes by
 *                 swapping to production ports. Old version stays on standby
 *                 for instant rollback. Two containers may run simultaneously.
 *
 * Both "release" and "bluegreen" use release directories and the shared
 * BlueGreen infrastructure (clone, patch, env files). The key difference is
 * whether shadow ports and health checks are used.
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers\BlueGreen;

use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;
use Gitcd\Helpers\BlueGreen;

class ReleaseState
{
    /**
     * Check if release-directory-based deployment is enabled.
     *
     * Returns true for BOTH "release" and "bluegreen" strategies, since both
     * use the shared release directory infrastructure (clone, patch, env files).
     * Returns false for "branch" strategy (legacy in-place deployment).
     *
     * @see isBlueGreenStrategy() — for bluegreen-only checks (shadow ports, health checks)
     * @see isReleaseStrategy()   — for release-only checks (single container, production ports)
     */
    public static function isEnabled(string $repo_dir): bool
    {
        $strategy = self::getStrategy($repo_dir);
        return in_array($strategy, ['release', 'bluegreen'], true);
    }

    /**
     * Get the deployment strategy for a repo.
     *
     * Returns "branch", "release", or "bluegreen".
     * Node config is the source of truth; falls back to protocol.json.
     */
    public static function getStrategy(string $repo_dir): string
    {
        $nodeData = self::getNodeData($repo_dir);
        if (!empty($nodeData)) {
            return $nodeData['deployment']['strategy'] ?? 'branch';
        }

        return Json::read('deployment.strategy', 'branch', $repo_dir);
    }

    /**
     * Check if the strategy is "bluegreen" (shadow ports + health checks + promote).
     *
     * Use this to gate bluegreen-specific behavior: shadow port allocation,
     * health check verification, promote/rollback with dual containers.
     */
    public static function isBlueGreenStrategy(string $repo_dir): bool
    {
        return self::getStrategy($repo_dir) === 'bluegreen';
    }

    /**
     * Check if the strategy is "release" (simple one-at-a-time deployment).
     *
     * Use this to gate release-specific behavior: direct production ports,
     * stop-old-then-start-new, no shadow ports or health checks.
     */
    public static function isReleaseStrategy(string $repo_dir): bool
    {
        return self::getStrategy($repo_dir) === 'release';
    }

    /**
     * Look up node config data for a given repo directory.
     */
    private static function getNodeData(string $repo_dir): array
    {
        $projectName = NodeConfig::findByRepoDir($repo_dir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repo_dir);
            if ($match) {
                $projectName = $match[0];
            }
        }
        return $projectName ? NodeConfig::load($projectName) : [];
    }

    /**
     * Get the currently active version (serving production traffic).
     */
    public static function getActiveVersion(string $repo_dir): ?string
    {
        return JsonLock::read('bluegreen.active_version', null, $repo_dir);
    }

    /**
     * Get the previous version (available for rollback).
     */
    public static function getPreviousVersion(string $repo_dir): ?string
    {
        return JsonLock::read('bluegreen.previous_version', null, $repo_dir);
    }

    /**
     * Get the shadow version (currently building or ready to promote).
     */
    public static function getShadowVersion(string $repo_dir): ?string
    {
        return JsonLock::read('bluegreen.shadow_version', null, $repo_dir);
    }

    /**
     * Get the state of a release from protocol.lock.
     */
    public static function getReleaseState(string $repo_dir, string $version): array
    {
        $key = 'bluegreen.releases.' . BlueGreen::sanitizeVersion($version);
        return JsonLock::read($key, [], $repo_dir) ?: [];
    }

    /**
     * Update the state of a release in protocol.lock.
     */
    public static function setReleaseState(string $repo_dir, string $version, int $port, string $status): void
    {
        $key = 'bluegreen.releases.' . BlueGreen::sanitizeVersion($version);
        JsonLock::write("{$key}.version", $version, $repo_dir);
        JsonLock::write("{$key}.port", $port, $repo_dir);
        JsonLock::write("{$key}.status", $status, $repo_dir);
        JsonLock::save($repo_dir);
    }

    /**
     * Set the active version in protocol.lock.
     */
    public static function setActiveVersion(string $repo_dir, string $version): void
    {
        $previousVersion = self::getActiveVersion($repo_dir);
        if ($previousVersion && $previousVersion !== $version) {
            JsonLock::write('bluegreen.previous_version', $previousVersion, $repo_dir);
        }
        JsonLock::write('bluegreen.active_version', $version, $repo_dir);
        JsonLock::save($repo_dir);
    }

    /**
     * Set the shadow version in protocol.lock.
     */
    public static function setShadowVersion(string $repo_dir, ?string $version): void
    {
        JsonLock::write('bluegreen.shadow_version', $version, $repo_dir);
        JsonLock::save($repo_dir);
    }

    /**
     * List all version releases on disk.
     */
    public static function listReleases(string $repo_dir): array
    {
        $releasesBase = BlueGreen::getReleasesDir($repo_dir);
        if (!is_dir($releasesBase)) {
            return [];
        }

        $dirs = glob(rtrim($releasesBase, '/') . '/*', GLOB_ONLYDIR);
        $releases = [];
        foreach ($dirs as $dir) {
            $releases[] = basename($dir);
        }
        return $releases;
    }
}
