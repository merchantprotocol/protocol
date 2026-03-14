<?php
namespace Gitcd\Helpers;

class PluginManager
{
    /**
     * Path to the global plugins config file: ~/.protocol/plugins.json
     */
    public static function configPath(): string
    {
        $home = ($_SERVER['HOME'] ?? getenv('HOME')) ?: '/root';
        return rtrim($home, '/') . '/.protocol/plugins.json';
    }

    /**
     * Load the global plugins config.
     */
    private static function loadConfig(): array
    {
        $path = self::configPath();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save the global plugins config.
     */
    private static function saveConfig(array $data): void
    {
        $path = self::configPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
    }

    /**
     * Get all available plugins by scanning the Plugins directory.
     *
     * @return array  Keyed by plugin slug, value is the decoded plugin.json data.
     */
    public static function available(): array
    {
        $plugins = [];
        if (!is_dir(PLUGINS_DIR)) {
            return $plugins;
        }

        foreach (glob(PLUGINS_DIR . '*', GLOB_ONLYDIR) as $dir) {
            $slug = basename($dir);
            $meta = self::readMeta($slug);
            if ($meta) {
                $plugins[$slug] = $meta;
            }
        }

        ksort($plugins);
        return $plugins;
    }

    /**
     * Get the list of globally enabled plugin slugs.
     *
     * @return array
     */
    public static function enabled(): array
    {
        $config = self::loadConfig();
        $plugins = $config['enabled'] ?? [];
        return is_array($plugins) ? $plugins : [];
    }

    /**
     * Check if a specific plugin is enabled globally.
     *
     * @param string $slug
     * @return bool
     */
    public static function isEnabled(string $slug): bool
    {
        return in_array($slug, self::enabled(), true);
    }

    /**
     * Enable a plugin globally by adding it to ~/.protocol/plugins.json.
     *
     * @param string $slug
     */
    public static function enable(string $slug): void
    {
        $config = self::loadConfig();
        $enabled = $config['enabled'] ?? [];
        if (!is_array($enabled)) {
            $enabled = [];
        }
        if (!in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
            sort($enabled);
            $config['enabled'] = $enabled;
            self::saveConfig($config);
        }
    }

    /**
     * Disable a plugin globally by removing it from ~/.protocol/plugins.json.
     *
     * @param string $slug
     */
    public static function disable(string $slug): void
    {
        $config = self::loadConfig();
        $enabled = $config['enabled'] ?? [];
        if (!is_array($enabled)) {
            $enabled = [];
        }
        $enabled = array_values(array_filter($enabled, fn($s) => $s !== $slug));
        $config['enabled'] = $enabled;
        self::saveConfig($config);
    }

    /**
     * Read the plugin.json metadata for a plugin.
     *
     * @param string $slug
     * @return array|null
     */
    public static function readMeta(string $slug): ?array
    {
        $file = PLUGINS_DIR . $slug . DIRECTORY_SEPARATOR . 'plugin.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the command directory for a plugin.
     *
     * @param string $slug
     * @return string
     */
    public static function commandsDir(string $slug): string
    {
        return PLUGINS_DIR . $slug . DIRECTORY_SEPARATOR . 'Commands' . DIRECTORY_SEPARATOR;
    }

    /**
     * Check if a plugin slug exists in the Plugins directory.
     *
     * @param string $slug
     * @return bool
     */
    public static function exists(string $slug): bool
    {
        return is_dir(PLUGINS_DIR . $slug) && is_file(PLUGINS_DIR . $slug . DIRECTORY_SEPARATOR . 'plugin.json');
    }
}
