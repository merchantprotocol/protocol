<?php
/**
 * GitHub API helper using the `gh` CLI.
 */
namespace Gitcd\Helpers;

class GitHub
{
    /**
     * Get the owner/repo slug from the git remote URL.
     */
    public static function getRepoSlug(?string $repo_dir = null): ?string
    {
        $remote = Git::RemoteUrl($repo_dir);
        if (!$remote) return null;

        // SSH: git@github.com:owner/repo.git
        if (preg_match('#github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$#', $remote, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Get a GitHub Actions variable value.
     */
    public static function getVariable(string $name, ?string $repo_dir = null): ?string
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return null;

        $result = Shell::run(
            "gh variable get " . escapeshellarg($name) . " --repo " . escapeshellarg($slug) . " 2>/dev/null"
        );
        return $result ? trim($result) : null;
    }

    /**
     * Set a GitHub Actions variable.
     */
    public static function setVariable(string $name, string $value, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        $result = Shell::run(
            "gh variable set " . escapeshellarg($name) . " --body " . escapeshellarg($value) . " --repo " . escapeshellarg($slug) . " 2>&1",
            $error
        );
        return !$error;
    }

    /**
     * Set a GitHub Actions secret (write-only).
     */
    public static function setSecret(string $name, string $value, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        $result = Shell::run(
            "echo " . escapeshellarg($value) . " | gh secret set " . escapeshellarg($name) . " --repo " . escapeshellarg($slug) . " 2>&1",
            $error
        );
        return !$error;
    }

    /**
     * Check if gh CLI is available and authenticated.
     */
    public static function isAvailable(): bool
    {
        $result = Shell::run("gh auth status 2>&1", $error);
        return !$error;
    }

    /**
     * List GitHub releases.
     */
    public static function listReleases(?string $repo_dir = null, int $limit = 20): array
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return [];

        $json = Shell::run(
            "gh release list --repo " . escapeshellarg($slug) . " --limit {$limit} --json tagName,name,publishedAt,isDraft,isPrerelease 2>/dev/null"
        );
        if (!$json) return [];

        $releases = json_decode($json, true);
        return is_array($releases) ? $releases : [];
    }

    /**
     * Create a GitHub release.
     */
    public static function createRelease(string $tag, string $title = '', bool $draft = false, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        $cmd = "gh release create " . escapeshellarg($tag);
        $cmd .= " --repo " . escapeshellarg($slug);
        if ($title) {
            $cmd .= " --title " . escapeshellarg($title);
        }
        if ($draft) {
            $cmd .= " --draft";
        }
        $cmd .= " --generate-notes 2>&1";

        Shell::run($cmd, $error);
        return !$error;
    }

    /**
     * Get the latest release tag.
     */
    public static function getLatestRelease(?string $repo_dir = null): ?string
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return null;

        $json = Shell::run(
            "gh release view --repo " . escapeshellarg($slug) . " --json tagName 2>/dev/null"
        );
        if (!$json) return null;

        $data = json_decode($json, true);
        return $data['tagName'] ?? null;
    }

    /**
     * Get tags from the repository, sorted newest first.
     */
    public static function getTags(?string $repo_dir = null): array
    {
        $dir = $repo_dir ?: WORKING_DIR;
        $result = Shell::run("git -C " . escapeshellarg($dir) . " tag --sort=-v:refname 2>/dev/null");
        if (!$result) return [];

        return array_filter(array_map('trim', explode("\n", $result)));
    }

    /**
     * Check if a tag exists locally or remotely.
     */
    public static function tagExists(string $tag, ?string $repo_dir = null): bool
    {
        $dir = $repo_dir ?: WORKING_DIR;
        $result = Shell::run(
            "git -C " . escapeshellarg($dir) . " tag -l " . escapeshellarg($tag) . " 2>/dev/null"
        );
        return trim($result) === $tag;
    }
}
