<?php
/**
 * Shadow Deployment Helper.
 *
 * Manages version-named release directories for zero-downtime deployments.
 * Each release (<project>-releases/v1.2.0/) is a fully self-contained git
 * clone with its own config and Docker containers named with the release tag.
 * Only one release serves production traffic at a time. Rollback swaps to
 * the previous version's pre-built containers.
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

class BlueGreen
{
    const PRODUCTION_HTTP = 80;
    const PRODUCTION_HTTPS = 443;
    const SHADOW_HTTP = 8080;
    const SHADOW_HTTPS = 8443;

    /**
     * Check if shadow deployment is enabled in protocol.json.
     */
    public static function isEnabled(string $repo_dir): bool
    {
        return (bool) Json::read('bluegreen.enabled', false, $repo_dir);
    }

    /**
     * Get the releases directory path.
     *
     * Default: sibling directory named <project>-releases/
     * Custom path can be set in protocol.json as bluegreen.releases_dir
     */
    public static function getReleasesDir(string $repo_dir): string
    {
        $custom = Json::read('bluegreen.releases_dir', null, $repo_dir);
        if ($custom) {
            // Absolute path
            if (str_starts_with($custom, '/')) {
                return rtrim($custom, '/') . '/';
            }
            // Relative path — resolve from repo_dir's parent
            return dirname(rtrim($repo_dir, '/')) . '/' . rtrim($custom, '/') . '/';
        }

        // Default: <project>-releases/ as sibling
        $baseName = basename(rtrim($repo_dir, '/'));
        return dirname(rtrim($repo_dir, '/')) . '/' . $baseName . '-releases/';
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
     * Sanitize a version string for use as a directory name.
     */
    public static function sanitizeVersion(string $version): string
    {
        return preg_replace('/[^a-zA-Z0-9._-]/', '_', $version);
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
     * Get the state of a release from protocol.lock.
     */
    public static function getReleaseState(string $repo_dir, string $version): array
    {
        $key = 'bluegreen.releases.' . self::sanitizeVersion($version);
        return JsonLock::read($key, [], $repo_dir) ?: [];
    }

    /**
     * Update the state of a release in protocol.lock.
     */
    public static function setReleaseState(string $repo_dir, string $version, int $port, string $status): void
    {
        $key = 'bluegreen.releases.' . self::sanitizeVersion($version);
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
     * Get the git remote URL for cloning releases.
     * Uses bluegreen.git_remote from protocol.json, falls back to the repo's own remote.
     */
    public static function getGitRemote(string $repo_dir): ?string
    {
        $configured = Json::read('bluegreen.git_remote', null, $repo_dir);
        if ($configured) {
            return $configured;
        }
        return Git::RemoteUrl($repo_dir);
    }

    /**
     * Initialize a release directory with a git clone.
     */
    public static function initReleaseDir(string $repo_dir, string $version, string $gitRemote): bool
    {
        $releaseDir = self::getReleaseDir($repo_dir, $version);
        $releasesBase = self::getReleasesDir($repo_dir);

        if (!is_dir($releasesBase)) {
            Shell::run("mkdir -p " . escapeshellarg(rtrim($releasesBase, '/')));
        }

        if (is_dir($releaseDir . '.git')) {
            return true;
        }

        // Remove any partial directory
        if (is_dir($releaseDir)) {
            Shell::run("rm -rf " . escapeshellarg(rtrim($releaseDir, '/')));
        }

        $result = Shell::run(
            "git clone " . escapeshellarg($gitRemote) . " " . escapeshellarg(rtrim($releaseDir, '/')) . " 2>&1",
            $returnVar
        );

        return $returnVar === 0;
    }

    /**
     * Checkout a specific version (tag) in a release directory.
     */
    public static function checkoutVersion(string $releaseDir, string $version): bool
    {
        $remote = Git::remoteName($releaseDir) ?: 'origin';

        Shell::run("git -C " . escapeshellarg(rtrim($releaseDir, '/')) . " fetch {$remote} --tags 2>/dev/null");

        $result = Shell::run(
            "git -C " . escapeshellarg(rtrim($releaseDir, '/')) . " checkout " . escapeshellarg($version) . " 2>&1",
            $returnVar
        );

        return $returnVar === 0;
    }

    /**
     * Write the .env file for a release with port configuration.
     */
    public static function writeReleaseEnv(string $releaseDir, int $httpPort, int $httpsPort, string $version): void
    {
        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $safeName = self::sanitizeVersion($version);
        $baseName = 'app';

        // Try to read container name from the release's own protocol.json
        $releaseProtocolJson = rtrim($releaseDir, '/') . '/protocol.json';
        if (is_file($releaseProtocolJson)) {
            $raw = json_decode(file_get_contents($releaseProtocolJson), true);
            if (!empty($raw['docker']['container_name'])) {
                $baseName = $raw['docker']['container_name'];
            }
        }

        $containerName = $baseName . '-' . $safeName;

        $content = "# Shadow deployment port configuration (auto-generated)\n";
        $content .= "COMPOSE_PROJECT_NAME=protocol-{$safeName}\n";
        $content .= "PROTOCOL_PORT_HTTP={$httpPort}\n";
        $content .= "PROTOCOL_PORT_HTTPS={$httpsPort}\n";
        $content .= "DOCKER_HOSTNAME={$containerName}\n";
        $content .= "CONTAINER_NAME={$containerName}\n";

        file_put_contents($envFile, $content);
    }

    /**
     * Patch the docker-compose.yml in a release to use parameterized ports.
     */
    public static function patchComposeFile(string $releaseDir): bool
    {
        $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        $content = file_get_contents($composePath);

        // Already patched?
        if (strpos($content, 'PROTOCOL_PORT_HTTP') !== false) {
            return true;
        }

        $content = preg_replace(
            '/"80:80"/',
            '"${PROTOCOL_PORT_HTTP:-80}:80"',
            $content
        );

        $content = preg_replace(
            '/"443:443"/',
            '"${PROTOCOL_PORT_HTTPS:-443}:443"',
            $content
        );

        // Parameterize container_name
        if (preg_match('/container_name:\s*(\S+)/', $content, $m)) {
            $originalName = $m[1];
            $content = preg_replace(
                '/container_name:\s*' . preg_quote($originalName, '/') . '/',
                'container_name: ${CONTAINER_NAME:-' . $originalName . '}',
                $content
            );
        }

        file_put_contents($composePath, $content);
        return true;
    }

    /**
     * Build containers for a release (slow operation).
     */
    public static function buildContainers(string $releaseDir): bool
    {
        $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $dockerCommand = Docker::getDockerCommand();

        $content = file_get_contents($composePath);
        $usesBuild = (bool) preg_match('/^\s+build:/m', $content);

        if ($usesBuild) {
            Shell::run("{$dockerCommand} -f " . escapeshellarg($composePath) . " --env-file " . escapeshellarg($envFile) . " build 2>&1");
        } else {
            // Read image from release's own protocol.json
            $image = null;
            $releaseProtocolJson = rtrim($releaseDir, '/') . '/protocol.json';
            if (is_file($releaseProtocolJson)) {
                $raw = json_decode(file_get_contents($releaseProtocolJson), true);
                $image = $raw['docker']['image'] ?? null;
            }
            if ($image) {
                Shell::run("docker pull " . escapeshellarg($image) . " 2>&1");
            }
        }

        $result = Shell::run(
            "cd " . escapeshellarg(rtrim($releaseDir, '/')) . " && {$dockerCommand} --env-file " . escapeshellarg($envFile) . " up --build -d 2>&1",
            $returnVar
        );

        // Run composer install if needed
        if (file_exists(rtrim($releaseDir, '/') . '/composer.json')) {
            $containerName = self::getContainerName($releaseDir);
            if ($containerName) {
                Shell::run("docker exec " . escapeshellarg($containerName) . " composer install --no-interaction 2>&1");
            }
        }

        return $returnVar === 0;
    }

    /**
     * Start containers for a release (fast — image already built).
     */
    public static function startContainers(string $releaseDir): bool
    {
        $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $dockerCommand = Docker::getDockerCommand();

        $result = Shell::run(
            "cd " . escapeshellarg(rtrim($releaseDir, '/')) . " && {$dockerCommand} --env-file " . escapeshellarg($envFile) . " up -d 2>&1",
            $returnVar
        );

        return $returnVar === 0;
    }

    /**
     * Stop containers for a release.
     */
    public static function stopContainers(string $releaseDir): bool
    {
        $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $dockerCommand = Docker::getDockerCommand();
        $envFlag = file_exists($envFile) ? " --env-file " . escapeshellarg($envFile) : "";

        $result = Shell::run(
            "cd " . escapeshellarg(rtrim($releaseDir, '/')) . " && {$dockerCommand}{$envFlag} down 2>&1",
            $returnVar
        );

        return $returnVar === 0;
    }

    /**
     * Promote a shadow version to production.
     *
     * 1. Stop the currently active containers
     * 2. Rewrite env files with production ports for the new version
     * 3. Start the new version on production ports (instant — image pre-built)
     * 4. Update state
     *
     * Returns the promoted version string, or null on failure.
     */
    public static function promote(string $repo_dir, string $newVersion): ?string
    {
        $activeVersion = self::getActiveVersion($repo_dir);
        $newDir = self::getReleaseDir($repo_dir, $newVersion);

        // Stop current active containers
        if ($activeVersion) {
            $activeDir = self::getReleaseDir($repo_dir, $activeVersion);
            if (is_dir($activeDir)) {
                self::stopContainers($activeDir);
            }
        }

        // Write production ports for new version
        self::writeReleaseEnv($newDir, self::PRODUCTION_HTTP, self::PRODUCTION_HTTPS, $newVersion);

        // Start new version on production ports (near-instant)
        $started = self::startContainers($newDir);
        if (!$started) {
            // Rollback: restart the original active version
            if ($activeVersion) {
                $activeDir = self::getReleaseDir($repo_dir, $activeVersion);
                self::writeReleaseEnv($activeDir, self::PRODUCTION_HTTP, self::PRODUCTION_HTTPS, $activeVersion);
                self::startContainers($activeDir);
            }
            return null;
        }

        // Update state
        self::setActiveVersion($repo_dir, $newVersion);
        self::setReleaseState($repo_dir, $newVersion, self::PRODUCTION_HTTP, 'serving');
        if ($activeVersion) {
            self::setReleaseState($repo_dir, $activeVersion, self::SHADOW_HTTP, 'standby');
            // Rewrite old active to shadow ports (keep it available for rollback)
            $activeDir = self::getReleaseDir($repo_dir, $activeVersion);
            if (is_dir($activeDir)) {
                self::writeReleaseEnv($activeDir, self::SHADOW_HTTP, self::SHADOW_HTTPS, $activeVersion);
            }
        }
        self::setShadowVersion($repo_dir, null);
        JsonLock::write('bluegreen.promoted_at', date('Y-m-d\TH:i:sP'), $repo_dir);
        JsonLock::save($repo_dir);

        return $newVersion;
    }

    /**
     * Run health checks against a release on the given HTTP port.
     */
    public static function runHealthChecks(string $repo_dir, int $httpPort, array $healthChecks, string $version = ''): bool
    {
        if (empty($healthChecks)) {
            return true;
        }

        sleep(2);
        $maxRetries = 3;

        foreach ($healthChecks as $check) {
            $type = $check['type'] ?? 'http';
            $passed = false;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                if ($type === 'http') {
                    $path = ltrim($check['path'] ?? '/health', '/');
                    $expectStatus = (int) ($check['expect_status'] ?? 200);
                    $timeout = (int) ($check['timeout'] ?? 10);

                    $result = Shell::run(
                        "curl -s -o /dev/null -w '%{http_code}' --max-time {$timeout} http://127.0.0.1:{$httpPort}/{$path} 2>/dev/null"
                    );
                    $statusCode = (int) trim($result);

                    if ($statusCode === $expectStatus) {
                        $passed = true;
                        break;
                    }
                } elseif ($type === 'exec') {
                    $command = $check['command'] ?? '';
                    $expectExit = (int) ($check['expect_exit'] ?? 0);
                    $releaseDir = self::getReleaseDir($repo_dir, $version);
                    $containerName = self::getContainerName($releaseDir);

                    if ($containerName) {
                        Shell::run("docker exec " . escapeshellarg($containerName) . " {$command} 2>&1", $returnVar);
                        if ($returnVar === $expectExit) {
                            $passed = true;
                            break;
                        }
                    }
                }

                if ($attempt < $maxRetries) {
                    sleep(3);
                }
            }

            if (!$passed) {
                return false;
            }
        }

        return true;
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

    /**
     * List all version releases on disk.
     */
    public static function listReleases(string $repo_dir): array
    {
        $releasesBase = self::getReleasesDir($repo_dir);
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

    /**
     * Remove a release directory and its containers.
     */
    public static function removeRelease(string $repo_dir, string $version): bool
    {
        $releaseDir = self::getReleaseDir($repo_dir, $version);
        if (!is_dir($releaseDir)) {
            return true;
        }

        self::stopContainers($releaseDir);
        Shell::run("rm -rf " . escapeshellarg(rtrim($releaseDir, '/')));

        return !is_dir($releaseDir);
    }
}
