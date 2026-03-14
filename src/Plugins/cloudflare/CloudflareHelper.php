<?php
namespace Gitcd\Plugins\cloudflare;

use Gitcd\Utils\Json;
use Gitcd\Helpers\Git;

class CloudflareHelper
{
    const BACKUP_DIR = '.backups';
    const BACKUP_PREFIX = 'static-output-backup';
    const MIN_FILES = 500;

    /**
     * Read a project-level cloudflare config value from protocol.json.
     * These are per-project settings stored under "cloudflare.*".
     */
    public static function config(string $key, $default = null, $repoDir = false)
    {
        return Json::read("cloudflare.{$key}", $default, $repoDir);
    }

    /**
     * Get the absolute path to the static output directory.
     */
    public static function staticDir($repoDir = false): string
    {
        if (!$repoDir) {
            $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        }
        $rel = self::config('static_dir', './static-output', $repoDir);
        if (str_starts_with($rel, '/')) {
            return rtrim($rel, '/');
        }
        return rtrim($repoDir, '/') . '/' . ltrim($rel, './');
    }

    /**
     * Get the absolute path to the backups directory.
     */
    public static function backupDir($repoDir = false): string
    {
        if (!$repoDir) {
            $repoDir = Git::getGitLocalFolder() ?: WORKING_DIR;
        }
        return rtrim($repoDir, '/') . '/' . self::BACKUP_DIR;
    }

    /**
     * Get the latest backup directory path, or null if none.
     */
    public static function latestBackup($repoDir = false): ?string
    {
        $pattern = self::backupDir($repoDir) . '/' . self::BACKUP_PREFIX . '-*';
        $dirs = glob($pattern, GLOB_ONLYDIR);
        if (empty($dirs)) {
            return null;
        }
        usort($dirs, fn($a, $b) => filemtime($b) - filemtime($a));
        return $dirs[0];
    }

    /**
     * Get all backup directories sorted newest first.
     */
    public static function allBackups($repoDir = false): array
    {
        $pattern = self::backupDir($repoDir) . '/' . self::BACKUP_PREFIX . '-*';
        $dirs = glob($pattern, GLOB_ONLYDIR);
        if (empty($dirs)) {
            return [];
        }
        usort($dirs, fn($a, $b) => filemtime($b) - filemtime($a));
        return $dirs;
    }

    /**
     * Count files recursively in a directory.
     */
    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Parse a backup directory name into a human-readable date string.
     */
    public static function backupDate(string $backupPath): string
    {
        $name = basename($backupPath);
        if (preg_match('/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $name, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
        }
        return 'unknown';
    }

    /**
     * Get the project name for Cloudflare Pages.
     */
    public static function projectName($repoDir = false): string
    {
        return self::config('project_name', 'my-project', $repoDir);
    }

    /**
     * Get the production URL.
     */
    public static function productionUrl($repoDir = false): string
    {
        return self::config('production_url', 'https://example.com', $repoDir);
    }

    /**
     * Get the local origin URL that should be replaced during prepare.
     */
    public static function localOrigin($repoDir = false): string
    {
        return self::config('local_origin', 'https://localhost', $repoDir);
    }

    /**
     * Build MD5 checksum map for all files in a directory.
     * Returns array keyed by relative path => md5 hash.
     */
    public static function checksumMap(string $dir): array
    {
        $map = [];
        if (!is_dir($dir)) {
            return $map;
        }
        $baseLen = strlen(rtrim($dir, '/')) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), $baseLen);
                $map[$rel] = md5_file($file->getPathname());
            }
        }
        return $map;
    }
}
