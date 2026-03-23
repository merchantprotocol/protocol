<?php
/**
 * GitHub API helper.
 *
 * Uses the GitHub REST API via GitHubApp installation tokens.
 * No dependency on the `gh` CLI.
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
     * Make an authenticated GitHub API request using the GitHubApp token.
     *
     * @param string $method  HTTP method (GET, POST, PATCH, PUT, DELETE)
     * @param string $endpoint API path (e.g. /repos/owner/repo/actions/variables/NAME)
     * @param array|null $body Request body (will be JSON-encoded for non-GET)
     * @return array|null Decoded JSON response, or null on failure
     */
    private static function apiRequest(string $method, string $endpoint, ?array $body = null): ?array
    {
        $token = GitHubApp::getAccessToken();

        if ($token) {
            $url = "https://api.github.com" . $endpoint;

            $cmd = "curl -s -X " . escapeshellarg($method)
                . " -H " . escapeshellarg("Authorization: token {$token}")
                . " -H 'Accept: application/vnd.github+json'"
                . " -H 'X-GitHub-Api-Version: 2022-11-28'";

            if ($body !== null && $method !== 'GET') {
                $cmd .= " -H 'Content-Type: application/json'"
                    . " -d " . escapeshellarg(json_encode($body));
            }

            $cmd .= " " . escapeshellarg($url) . " 2>/dev/null";
        } else {
            Log::warn('github', "no access token available, falling back to gh CLI for {$method} {$endpoint}");
            $cmd = "gh api -X " . escapeshellarg($method)
                . " -H 'Accept: application/vnd.github+json'";

            if ($body !== null && $method !== 'GET') {
                foreach ($body as $key => $val) {
                    if (is_bool($val)) {
                        $cmd .= " -F " . escapeshellarg("{$key}=" . ($val ? 'true' : 'false'));
                    } else {
                        $cmd .= " -f " . escapeshellarg("{$key}={$val}");
                    }
                }
            }

            $cmd .= " " . escapeshellarg($endpoint) . " 2>/dev/null";
        }

        Log::debug('github', "API {$method} {$endpoint}");
        $start = microtime(true);
        $result = Shell::run($cmd);
        $duration = round(microtime(true) - $start, 2);

        if (!$result) {
            Log::warn('github', "API {$method} {$endpoint} returned empty response ({$duration}s)");
            return null;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            Log::warn('github', "API {$method} {$endpoint} returned non-JSON response ({$duration}s)");
            return null;
        }

        // Log API errors (rate limit, auth failures, not found)
        if (isset($data['message'])) {
            Log::warn('github', "API {$method} {$endpoint} error: {$data['message']} ({$duration}s)");
        }

        return $data;
    }

    /**
     * Make an authenticated API request and return the raw string response.
     */
    private static function apiRequestRaw(string $method, string $endpoint): ?string
    {
        $token = GitHubApp::getAccessToken();

        if ($token) {
            $url = "https://api.github.com" . $endpoint;
            $cmd = "curl -s -X " . escapeshellarg($method)
                . " -H " . escapeshellarg("Authorization: token {$token}")
                . " -H 'Accept: application/vnd.github+json'"
                . " -H 'X-GitHub-Api-Version: 2022-11-28'"
                . " " . escapeshellarg($url) . " 2>/dev/null";
        } else {
            $cmd = "gh api -X " . escapeshellarg($method)
                . " -H 'Accept: application/vnd.github+json'"
                . " " . escapeshellarg($endpoint) . " 2>/dev/null";
        }

        $result = Shell::run($cmd);
        return $result ?: null;
    }

    /**
     * Get a GitHub Actions variable value.
     */
    public static function getVariable(string $name, ?string $repo_dir = null): ?string
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) {
            Log::warn('github', "getVariable({$name}) failed: could not resolve repo slug");
            return null;
        }

        $data = self::apiRequest('GET', "/repos/{$slug}/actions/variables/" . urlencode($name));
        $value = $data['value'] ?? null;

        if ($value === null) {
            Log::debug('github', "getVariable({$name}) returned null for {$slug}");
        }

        return $value;
    }

    /**
     * Set a GitHub Actions variable.
     */
    public static function setVariable(string $name, string $value, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        // Try to update first (PATCH), create if not found (POST)
        $data = self::apiRequest('PATCH', "/repos/{$slug}/actions/variables/" . urlencode($name), [
            'value' => $value,
        ]);

        // If variable doesn't exist, PATCH returns 404 — create it
        if ($data && isset($data['message']) && str_contains($data['message'], 'Not Found')) {
            $data = self::apiRequest('POST', "/repos/{$slug}/actions/variables", [
                'name' => $name,
                'value' => $value,
            ]);
        }

        // PATCH returns 204 (empty body → null), which is success
        // POST returns 201 with the created variable
        return $data === null || !isset($data['message']);
    }

    /**
     * Set a GitHub Actions secret (write-only).
     *
     * Requires libsodium for public-key encryption.
     */
    public static function setSecret(string $name, string $value, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        // Get the repo's public key for encrypting secrets
        $keyData = self::apiRequest('GET', "/repos/{$slug}/actions/secrets/public-key");
        if (!$keyData || !isset($keyData['key']) || !isset($keyData['key_id'])) {
            return false;
        }

        // Encrypt using libsodium (sealed box)
        if (!function_exists('sodium_crypto_box_seal')) {
            return false;
        }

        $publicKey = base64_decode($keyData['key']);
        $encrypted = sodium_crypto_box_seal($value, $publicKey);
        $encryptedBase64 = base64_encode($encrypted);

        $data = self::apiRequest('PUT', "/repos/{$slug}/actions/secrets/" . urlencode($name), [
            'encrypted_value' => $encryptedBase64,
            'key_id' => $keyData['key_id'],
        ]);

        // PUT returns 201 (created) or 204 (updated) — both have null/empty body
        return $data === null || !isset($data['message']);
    }

    /**
     * Check if GitHub API is accessible via the App token.
     */
    public static function isAvailable(): bool
    {
        $token = GitHubApp::getAccessToken();
        return $token !== null;
    }

    /**
     * List GitHub releases.
     */
    public static function listReleases(?string $repo_dir = null, int $limit = 20): array
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return [];

        $raw = self::apiRequestRaw('GET', "/repos/{$slug}/releases?per_page={$limit}");
        if (!$raw) return [];

        $releases = json_decode($raw, true);
        if (!is_array($releases)) return [];

        // Normalize to match the format the codebase expects
        $result = [];
        foreach ($releases as $r) {
            $result[] = [
                'tagName' => $r['tag_name'] ?? '',
                'name' => $r['name'] ?? '',
                'publishedAt' => $r['published_at'] ?? '',
                'isDraft' => $r['draft'] ?? false,
                'isPrerelease' => $r['prerelease'] ?? false,
            ];
        }
        return $result;
    }

    /**
     * Create a GitHub release.
     */
    public static function createRelease(string $tag, string $title = '', bool $draft = false, ?string $repo_dir = null): bool
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return false;

        $body = [
            'tag_name' => $tag,
            'name' => $title ?: $tag,
            'draft' => $draft,
            'generate_release_notes' => true,
        ];

        $data = self::apiRequest('POST', "/repos/{$slug}/releases", $body);
        return $data !== null && isset($data['id']);
    }

    /**
     * Get the latest release tag.
     */
    public static function getLatestRelease(?string $repo_dir = null): ?string
    {
        $slug = self::getRepoSlug($repo_dir);
        if (!$slug) return null;

        $data = self::apiRequest('GET', "/repos/{$slug}/releases/latest");
        return $data['tag_name'] ?? null;
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

        // Query merged PRs via the API
        $raw = self::apiRequestRaw('GET', "/repos/{$slug}/pulls?state=closed&sort=updated&direction=desc&per_page=50");
        if (!$raw) return [];

        $prs = json_decode($raw, true);
        if (!is_array($prs)) return [];

        $result = [];
        foreach ($prs as $pr) {
            // Only include actually merged PRs
            if (empty($pr['merged_at'])) continue;

            // Get reviews for approver info
            $approvers = [];
            $reviews = self::apiRequest('GET', "/repos/{$slug}/pulls/{$pr['number']}/reviews");
            if (is_array($reviews)) {
                foreach ($reviews as $review) {
                    if (($review['state'] ?? '') === 'APPROVED') {
                        $approvers[] = $review['user']['login'] ?? 'unknown';
                    }
                }
            }

            $result[] = [
                'number' => (string) ($pr['number'] ?? ''),
                'title' => $pr['title'] ?? '',
                'author' => $pr['user']['login'] ?? '',
                'approvers' => implode(',', array_unique($approvers)),
                'merged_by' => $pr['merged_by']['login'] ?? '',
                'merged_at' => $pr['merged_at'] ?? '',
                'url' => $pr['html_url'] ?? '',
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
