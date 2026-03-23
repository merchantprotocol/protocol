<?php
/**
 * Release State Management.
 *
 * Manages version state (active, shadow, previous) stored in NodeConfig,
 * and per-release metadata in .protocol/deployment.json files.
 *
 * STRATEGY OVERVIEW
 * -----------------
 * Three deployment strategies exist. This class uses `deployment.strategy`
 * (from node config or protocol.json) to decide which features are active:
 *
 *   "none"      — No deployment strategy. Pure development mode. Containers
 *                 run in the repo directory via docker compose. No watchers,
 *                 no release directories, no deployment state tracking.
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
use Gitcd\Utils\NodeConfig;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Helpers\Log;

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
     * Returns "none", "branch", "release", or "bluegreen".
     * Node config is the source of truth; falls back to protocol.json.
     */
    public static function getStrategy(string $repo_dir): string
    {
        $nodeData = self::getNodeData($repo_dir);
        if (!empty($nodeData)) {
            return $nodeData['deployment']['strategy'] ?? 'none';
        }

        return Json::read('deployment.strategy', 'none', $repo_dir);
    }

    /**
     * Check if the strategy is "bluegreen" (shadow ports + health checks + promote).
     */
    public static function isBlueGreenStrategy(string $repo_dir): bool
    {
        return self::getStrategy($repo_dir) === 'bluegreen';
    }

    /**
     * Check if the strategy is "release" (simple one-at-a-time deployment).
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
        $project = DeploymentState::resolveProjectName($repo_dir);
        return $project ? NodeConfig::load($project) : [];
    }

    /**
     * Resolve the project name for a repo directory.
     */
    private static function resolveProjectName(string $repo_dir): ?string
    {
        return DeploymentState::resolveProjectName($repo_dir);
    }

    /**
     * Get the currently active version (serving production traffic).
     */
    public static function getActiveVersion(string $repo_dir): ?string
    {
        $project = self::resolveProjectName($repo_dir);
        if (!$project) {
            return null;
        }
        return NodeConfig::read($project, 'release.active');
    }

    /**
     * Get the previous version (available for rollback).
     */
    public static function getPreviousVersion(string $repo_dir): ?string
    {
        $project = self::resolveProjectName($repo_dir);
        if (!$project) {
            return null;
        }
        return NodeConfig::read($project, 'release.previous');
    }

    /**
     * Get the shadow version (currently building or ready to promote).
     * Bluegreen strategy only.
     */
    public static function getShadowVersion(string $repo_dir): ?string
    {
        $project = self::resolveProjectName($repo_dir);
        if (!$project) {
            return null;
        }
        return NodeConfig::read($project, 'bluegreen.shadow_version');
    }

    /**
     * Get the state of a release from its .protocol/deployment.json.
     */
    public static function getReleaseState(string $repo_dir, string $version): array
    {
        $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
        $data = DeploymentState::readDeploymentJson($releaseDir);
        return is_array($data) ? $data : [];
    }

    /**
     * Update the state of a release in its .protocol/deployment.json.
     */
    public static function setReleaseState(string $repo_dir, string $version, int $port, string $status): void
    {
        Log::info('deployment', "setReleaseState: version={$version} port={$port} status={$status}");

        $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
        DeploymentState::writeDeploymentJson($releaseDir, [
            'version' => $version,
            'port_http' => $port,
            'status' => $status,
        ]);
    }

    /**
     * Set the active version in NodeConfig.
     * Moves previous active to previous.
     */
    public static function setActiveVersion(string $repo_dir, string $version): void
    {
        $project = self::resolveProjectName($repo_dir);
        if (!$project) {
            return;
        }

        Log::info('deployment', "setActiveVersion: version={$version} project={$project}");

        NodeConfig::modify($project, function (array $nodeData) use ($version) {
            $previousVersion = $nodeData['release']['active'] ?? null;
            if ($previousVersion && $previousVersion !== $version) {
                $nodeData['release']['previous'] = $previousVersion;
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
    }

    /**
     * Set the shadow version in NodeConfig (bluegreen only).
     */
    public static function setShadowVersion(string $repo_dir, ?string $version): void
    {
        $project = self::resolveProjectName($repo_dir);
        if (!$project) {
            return;
        }

        Log::info('deployment', "setShadowVersion: version=" . ($version ?? 'null') . " project={$project}");

        NodeConfig::modify($project, function (array $nodeData) use ($version) {
            $nodeData['bluegreen']['shadow_version'] = $version;
            return $nodeData;
        });
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
