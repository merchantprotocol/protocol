<?php
/**
 * Release Builder.
 *
 * Handles file system setup, git operations, Docker build/start/stop,
 * environment file generation, and compose file patching for releases.
 *
 * MIT License
 * Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 */
namespace Gitcd\Helpers\BlueGreen;

use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

class ReleaseBuilder
{
    /**
     * Get the git remote URL for cloning releases.
     * Node config is the source of truth, falls back to protocol.json, then git remote.
     */
    public static function getGitRemote(string $repo_dir): ?string
    {
        // Check node config first
        $projectName = NodeConfig::findByRepoDir($repo_dir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repo_dir);
            if ($match) {
                $projectName = $match[0];
            }
        }
        if ($projectName) {
            $nodeData = NodeConfig::load($projectName);
            $remote = $nodeData['bluegreen']['git_remote'] ?? null;
            if ($remote) {
                return $remote;
            }
        }

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
        $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
        $releasesBase = BlueGreen::getReleasesDir($repo_dir);

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

        $cloneUrl = \Gitcd\Helpers\GitHubApp::resolveUrl($gitRemote);
        $result = Shell::run(
            "GIT_TERMINAL_PROMPT=0 git clone " . escapeshellarg($cloneUrl) . " " . escapeshellarg(rtrim($releaseDir, '/')) . " 2>&1",
            $returnVar
        );

        return $returnVar === 0;
    }

    /**
     * Checkout a specific version (tag) in a release directory.
     */
    public static function checkoutVersion(string $releaseDir, string $version): bool
    {
        $dir = escapeshellarg(rtrim($releaseDir, '/'));

        // Fetch tags using resolved HTTPS URL so credential helper works
        $remoteUrl = trim(Shell::run("git -C {$dir} remote get-url origin 2>/dev/null"));
        $fetchUrl = $remoteUrl ? GitHubApp::resolveUrl($remoteUrl) : 'origin';
        Shell::run(
            "GIT_TERMINAL_PROMPT=0 git -C {$dir} fetch " . escapeshellarg($fetchUrl) . " --tags 2>&1",
            $fetchReturn
        );
        if ($fetchReturn !== 0) {
            // Tag fetch failed — caller logs the error context
        }

        $result = Shell::run(
            "git -C {$dir} checkout " . escapeshellarg($version) . " 2>&1",
            $returnVar
        );
        if ($returnVar !== 0) {
            // Checkout failed — caller logs the error context
        }

        return $returnVar === 0;
    }

    /**
     * Write the .env file for a release with port configuration.
     */
    public static function writeReleaseEnv(string $releaseDir, int $httpPort, int $httpsPort, string $version): void
    {
        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $safeName = BlueGreen::sanitizeVersion($version);
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
        $projectName = str_replace('.', '-', $safeName);
        $content .= "COMPOSE_PROJECT_NAME=protocol-{$projectName}\n";
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

        // Always re-read the original from git so patching is idempotent
        $dir = escapeshellarg(rtrim($releaseDir, '/'));
        $original = Shell::run("git -C {$dir} show HEAD:docker-compose.yml 2>/dev/null");
        $content = (!empty($original) && strpos($original, 'fatal:') === false)
            ? $original
            : file_get_contents($composePath);

        // Strip ALL existing port mappings and replace with only the
        // two shadow-configurable ports (HTTP → 80, HTTPS → 443).
        // This prevents collisions with the production container's ports.
        $content = preg_replace(
            '/^(\s+)ports:\s*\n(\s+-\s*".+"\s*\n)+/m',
            "$1ports:\n$1    - \"\${PROTOCOL_PORT_HTTP:-80}:80\"\n$1    - \"\${PROTOCOL_PORT_HTTPS:-443}:443\"\n",
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
     *
     * @param string $releaseDir  Path to the release directory
     * @param string|null &$output  Optional — receives the docker compose output for caller logging
     * @return bool  True on success
     */
    public static function buildContainers(string $releaseDir, ?string &$output = null): bool
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
        $output = $result;

        // Run composer install if needed
        if (file_exists(rtrim($releaseDir, '/') . '/composer.json')) {
            $containerName = BlueGreen::getContainerName($releaseDir);
            if ($containerName) {
                Shell::run("docker exec " . escapeshellarg($containerName) . " composer install --no-interaction 2>&1");
            }
        }

        return $returnVar === 0;
    }

    /**
     * Start containers for a release (fast -- image already built).
     *
     * Uses --env-file .env.bluegreen so docker compose resolves the
     * patched container name (e.g. ghostagent-v0.1.1) and port vars.
     */
    public static function startContainers(string $releaseDir): bool
    {
        $composePath = rtrim($releaseDir, '/') . '/docker-compose.yml';
        if (!file_exists($composePath)) {
            return false;
        }

        $envFile = rtrim($releaseDir, '/') . '/.env.bluegreen';
        $dockerCommand = Docker::getDockerCommand();

        $cmd = "cd " . escapeshellarg(rtrim($releaseDir, '/')) . " && {$dockerCommand} --env-file " . escapeshellarg($envFile) . " up -d 2>&1";
        $result = Shell::run($cmd, $returnVar);

        return $returnVar === 0;
    }

    /**
     * Stop containers for a release.
     *
     * Uses --env-file .env.bluegreen (if it exists) so docker compose
     * resolves the patched container name correctly. Without this flag,
     * compose uses the default name from the compose file, which won't
     * match the running container (e.g. stops 'ghostagent' instead of
     * 'ghostagent-v0.1.1').
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

        $cmd = "cd " . escapeshellarg(rtrim($releaseDir, '/')) . " && {$dockerCommand}{$envFlag} down 2>&1";
        $result = Shell::run($cmd, $returnVar);

        return $returnVar === 0;
    }

    /**
     * Remove a release directory and its containers.
     */
    public static function removeRelease(string $repo_dir, string $version): bool
    {
        $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
        if (!is_dir($releaseDir)) {
            return true;
        }

        self::stopContainers($releaseDir);
        Shell::run("rm -rf " . escapeshellarg(rtrim($releaseDir, '/')));

        return !is_dir($releaseDir);
    }
}
