<?php
/**
 * Release State Management.
 *
 * Manages version state (active, shadow, previous) stored in protocol.lock,
 * and queries release metadata from protocol.json.
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
     * Check if shadow deployment is enabled.
     * Node config is the source of truth (deployment is a node-level concern).
     * Falls back to protocol.json only for non-slave/local dev usage.
     */
    public static function isEnabled(string $repo_dir): bool
    {
        $nodeData = self::getNodeData($repo_dir);
        if (!empty($nodeData)) {
            return (bool) ($nodeData['bluegreen']['enabled'] ?? false);
        }

        return (bool) Json::read('bluegreen.enabled', false, $repo_dir);
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
