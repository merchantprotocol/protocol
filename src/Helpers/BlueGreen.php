<?php
/**
 * Shadow Deployment Helper (Facade).
 *
 * Manages version-named release directories for zero-downtime deployments.
 * Each release (<project>-releases/v1.2.0/) is a fully self-contained git
 * clone with its own config and Docker containers named with the release tag.
 * Only one release serves production traffic at a time. Rollback swaps to
 * the previous version's pre-built containers.
 *
 * This class is a thin facade that delegates to focused sub-classes:
 *   - BlueGreen\ReleaseState   (state management)
 *   - BlueGreen\ReleaseBuilder (file system, git, Docker, env)
 *   - BlueGreen\HealthChecker  (health check verification)
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Helpers\BlueGreen\ReleaseState;
use Gitcd\Helpers\BlueGreen\ReleaseBuilder;
use Gitcd\Helpers\BlueGreen\HealthChecker;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

class BlueGreen
{
    const PRODUCTION_HTTP = 80;
    const PRODUCTION_HTTPS = 443;
    const SHADOW_HTTP = 18080;
    const SHADOW_HTTPS = 18443;

    // ─── State management (delegated to ReleaseState) ─────────────────

    public static function isEnabled(string $repo_dir): bool
    {
        return ReleaseState::isEnabled($repo_dir);
    }

    public static function getActiveVersion(string $repo_dir): ?string
    {
        return ReleaseState::getActiveVersion($repo_dir);
    }

    public static function getPreviousVersion(string $repo_dir): ?string
    {
        return ReleaseState::getPreviousVersion($repo_dir);
    }

    public static function getShadowVersion(string $repo_dir): ?string
    {
        return ReleaseState::getShadowVersion($repo_dir);
    }

    public static function getReleaseState(string $repo_dir, string $version): array
    {
        return ReleaseState::getReleaseState($repo_dir, $version);
    }

    public static function setReleaseState(string $repo_dir, string $version, int $port, string $status): void
    {
        ReleaseState::setReleaseState($repo_dir, $version, $port, $status);
    }

    public static function setActiveVersion(string $repo_dir, string $version): void
    {
        ReleaseState::setActiveVersion($repo_dir, $version);
    }

    public static function setShadowVersion(string $repo_dir, ?string $version): void
    {
        ReleaseState::setShadowVersion($repo_dir, $version);
    }

    public static function listReleases(string $repo_dir): array
    {
        return ReleaseState::listReleases($repo_dir);
    }

    // ─── Path helpers (kept here — used by sub-classes) ───────────────

    /**
     * Sanitize a version string for use as a directory name.
     */
    public static function sanitizeVersion(string $version): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $version);
    }

    /**
     * Get the releases directory path.
     *
     * Default: sibling directory named <project>-releases/
     * Custom path can be set in protocol.json as bluegreen.releases_dir
     */
    public static function getReleasesDir(string $repo_dir): string
    {
        // Node config is the source of truth for deployment paths
        $nodeData = self::getNodeData($repo_dir);
        $custom = $nodeData['bluegreen']['releases_dir'] ?? null;

        // Fall back to protocol.json for non-slave usage
        if (!$custom) {
            $custom = Json::read('bluegreen.releases_dir', null, $repo_dir);
        }

        if ($custom) {
            if (str_starts_with($custom, '/')) {
                return rtrim($custom, '/') . '/';
            }
            return dirname(rtrim($repo_dir, '/')) . '/' . rtrim($custom, '/') . '/';
        }

        $baseName = basename(rtrim($repo_dir, '/'));
        return dirname(rtrim($repo_dir, '/')) . '/' . $baseName . '-releases/';
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
     * Get the filesystem path for a version's release directory.
     */
    public static function getReleaseDir(string $repo_dir, string $version): string
    {
        $safeName = self::sanitizeVersion($version);
        return self::getReleasesDir($repo_dir) . $safeName . '/';
    }

    /**
     * Read the container name from a release's .env.bluegreen file.
     */
    public static function getContainerName(string $releaseDir): ?string
    {
        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        if (!is_file($envFile)) {
            return null;
        }

        $content = file_get_contents($envFile);
        if (preg_match('/^CONTAINER_NAME=(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Check if a version's containers are running.
     */
    public static function isReleaseRunning(string $repo_dir, string $version): bool
    {
        $releaseDir = self::getReleaseDir($repo_dir, $version);
        $containerName = self::getContainerName($releaseDir);
        if (!$containerName) {
            return false;
        }
        return Docker::isDockerContainerRunning($containerName);
    }

    // ─── Build / setup (delegated to ReleaseBuilder) ──────────────────

    public static function getGitRemote(string $repo_dir): ?string
    {
        return ReleaseBuilder::getGitRemote($repo_dir);
    }

    public static function initReleaseDir(string $repo_dir, string $version, string $gitRemote): bool
    {
        return ReleaseBuilder::initReleaseDir($repo_dir, $version, $gitRemote);
    }

    public static function checkoutVersion(string $releaseDir, string $version): bool
    {
        return ReleaseBuilder::checkoutVersion($releaseDir, $version);
    }

    public static function writeReleaseEnv(string $releaseDir, int $httpPort, int $httpsPort, string $version): void
    {
        ReleaseBuilder::writeReleaseEnv($releaseDir, $httpPort, $httpsPort, $version);
    }

    public static function patchComposeFile(string $releaseDir): bool
    {
        return ReleaseBuilder::patchComposeFile($releaseDir);
    }

    public static function buildContainers(string $releaseDir): bool
    {
        return ReleaseBuilder::buildContainers($releaseDir);
    }

    public static function startContainers(string $releaseDir): bool
    {
        return ReleaseBuilder::startContainers($releaseDir);
    }

    public static function stopContainers(string $releaseDir): bool
    {
        return ReleaseBuilder::stopContainers($releaseDir);
    }

    public static function removeRelease(string $repo_dir, string $version): bool
    {
        return ReleaseBuilder::removeRelease($repo_dir, $version);
    }

    // ─── Health checks (delegated to HealthChecker) ───────────────────

    public static function runHealthChecks(string $repo_dir, int $httpPort, array $healthChecks, string $version = ''): bool
    {
        return HealthChecker::runHealthChecks($repo_dir, $httpPort, $healthChecks, $version);
    }

    // ─── Orchestration (stays in facade — coordinates sub-classes) ────

    /**
     * Promote a shadow version to production.
     *
     * 1. Stop the currently active containers
     * 2. Rewrite env files with production ports for the new version
     * 3. Start the new version on production ports (instant -- image pre-built)
     * 4. Update state
     *
     * Returns the promoted version string, or null on failure.
     */
    public static function promote(string $repo_dir, string $newVersion): ?string
    {
        $activeVersion = self::getActiveVersion($repo_dir);
        $newDir = self::getReleaseDir($repo_dir, $newVersion);

        // Write production ports for new version
        self::writeReleaseEnv($newDir, self::PRODUCTION_HTTP, self::PRODUCTION_HTTPS, $newVersion);

        // Start new version on production ports FIRST (zero-downtime)
        $started = self::startContainers($newDir);
        if (!$started) {
            // New version failed to start — old containers are still running, no damage done
            return null;
        }

        // New version is serving — now stop the old containers
        if ($activeVersion) {
            $activeDir = self::getReleaseDir($repo_dir, $activeVersion);
            if (is_dir($activeDir)) {
                self::stopContainers($activeDir);
                // Rewrite old version to shadow ports for standby rollback
                self::writeReleaseEnv($activeDir, self::SHADOW_HTTP, self::SHADOW_HTTPS, $activeVersion);
                // Start old version on shadow ports for instant rollback
                self::startContainers($activeDir);
                self::setReleaseState($repo_dir, $activeVersion, self::SHADOW_HTTP, 'standby');
            }
        }

        // Update state
        self::setActiveVersion($repo_dir, $newVersion);
        self::setReleaseState($repo_dir, $newVersion, self::PRODUCTION_HTTP, 'serving');
        self::setShadowVersion($repo_dir, null);
        JsonLock::write('bluegreen.promoted_at', date('Y-m-d\TH:i:sP'), $repo_dir);
        JsonLock::save($repo_dir);

        return $newVersion;
    }
}
