<?php
/**
 * Node-level configuration stored in ~/.protocol/nodes/
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
            self::$nodesDir = rtrim(getenv('HOME') ?: '/root', '/') . '/.protocol/nodes';
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
     * Find a node config by its repo directory path.
     */
    public static function findByRepoDir(string $repoDir): ?string
    {
        $repoDir = rtrim($repoDir, '/');
        foreach (self::listProjects() as $project) {
            $data = self::load($project);
            $nodeRepoDir = rtrim($data['repo_dir'] ?? '', '/');
            if ($nodeRepoDir === $repoDir) {
                return $project;
            }
        }
        return null;
    }
}
