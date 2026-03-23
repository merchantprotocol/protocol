<?php
/**
 * Node-level configuration stored in ~/.protocol/.node/nodes/
 *
 * When a server is set up as a slave/deployment node, its configuration
 * is stored here instead of in the project repo. This way blue-green
 * deployments can swap directories without losing track of settings.
 */
namespace Gitcd\Utils;

class NodeConfig
{
    private static string $nodesDir = '';

    /**
     * Get the nodes configuration directory.
     */
    public static function nodesDir(): string
    {
        if (!self::$nodesDir) {
            self::$nodesDir = NODE_DATA_DIR . 'nodes';
        }
        return self::$nodesDir;
    }

    /**
     * Get the config file path for a project.
     */
    public static function configPath(string $projectName): string
    {
        return self::nodesDir() . '/' . $projectName . '.json';
    }

    /**
     * Check if a node config exists for a project.
     */
    public static function exists(string $projectName): bool
    {
        return is_file(self::configPath($projectName));
    }

    /**
     * Read a value from a node config (with shared file lock).
     */
    public static function read(string $projectName, string $key, $default = null)
    {
        $data = self::load($projectName);
        if (empty($data)) {
            return $default;
        }

        // Support dotted keys like "bluegreen.releases_dir"
        $keys = explode('.', $key);
        $current = $data;
        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return $default;
            }
            $current = $current[$k];
        }
        return $current;
    }

    /**
     * Write a node config file (atomic read-modify-write with exclusive lock).
     */
    public static function save(string $projectName, array $data): void
    {
        $dir = self::nodesDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = self::configPath($projectName);
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
        chmod($path, 0600);
    }

    /**
     * Atomically load, modify, and save a node config.
     * The callback receives the current data array and must return the modified array.
     * The file is held under exclusive lock for the entire read-modify-write cycle.
     *
     * @param string $projectName
     * @param callable(array): array $callback
     */
    public static function modify(string $projectName, callable $callback): void
    {
        $dir = self::nodesDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = self::configPath($projectName);
        $lockFile = $path . '.lock';

        $lockHandle = fopen($lockFile, 'c');
        if (!$lockHandle) {
            // Fallback to non-atomic save
            $data = self::load($projectName);
            $data = $callback($data);
            self::save($projectName, $data);
            return;
        }

        // Acquire exclusive lock — blocks until available
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            $data = self::load($projectName);
            $data = $callback($data);
            self::save($projectName, $data);
            return;
        }

        try {
            // Read under lock
            $data = [];
            if (is_file($path)) {
                $content = file_get_contents($path);
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }

            // Modify
            $data = $callback($data);

            // Write under lock
            file_put_contents(
                $path,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
                LOCK_EX
            );
            chmod($path, 0600);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * Load the full config array for a project (with shared file lock).
     */
    public static function load(string $projectName): array
    {
        $path = self::configPath($projectName);
        if (!is_file($path)) {
            return [];
        }

        // Acquire shared lock for consistent reads
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }

        try {
            flock($handle, LOCK_SH);
            $content = stream_get_contents($handle);
            $data = json_decode($content, true);
            return is_array($data) ? $data : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * List all configured node projects.
     */
    public static function listProjects(): array
    {
        $dir = self::nodesDir();
        if (!is_dir($dir)) {
            return [];
        }

        $projects = [];
        foreach (glob($dir . '/*.json') as $file) {
            $projects[] = basename($file, '.json');
        }
        return $projects;
    }

    /**
     * Find a node config by matching a directory path.
     * Checks releases_dir first, then repo_dir for legacy configs.
     */
    public static function findByRepoDir(string $repoDir): ?string
    {
        $repoDir = rtrim($repoDir, '/');
        foreach (self::listProjects() as $project) {
            $data = self::load($project);

            // Primary: check if path is inside or matches releases_dir
            $releasesDir = rtrim($data['bluegreen']['releases_dir'] ?? '', '/');
            if ($releasesDir && ($repoDir === $releasesDir || str_starts_with($repoDir, $releasesDir . '/'))) {
                return $project;
            }

            // Legacy: match repo_dir for configs that don't use releases_dir
            $nodeRepoDir = rtrim($data['repo_dir'] ?? '', '/');
            if ($nodeRepoDir && $nodeRepoDir === $repoDir) {
                return $project;
            }
        }
        return null;
    }

    /**
     * Resolve a slave node by project name or current directory.
     *
     * Returns [projectName, nodeData, activeDir] or null if no slave node found.
     * All paths derive from releases_dir when configured.
     */
    public static function resolveSlaveNode(?string $projectName = null, ?string $repoDir = null): ?array
    {
        if ($projectName) {
            if (!self::exists($projectName)) {
                return null;
            }
            $data = self::load($projectName);
            if (($data['node_type'] ?? '') !== 'slave') {
                return null;
            }
        } else {
            $projects = self::listProjects();
            if (empty($projects)) {
                return null;
            }

            $matched = $repoDir ? self::findByRepoDir($repoDir) : null;
            if (!$matched) {
                $matched = $projects[0];
            }

            $data = self::load($matched);
            if (empty($data) || ($data['node_type'] ?? '') !== 'slave') {
                return null;
            }
            $projectName = $matched;
        }

        $activeDir = self::resolveActiveDir($data);

        return [$projectName, $data, $activeDir];
    }

    /**
     * Resolve the active directory from node config data.
     * releases_dir is the source of truth.
     *
     * @throws \RuntimeException when the resolved directory does not exist
     */
    public static function resolveActiveDir(array $data): string
    {
        $strategy = $data['deployment']['strategy'] ?? 'none';
        $releasesDir = $data['bluegreen']['releases_dir'] ?? null;
        $currentRelease = $data['release']['current'] ?? null;
        $currentBranch = $data['deployment']['branch'] ?? $data['git']['branch'] ?? null;
        $projectName = $data['name'] ?? 'unknown';

        if (!$releasesDir) {
            throw new \RuntimeException(
                "Node '{$projectName}' has no releases_dir configured. "
                . "Run 'protocol init' to set up the releases directory."
            );
        }

        if (!is_dir($releasesDir)) {
            throw new \RuntimeException(
                "Releases directory does not exist: {$releasesDir} "
                . "(node: {$projectName}). Run 'protocol init' to set it up."
            );
        }

        // Release strategy: expect the release version directory
        if ($strategy === 'release' && $currentRelease) {
            $dir = rtrim($releasesDir, '/') . '/' . $currentRelease;
            if (!is_dir($dir)) {
                throw new \RuntimeException(
                    "Release directory not found: {$dir} "
                    . "(strategy=release, current={$currentRelease}). "
                    . "The release may not have been deployed yet."
                );
            }
            return rtrim($dir, '/') . '/';
        }

        // Release strategy but no release.current: scan for deployed releases
        if ($strategy === 'release') {
            $found = self::scanReleasesDir($releasesDir);
            if ($found) {
                return $found;
            }
        }

        // Branch strategy: expect the branch directory
        if ($currentBranch) {
            $dir = rtrim($releasesDir, '/') . '/' . $currentBranch;
            if (is_dir($dir)) {
                return rtrim($dir, '/') . '/';
            }
        }

        // Last resort: scan for any deployed directory
        $found = self::scanReleasesDir($releasesDir);
        if ($found) {
            return $found;
        }

        throw new \RuntimeException(
            "No deployed release or branch found in {$releasesDir} "
            . "(node: {$projectName}). Run 'protocol init' or deploy a release."
        );
    }

    /**
     * Scan releases directory for the most recent versioned deployment.
     * Returns the directory path or null if none found.
     */
    private static function scanReleasesDir(string $releasesDir): ?string
    {
        $releasesDir = rtrim($releasesDir, '/');
        $candidates = [];
        foreach (scandir($releasesDir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $releasesDir . '/' . $entry;
            if (!is_dir($path)) continue;
            // Skip config directories (e.g. ghostagent-config)
            if (str_ends_with($entry, '-config')) continue;
            // Prefer version-tagged directories (v0.1.0, v1.2.3, etc.)
            if (preg_match('/^v?\d+\.\d+/', $entry)) {
                $candidates[] = $entry;
            }
        }
        if (empty($candidates)) {
            return null;
        }
        // Sort versions descending, pick newest
        usort($candidates, 'version_compare');
        $best = array_pop($candidates);
        return $releasesDir . '/' . $best . '/';
    }

    /**
     * Find a slave node config by checking if a directory is inside
     * any node's releases directory.
     *
     * @return array|null [$projectName, $nodeData] or null
     */
    public static function findByActiveDir(string $activeDir): ?array
    {
        $activeDir = rtrim($activeDir, '/');
        foreach (self::listProjects() as $project) {
            $data = self::load($project);
            if (($data['node_type'] ?? '') !== 'slave') {
                continue;
            }
            $releasesDir = rtrim($data['bluegreen']['releases_dir'] ?? '', '/');
            if ($releasesDir && ($activeDir === $releasesDir || str_starts_with($activeDir, $releasesDir . '/'))) {
                return [$project, $data];
            }
        }
        return null;
    }
}
