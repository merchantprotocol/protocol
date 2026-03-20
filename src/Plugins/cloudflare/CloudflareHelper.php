<?php
namespace Gitcd\Plugins\cloudflare;

use Gitcd\Utils\Json;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

class CloudflareHelper
{
    const DEFAULT_MIN_FILES = 10;
    const CF_API_BASE = 'https://api.cloudflare.com/client/v4';

    /**
     * Read a project-level cloudflare config value from protocol.json.
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
     * Get the minimum file count for verification.
     */
    public static function minFiles($repoDir = false): int
    {
        return (int) self::config('min_files', self::DEFAULT_MIN_FILES, $repoDir);
    }

    // ─── Logging ────────────────────────────────────────────────

    /**
     * Write a verbose log entry to the cloudflare deploy log.
     */
    public static function log(string $message): void
    {
        $dir = NODE_DATA_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $logFile = $dir . 'cloudflare-deploy.log';
        $entry = date('Y-m-d\TH:i:sP') . ' ' . $message . "\n";
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    // ─── Cloudflare API ─────────────────────────────────────────

    /**
     * Get the OAuth token from wrangler's config file.
     */
    public static function getOAuthToken(): ?string
    {
        // macOS: ~/Library/Preferences/.wrangler/config/default.toml
        // Linux: ~/.config/.wrangler/config/default.toml
        $paths = [
            $_SERVER['HOME'] . '/Library/Preferences/.wrangler/config/default.toml',
            $_SERVER['HOME'] . '/.config/.wrangler/config/default.toml',
            $_SERVER['HOME'] . '/.wrangler/config/default.toml',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $contents = file_get_contents($path);
                if (preg_match('/oauth_token\s*=\s*"([^"]+)"/', $contents, $m)) {
                    return $m[1];
                }
            }
        }

        return null;
    }

    /**
     * Get the Cloudflare account ID via API, falling back to wrangler whoami.
     */
    public static function getAccountId(): ?string
    {
        // Try the API first — much faster and won't hang
        $token = self::getOAuthToken();
        if ($token) {
            self::log('getAccountId — querying API /accounts');
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => self::CF_API_BASE . '/accounts?per_page=1',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlErrno !== 0) {
                // Network error — safe to fall back to wrangler
                self::log("getAccountId — API curl error #{$curlErrno}: {$curlError}");
            } elseif ($httpCode === 401 || $httpCode === 403) {
                // Auth error — wrangler will hang for the same reason, don't fall back
                self::log("getAccountId — API returned HTTP {$httpCode}, OAuth token is expired or invalid. Run: npx wrangler login");
                return null;
            } elseif ($httpCode >= 200 && $httpCode < 300 && $response) {
                $data = json_decode($response, true);
                $accounts = $data['result'] ?? [];
                if (!empty($accounts)) {
                    $id = $accounts[0]['id'] ?? null;
                    if ($id) {
                        self::log("getAccountId — found account {$id} via API");
                        return $id;
                    }
                }
                self::log('getAccountId — API returned OK but no accounts found');
                return null;
            } else {
                self::log("getAccountId — API returned HTTP {$httpCode}");
            }
        }

        // Fallback to wrangler whoami — only reached on network errors or missing token
        self::log('getAccountId — falling back to `npx wrangler whoami`');
        $output = Shell::run('npx wrangler whoami 2>&1', $returnVar);
        if ($returnVar !== 0) {
            self::log("getAccountId — wrangler whoami failed (exit {$returnVar})");
            return null;
        }

        // Parse account ID from the table output
        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, '│') && !str_contains($line, '─') && !str_contains($line, 'Account ID')) {
                $cols = explode('│', $line);
                if (isset($cols[2])) {
                    $id = trim($cols[2]);
                    if (preg_match('/^[a-f0-9]{32}$/', $id)) {
                        self::log("getAccountId — found account {$id} via wrangler");
                        return $id;
                    }
                }
            }
        }

        self::log('getAccountId — could not determine account ID');
        return null;
    }

    /**
     * Make a Cloudflare API request.
     *
     * @return array|null Decoded JSON response, or null on failure
     */
    public static function apiRequest(string $method, string $endpoint, ?string $token = null): ?array
    {
        $token = $token ?: self::getOAuthToken();
        if (!$token) {
            self::log("API {$method} {$endpoint} — no OAuth token found, skipping request");
            return null;
        }

        $url = self::CF_API_BASE . $endpoint;
        self::log("API {$method} {$endpoint} — starting request");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        if ($curlErrno !== 0) {
            self::log("API {$method} {$endpoint} — curl error #{$curlErrno}: {$curlError} (after {$totalTime}s)");
            return null;
        }

        if ($httpCode < 200 || $httpCode >= 300 || !$response) {
            $snippet = $response ? substr($response, 0, 200) : '(empty)';
            self::log("API {$method} {$endpoint} — HTTP {$httpCode} after {$totalTime}s — {$snippet}");
            return null;
        }

        self::log("API {$method} {$endpoint} — HTTP {$httpCode} OK in {$totalTime}s");
        return json_decode($response, true);
    }

    /**
     * Get the list of deployments for a project from Cloudflare.
     *
     * @return array List of deployment objects, or empty array on failure
     */
    public static function getDeployments(string $projectName, ?string $accountId = null, int $perPage = 10): array
    {
        self::log("getDeployments — project={$projectName}, perPage={$perPage}");
        $accountId = $accountId ?: self::getAccountId();
        if (!$accountId) {
            self::log('getDeployments — no account ID, returning empty');
            return [];
        }

        $response = self::apiRequest(
            'GET',
            "/accounts/{$accountId}/pages/projects/{$projectName}/deployments?per_page={$perPage}"
        );

        if (!$response || !($response['success'] ?? false)) {
            self::log('getDeployments — API returned failure or empty response');
            return [];
        }

        $count = count($response['result'] ?? []);
        self::log("getDeployments — found {$count} deployment(s)");
        return $response['result'] ?? [];
    }

    /**
     * Get the file manifest (path => md5) for a specific deployment.
     *
     * @return array Map of relative paths to MD5 hashes, or empty array on failure
     */
    public static function getDeployedFiles(string $projectName, string $deploymentId, ?string $accountId = null): array
    {
        self::log("getDeployedFiles — project={$projectName}, deployment={$deploymentId}");
        $accountId = $accountId ?: self::getAccountId();
        if (!$accountId) {
            self::log('getDeployedFiles — no account ID, returning empty');
            return [];
        }

        $response = self::apiRequest(
            'GET',
            "/accounts/{$accountId}/pages/projects/{$projectName}/deployments/{$deploymentId}"
        );

        if (!$response || !($response['success'] ?? false)) {
            self::log('getDeployedFiles — API returned failure or empty response');
            return [];
        }

        $files = $response['result']['files'] ?? [];
        $count = count($files);
        self::log("getDeployedFiles — found {$count} file(s) in manifest");
        return $files;
    }

    /**
     * Get the file manifest for the latest production deployment.
     *
     * @return array Map of relative paths to MD5 hashes, or empty array
     */
    public static function getLatestDeployedFiles(string $projectName, ?string $accountId = null): array
    {
        self::log("getLatestDeployedFiles — project={$projectName}");
        $deployments = self::getDeployments($projectName, $accountId, 1);
        if (empty($deployments)) {
            self::log('getLatestDeployedFiles — no deployments found');
            return [];
        }

        $latest = $deployments[0];
        self::log("getLatestDeployedFiles — latest deployment ID: {$latest['id']}");
        return self::getDeployedFiles($projectName, $latest['id'], $accountId);
    }

    /**
     * Delete a specific deployment.
     *
     * @return array|null API response, or null on failure
     */
    public static function deleteDeployment(string $projectName, string $deploymentId, ?string $accountId = null): ?array
    {
        $accountId = $accountId ?: self::getAccountId();
        if (!$accountId) {
            return null;
        }

        return self::apiRequest(
            'DELETE',
            "/accounts/{$accountId}/pages/projects/{$projectName}/deployments/{$deploymentId}?force=true"
        );
    }

    /**
     * Rollback to a specific deployment.
     *
     * @return array|null API response, or null on failure
     */
    public static function rollback(string $projectName, string $deploymentId, ?string $accountId = null): ?array
    {
        $accountId = $accountId ?: self::getAccountId();
        if (!$accountId) {
            return null;
        }

        return self::apiRequest(
            'POST',
            "/accounts/{$accountId}/pages/projects/{$projectName}/deployments/{$deploymentId}/rollback"
        );
    }

    /**
     * Purge the Cloudflare cache for specific URLs or all files.
     *
     * @param string      $zoneId  The Cloudflare zone ID
     * @param array|null  $urls    Specific URLs to purge, or null to purge everything
     * @return array|null API response, or null on failure
     */
    public static function purgeCache(string $zoneId, ?array $urls = null): ?array
    {
        $token = self::getOAuthToken();
        if (!$token) {
            self::log('purgeCache — no OAuth token found');
            return null;
        }

        $endpoint = self::CF_API_BASE . "/zones/{$zoneId}/purge_cache";
        $body = $urls ? json_encode(['files' => $urls]) : '{"purge_everything":true}';

        self::log("purgeCache — zone={$zoneId}, urls=" . ($urls ? count($urls) : 'ALL'));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::log("purgeCache — HTTP {$httpCode}");
        return $response ? json_decode($response, true) : null;
    }

    /**
     * Get the Cloudflare zone ID for a domain.
     *
     * @return string|null Zone ID, or null if not found
     */
    public static function getZoneId(string $domain): ?string
    {
        $token = self::getOAuthToken();
        if (!$token) {
            return null;
        }

        $url = self::CF_API_BASE . '/zones?name=' . urlencode($domain) . '&per_page=1';
        self::log("getZoneId — looking up zone for {$domain}");

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !$response) {
            self::log("getZoneId — HTTP {$httpCode}");
            return null;
        }

        $data = json_decode($response, true);
        $zones = $data['result'] ?? [];
        if (empty($zones)) {
            self::log("getZoneId — no zone found for {$domain}");
            return null;
        }

        $id = $zones[0]['id'] ?? null;
        self::log("getZoneId — found zone {$id}");
        return $id;
    }

    // ─── File System ────────────────────────────────────────────

    /**
     * Parse the .cfignore file from the static directory root.
     */
    public static function loadCfIgnore(string $staticDir): array
    {
        $ignoreFile = rtrim($staticDir, '/') . '/.cfignore';
        if (!file_exists($ignoreFile)) {
            return [];
        }

        $lines = file($ignoreFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $patterns = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $patterns[] = $line;
        }
        return $patterns;
    }

    /**
     * Check if a relative path matches any .cfignore pattern.
     */
    public static function isExcluded(string $relativePath, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($relativePath === $pattern || $relativePath === ltrim($pattern, '/')) {
                return true;
            }

            $dirPattern = rtrim($pattern, '/') . '/';
            if (str_starts_with($relativePath, $dirPattern) || str_starts_with($relativePath, ltrim($dirPattern, '/'))) {
                return true;
            }

            if (fnmatch($pattern, $relativePath) || fnmatch($pattern, basename($relativePath))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count files recursively in a directory, respecting .cfignore.
     */
    public static function countFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $patterns = self::loadCfIgnore($dir);
        $baseLen = strlen(rtrim($dir, '/')) + 1;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), $baseLen);
                if (!self::isExcluded($rel, $patterns)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Build MD5 checksum map for all files in a directory, respecting .cfignore.
     * Returns array keyed by "/relative/path" => md5 hash.
     * Paths are prefixed with "/" to match Cloudflare API format.
     */
    public static function checksumMap(string $dir): array
    {
        self::log("checksumMap — scanning {$dir}");
        $map = [];
        if (!is_dir($dir)) {
            self::log("checksumMap — directory does not exist: {$dir}");
            return $map;
        }
        $patterns = self::loadCfIgnore($dir);
        $baseLen = strlen(rtrim($dir, '/')) + 1;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $rel = substr($file->getPathname(), $baseLen);
                if (!self::isExcluded($rel, $patterns)) {
                    $map['/' . $rel] = md5_file($file->getPathname());
                }
            }
        }
        self::log("checksumMap — hashed " . count($map) . " files");
        return $map;
    }

    /**
     * Compare local checksums against deployed checksums.
     * Returns ['added' => [], 'modified' => [], 'removed' => []]
     */
    public static function diffAgainstDeployed(array $localSums, array $deployedSums): array
    {
        $added = [];
        $modified = [];
        $removed = [];

        foreach ($localSums as $path => $hash) {
            if (!isset($deployedSums[$path])) {
                $added[] = $path;
            } elseif ($deployedSums[$path] !== $hash) {
                $modified[] = $path;
            }
        }
        foreach ($deployedSums as $path => $hash) {
            if (!isset($localSums[$path])) {
                $removed[] = $path;
            }
        }

        return compact('added', 'modified', 'removed');
    }
}
