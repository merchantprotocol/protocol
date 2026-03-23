<?php
/**
 * Release Directory Deployment Helper (Facade).
 *
 * Shared infrastructure for both "release" and "bluegreen" deployment
 * strategies. Both strategies clone version-tagged releases into dedicated
 * directories (<project>-releases/v1.2.0/), patch container names with
 * version suffixes, and generate per-release .env.bluegreen files.
 *
 * STRATEGY DIFFERENCES
 * --------------------
 * "release"   — Simple one-at-a-time deployment. Clones tag, patches compose,
 *               starts on production ports (80/443). Stops old container first.
 *               No shadow ports. No health checks. No dual containers.
 *
 * "bluegreen" — Zero-downtime deployment. Builds on shadow ports (18080-18280),
 *               runs health checks, then promotes by swapping to production
 *               ports. Old version stays on standby for instant rollback.
 *               Two containers may run simultaneously during swap.
 *
 * Use isEnabled() to check if EITHER strategy is active (shared infra).
 * Use isBlueGreenStrategy() to check for bluegreen-only behavior.
 * Use isReleaseStrategy() to check for release-only behavior.
 *
 * This class is a thin facade that delegates to focused sub-classes:
 *   - BlueGreen\ReleaseState   (state management, strategy detection)
 *   - BlueGreen\ReleaseBuilder (file system, git, Docker, env)
 *   - BlueGreen\HealthChecker  (health check verification — bluegreen only)
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Helpers\BlueGreen\ReleaseState;
use Gitcd\Helpers\BlueGreen\ReleaseBuilder;
use Gitcd\Helpers\BlueGreen\HealthChecker;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Log;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

class BlueGreen
{
    const PRODUCTION_HTTP = 80;
    const PRODUCTION_HTTPS = 443;
    const SHADOW_PORT_RANGE_START = 18080;
    const SHADOW_PORT_RANGE_END = 18280;

    /**
     * Find an available pair of ports (HTTP, HTTPS) for a shadow deploy.
     * Scans from SHADOW_PORT_RANGE_START in steps of 2 until a free pair is found.
     *
     * @return array{int, int} [httpPort, httpsPort]
     */
    public static function findAvailableShadowPorts(): array
    {
        for ($http = self::SHADOW_PORT_RANGE_START; $http < self::SHADOW_PORT_RANGE_END; $http += 2) {
            $https = $http + 1;
            $httpInUse = Shell::run("ss -tlnp 'sport = :{$http}' 2>/dev/null | tail -n +2");
            $httpsInUse = Shell::run("ss -tlnp 'sport = :{$https}' 2>/dev/null | tail -n +2");
            if (empty(trim($httpInUse)) && empty(trim($httpsInUse))) {
                Log::debug('bluegreen', "found available shadow ports: {$http}/{$https}");
                return [$http, $https];
            }
        }
        // Fallback if all ports are exhausted
        Log::warn('bluegreen', "all shadow ports exhausted (18080-18280), falling back to " . self::SHADOW_PORT_RANGE_START);
        return [self::SHADOW_PORT_RANGE_START, self::SHADOW_PORT_RANGE_START + 1];
    }

    // ─── Strategy detection (delegated to ReleaseState) ────────────────

    /**
     * Check if release-directory-based deployment is enabled.
     * True for both "release" and "bluegreen" strategies.
     */
    public static function isEnabled(string $repo_dir): bool
    {
        return ReleaseState::isEnabled($repo_dir);
    }

    /**
     * Get the deployment strategy: "branch", "release", or "bluegreen".
     */
    public static function getStrategy(string $repo_dir): string
    {
        return ReleaseState::getStrategy($repo_dir);
    }

    /**
     * True only for "bluegreen" strategy (shadow ports + health checks).
     */
    public static function isBlueGreenStrategy(string $repo_dir): bool
    {
        return ReleaseState::isBlueGreenStrategy($repo_dir);
    }

    /**
     * True only for "release" strategy (simple one-at-a-time, production ports).
     */
    public static function isReleaseStrategy(string $repo_dir): bool
    {
        return ReleaseState::isReleaseStrategy($repo_dir);
    }

    // ─── State management (delegated to ReleaseState) ─────────────────

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

    public static function buildContainers(string $releaseDir, ?string &$output = null): bool
    {
        return ReleaseBuilder::buildContainers($releaseDir, $output);
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
     * Promote a shadow version to production (BLUEGREEN STRATEGY ONLY).
     *
     * This method is specific to the "bluegreen" strategy where a shadow
     * version has been pre-built and health-checked on shadow ports. It:
     *
     * 1. Rewrites env files with production ports for the new version
     * 2. Starts the new version on production ports (instant — image pre-built)
     * 3. Stops the old version and restarts it on shadow ports for standby rollback
     * 4. Updates state
     *
     * For the "release" strategy, use the simpler flow in release-watcher.php:
     * stop old → writeReleaseEnv with production ports → startContainers.
     *
     * Returns the promoted version string, or null on failure.
     */
    public static function promote(string $repo_dir, string $newVersion): ?string
    {
        $activeVersion = self::getActiveVersion($repo_dir);
        $newDir = self::getReleaseDir($repo_dir, $newVersion);

        Log::context('bluegreen', [
            'action'         => 'promote',
            'new_version'    => $newVersion,
            'active_version' => $activeVersion ?: 'none',
            'new_dir'        => $newDir,
        ]);

        // Write production ports for new version
        self::writeReleaseEnv($newDir, self::PRODUCTION_HTTP, self::PRODUCTION_HTTPS, $newVersion);

        // Start new version on production ports FIRST (zero-downtime)
        $started = self::startContainers($newDir);
        if (!$started) {
            Log::error('bluegreen', "promote aborted: new version {$newVersion} failed to start on production ports");
            return null;
        }

        // New version is serving — now stop the old containers
        if ($activeVersion) {
            $activeDir = self::getReleaseDir($repo_dir, $activeVersion);
            if (is_dir($activeDir)) {
                self::stopContainers($activeDir);
                // Rewrite old version to shadow ports for standby rollback
                [$standbyHttp, $standbyHttps] = self::findAvailableShadowPorts();
                self::writeReleaseEnv($activeDir, $standbyHttp, $standbyHttps, $activeVersion);
                // Start old version on shadow ports for instant rollback
                self::startContainers($activeDir);
                self::setReleaseState($repo_dir, $activeVersion, $standbyHttp, 'standby');
                Log::info('bluegreen', "old version {$activeVersion} moved to standby on port {$standbyHttp}");
            }
        }

        // Update state
        self::setActiveVersion($repo_dir, $newVersion);
        self::setReleaseState($repo_dir, $newVersion, self::PRODUCTION_HTTP, 'serving');
        self::setShadowVersion($repo_dir, null);
        JsonLock::write('bluegreen.promoted_at', date('Y-m-d\TH:i:sP'), $repo_dir);
        JsonLock::save($repo_dir);

        Log::info('bluegreen', "promote complete: {$newVersion} is now serving on production ports");
        return $newVersion;
    }
}
