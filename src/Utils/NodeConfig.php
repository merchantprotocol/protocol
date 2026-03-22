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
     * Read a value from a node config.
     */
    public static function read(string $projectName, string $key, $default = null)
    {
        $path = self::configPath($projectName);
        if (!is_file($path)) {
            return $default;
        }

        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
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
     * Write a node config file.
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
     * Load the full config array for a project.
     */
    public static function load(string $projectName): array
    {
        $path = self::configPath($projectName);
        if (!is_file($path)) {
            return [];
        }

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
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
        $strategy = $data['deployment']['strategy'] ?? 'branch';
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

        // Branch strategy: expect the branch directory
        if ($currentBranch) {
            $dir = rtrim($releasesDir, '/') . '/' . $currentBranch;
            if (!is_dir($dir)) {
                throw new \RuntimeException(
                    "Branch directory not found: {$dir} "
                    . "(strategy={$strategy}, branch={$currentBranch}). "
                    . "Clone the branch into the releases directory first."
                );
            }
            return rtrim($dir, '/') . '/';
        }

        throw new \RuntimeException(
            "Cannot resolve active directory for node '{$projectName}': "
            . "no current release or branch configured. "
            . "Set deployment.branch or deploy a release."
        );
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
