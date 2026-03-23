<?php
/**
 * Centralized Docker Container Name Resolution.
 *
 * Single source of truth for resolving container names across all
 * deployment strategies (none, branch, release, bluegreen). Replaces the
 * scattered resolution logic previously spread across 9+ files.
 *
 * Resolution priority (per directory):
 *   1. .protocol/deployment.json → container_name
 *   2. .env.deployment → CONTAINER_NAME=
 *   3. .env.bluegreen → CONTAINER_NAME= (legacy migration)
 *   4. protocol.json → docker.container_name
 *   5. docker-compose.yml → services.*.container_name (resolved with env vars)
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers;

use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\BlueGreen\ReleaseState;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

class ContainerName
{
    /**
     * Resolve the container name for a specific directory.
     * Walks the full priority chain.
     *
     * @param string $dir  Repo or release directory
     * @return string|null  Container name, or null if unresolvable
     */
    public static function resolveFromDir(string $dir): ?string
    {
        Log::debug('container-name', "resolve dir={$dir}");

        // 1. .protocol/deployment.json
        $name = self::fromDeploymentJson($dir);
        if ($name) {
            Log::debug('container-name', "resolved: \"{$name}\" (source: deployment.json)");
            return $name;
        }

        // 2. .env.deployment
        $name = self::fromEnvFile($dir, '.env.deployment');
        if ($name) {
            Log::debug('container-name', "resolved: \"{$name}\" (source: .env.deployment)");
            return $name;
        }

        // 3. .env.bluegreen (legacy migration)
        $name = self::fromEnvFile($dir, '.env.bluegreen');
        if ($name) {
            Log::debug('container-name', "resolved: \"{$name}\" (source: .env.bluegreen)");
            return $name;
        }

        // 4. protocol.json
        $name = self::fromProtocolJson($dir);
        if ($name) {
            Log::debug('container-name', "resolved: \"{$name}\" (source: protocol.json)");
            return $name;
        }

        // 5. docker-compose.yml
        $name = self::fromComposeFile($dir);
        if ($name) {
            Log::debug('container-name', "resolved: \"{$name}\" (source: docker-compose.yml)");
            return $name;
        }

        Log::warn('container-name', "could not resolve from any source in {$dir}");
        return null;
    }

    /**
     * Resolve the currently active container name (strategy-aware).
     * None/Branch: resolves from repo dir.
     * Release/bluegreen: resolves from active release dir, falls back to repo dir.
     *
     * @param string $repoDir  The project's repo directory
     * @return string|null
     */
    public static function resolveActive(string $repoDir): ?string
    {
        $strategy = self::getStrategy($repoDir);
        Log::debug('container-name', "resolve-active repo={$repoDir} strategy={$strategy}");

        if ($strategy === 'none' || $strategy === 'branch') {
            return self::resolveFromDir($repoDir);
        }

        // release or bluegreen — find active release dir
        $releaseDir = self::getActiveReleaseDir($repoDir);
        if ($releaseDir) {
            Log::debug('container-name', "active release dir: {$releaseDir}");
            $name = self::resolveFromDir($releaseDir);
            if ($name) {
                return $name;
            }
        } else {
            Log::warn('container-name', "no active release dir found, falling back to repo dir");
        }

        // Fallback to repo dir
        return self::resolveFromDir($repoDir);
    }

    /**
     * Resolve all known container names across all deployment dirs.
     * Used by stop/status commands that need to find everything.
     *
     * @param string $repoDir  The project's repo directory
     * @return string[]  Deduplicated array of container names
     */
    public static function resolveAll(string $repoDir): array
    {
        $strategy = self::getStrategy($repoDir);
        Log::debug('container-name', "resolve-all repo={$repoDir} strategy={$strategy}");

        // No deployment strategy — just use repo dir directly
        if ($strategy === 'none') {
            $names = Docker::getContainerNamesFromDockerComposeFile($repoDir);
            Log::debug('container-name', "strategy=none, compose names: [" . implode(', ', $names) . "]");
            return $names;
        }

        $names = [];

        // Release dir containers
        if (BlueGreen::isEnabled($repoDir)) {
            $releases = BlueGreen::listReleases($repoDir);
            foreach ($releases as $release) {
                $releaseDir = BlueGreen::getReleaseDir($repoDir, $release);
                if (is_dir($releaseDir)) {
                    $name = self::resolveFromDir($releaseDir);
                    if ($name && !in_array($name, $names, true)) {
                        $names[] = $name;
                    }
                }
            }
        }

        // Non-release dirs (repo dir, any tracked dirs)
        $dirs = DeploymentState::allKnownDirs($repoDir);
        foreach ($dirs as $dir) {
            if (BlueGreen::isReleaseDir($dir, $repoDir)) {
                continue; // Already covered above
            }
            // For non-release dirs, get all compose containers
            $composeNames = Docker::getContainerNamesFromDockerComposeFile($dir);
            foreach ($composeNames as $name) {
                if (!in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        Log::debug('container-name', "resolved all: [" . implode(', ', $names) . "]");
        return $names;
    }

    /**
     * Check if the active container is currently running.
     */
    public static function isActiveRunning(string $repoDir): bool
    {
        $name = self::resolveActive($repoDir);
        if (!$name) {
            return false;
        }
        return Docker::isDockerContainerRunning($name);
    }

    // ─── Internal resolution methods ────────────────────────────

    /**
     * Read container_name from .protocol/deployment.json.
     */
    private static function fromDeploymentJson(string $dir): ?string
    {
        $name = DeploymentState::readDeploymentJson($dir, 'container_name');
        if ($name) {
            return $name;
        }
        Log::debug('container-name', "deployment.json: not found or empty");
        return null;
    }

    /**
     * Read CONTAINER_NAME from an env file (.env.deployment or .env.bluegreen).
     */
    private static function fromEnvFile(string $dir, string $filename): ?string
    {
        $envFile = rtrim($dir, '/') . '/' . $filename;
        if (!is_file($envFile)) {
            Log::debug('container-name', "{$filename}: not found");
            return null;
        }
        $content = file_get_contents($envFile);
        if (preg_match('/^CONTAINER_NAME=(.+)$/m', $content, $m)) {
            return trim($m[1]);
        }
        Log::debug('container-name', "{$filename}: no CONTAINER_NAME line");
        return null;
    }

    /**
     * Read docker.container_name from protocol.json.
     */
    private static function fromProtocolJson(string $dir): ?string
    {
        $name = Json::read('docker.container_name', null, $dir);
        if ($name) {
            return $name;
        }
        Log::debug('container-name', "protocol.json: docker.container_name not set");
        return null;
    }

    /**
     * Parse container_name from docker-compose.yml, resolving env vars.
     * Returns the first resolved name if single service, null if multiple or none.
     */
    private static function fromComposeFile(string $dir): ?string
    {
        $composePath = rtrim($dir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            Log::debug('container-name', "docker-compose.yml: not found");
            return null;
        }

        $names = Docker::getContainerNamesFromDockerComposeFile($dir);
        if (count($names) === 1) {
            return $names[0];
        }
        if (count($names) > 1) {
            Log::debug('container-name', "docker-compose.yml: multiple containers (" . implode(', ', $names) . "), returning null");
        } else {
            Log::debug('container-name', "docker-compose.yml: no container_name defined");
        }
        return null;
    }

    // ─── Strategy helpers ───────────────────────────────────────

    /**
     * Determine the deployment strategy for a repo.
     */
    private static function getStrategy(string $repoDir): string
    {
        return ReleaseState::getStrategy($repoDir);
    }

    /**
     * Get the active release directory (release/bluegreen strategies).
     */
    private static function getActiveReleaseDir(string $repoDir): ?string
    {
        $activeVersion = ReleaseState::getActiveVersion($repoDir);
        if (!$activeVersion) {
            return null;
        }
        $dir = BlueGreen::getReleaseDir($repoDir, $activeVersion);
        return is_dir($dir) ? $dir : null;
    }
}
