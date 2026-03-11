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
     * Get merged PRs associated with a release tag by finding commits between
     * the previous tag and this one, then querying for associated PRs.
     *
     * Returns an array of PR metadata including approvers.
     */
    public static function getMergedPRsForTag(string $tag, ?string $repo_dir = null): array
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return [];

        // Find the previous tag to determine the commit range
        $dir = $repo_dir ?: WORKING_DIR;
        $tags = self::getTags($dir);
        $prevTag = null;
        $found = false;
        foreach ($tags as $t) {
            if ($found) {
                $prevTag = $t;
                break;
            }
            if ($t === $tag) {
                $found = true;
            }
        }

        // Get PRs merged into the default branch between tags
        $range = $prevTag ? escapeshellarg($prevTag) . ".." . escapeshellarg($tag) : escapeshellarg($tag);
        $commitJson = Shell::run(
            "git -C " . escapeshellarg($dir) . " log {$range} --merges --format=%H 2>/dev/null"
        );

        // Use gh to find PRs associated with the release
        $json = Shell::run(
            "gh pr list --repo " . escapeshellarg($slug)
            . " --state merged --limit 50"
            . " --json number,title,author,mergedBy,mergedAt,reviews,url"
            . " --jq " . escapeshellarg('[.[] | select(.mergedAt != null)]')
            . " 2>/dev/null"
        );

        if (!$json) return [];

        $prs = json_decode($json, true);
        if (!is_array($prs)) return [];

        $result = [];
        foreach ($prs as $pr) {
            $approvers = [];
            if (!empty($pr['reviews'])) {
                foreach ($pr['reviews'] as $review) {
                    if (($review['state'] ?? '') === 'APPROVED') {
                        $approvers[] = $review['author']['login'] ?? 'unknown';
                    }
                }
            }
            $result[] = [
                'number' => (string) ($pr['number'] ?? ''),
                'title' => $pr['title'] ?? '',
                'author' => $pr['author']['login'] ?? '',
                'approvers' => implode(',', array_unique($approvers)),
                'merged_by' => $pr['mergedBy']['login'] ?? '',
                'merged_at' => $pr['mergedAt'] ?? '',
                'url' => $pr['url'] ?? '',
            ];
        }

        return $result;
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
