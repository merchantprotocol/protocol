<?php
/**
 * GitHub App authentication helper.
 *
 * Manages GitHub App credentials (App ID + private key) stored in
 * NODE_DATA_DIR. Generates JWTs and installation access tokens so
 * production servers can pull from private repos without personal
 * tokens tied to individual developers.
 */
namespace Gitcd\Helpers;

class GitHubApp
{
    /**
     * Path where GitHub App credentials are stored.
     */
    public static function credentialsPath(): string
    {
        return NODE_DATA_DIR . 'github-app.json';
    }

    /**
     * Path where the private key PEM is stored.
     */
    public static function privateKeyPath(): string
    {
        return NODE_DATA_DIR . 'github-app.pem';
    }

    /**
     * Check if GitHub App credentials are configured.
     */
    public static function isConfigured(): bool
    {
        return is_file(self::credentialsPath()) && is_file(self::privateKeyPath());
    }

    /**
     * Save GitHub App credentials.
     */
    public static function saveCredentials(string $appId, string $pemContents, string $owner): void
    {
        $dir = NODE_DATA_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Save the PEM key
        $pemPath = self::privateKeyPath();
        file_put_contents($pemPath, $pemContents, LOCK_EX);
        chmod($pemPath, 0600);

        // Save app metadata
        $data = [
            'app_id' => $appId,
            'owner' => $owner,
            'pem_path' => $pemPath,
            'created_at' => date('c'),
        ];
        $credPath = self::credentialsPath();
        file_put_contents($credPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        chmod($credPath, 0600);
    }

    /**
     * Load stored credentials.
     */
    public static function loadCredentials(): ?array
    {
        $path = self::credentialsPath();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Generate a JWT for GitHub App authentication.
     * JWTs are valid for up to 10 minutes.
     */
    public static function generateJwt(string $appId, string $pemContents): ?string
    {
        $header = self::base64urlEncode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $payload = self::base64urlEncode(json_encode([
            'iat' => time() - 60,
            'exp' => time() + (10 * 60),
            'iss' => $appId,
        ]));

        $privateKey = openssl_pkey_get_private($pemContents);
        if (!$privateKey) {
            return null;
        }

        $signature = '';
        $success = openssl_sign("{$header}.{$payload}", $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$success) {
            return null;
        }

        return "{$header}.{$payload}." . self::base64urlEncode($signature);
    }

    /**
     * Get the installation ID for a given org/owner.
     */
    public static function getInstallationId(string $jwt, string $owner): ?int
    {
        $result = Shell::run(
            "curl -s -H " . escapeshellarg("Authorization: Bearer {$jwt}")
            . " -H 'Accept: application/vnd.github+json'"
            . " https://api.github.com/app/installations 2>/dev/null"
        );

        if (!$result) {
            return null;
        }

        $installations = json_decode($result, true);
        if (!is_array($installations)) {
            return null;
        }

        foreach ($installations as $install) {
            $accountLogin = $install['account']['login'] ?? '';
            if (strcasecmp($accountLogin, $owner) === 0) {
                return (int) $install['id'];
            }
        }

        return null;
    }

    /**
     * Generate an installation access token.
     * These tokens are valid for 1 hour.
     */
    public static function generateInstallationToken(string $jwt, int $installationId): ?string
    {
        $result = Shell::run(
            "curl -s -X POST"
            . " -H " . escapeshellarg("Authorization: Bearer {$jwt}")
            . " -H 'Accept: application/vnd.github+json'"
            . " https://api.github.com/app/installations/{$installationId}/access_tokens 2>/dev/null"
        );

        if (!$result) {
            return null;
        }

        $data = json_decode($result, true);
        return $data['token'] ?? null;
    }

    /**
     * High-level: get a fresh installation access token using stored credentials.
     *
     * @return string|null The access token, or null on failure
     */
    public static function getAccessToken(?string $owner = null): ?string
    {
        $creds = self::loadCredentials();
        if (!$creds) {
            return null;
        }

        $appId = $creds['app_id'] ?? null;
        $pemPath = $creds['pem_path'] ?? self::privateKeyPath();
        $owner = $owner ?? $creds['owner'] ?? null;

        if (!$appId || !$owner || !is_file($pemPath)) {
            return null;
        }

        $pemContents = file_get_contents($pemPath);
        $jwt = self::generateJwt($appId, $pemContents);
        if (!$jwt) {
            return null;
        }

        $installationId = self::getInstallationId($jwt, $owner);
        if (!$installationId) {
            return null;
        }

        return self::generateInstallationToken($jwt, $installationId);
    }

    /**
     * Write an installation access token to the git-credentials file
     * so git can authenticate via HTTPS.
     */
    public static function writeGitCredentials(string $token): void
    {
        $credentialFile = NODE_DATA_DIR . 'git-credentials';
        $credentialEntry = "https://x-access-token:{$token}@github.com";

        // Replace any existing github.com x-access-token entries
        $existingCreds = is_file($credentialFile) ? file_get_contents($credentialFile) : '';
        $lines = array_filter(explode("\n", trim($existingCreds)), function ($line) {
            return !str_contains($line, 'x-access-token') || !str_contains($line, 'github.com');
        });
        $lines[] = $credentialEntry;
        file_put_contents($credentialFile, implode("\n", $lines) . "\n", LOCK_EX);
        chmod($credentialFile, 0600);

        // Configure git to use our credential file
        Shell::run("git config --global credential.helper 'store --file=" . escapeshellarg($credentialFile) . "'");
    }

    /**
     * Refresh the git credentials with a new installation token.
     * Call this periodically (tokens expire after 1 hour).
     */
    public static function refreshGitCredentials(?string $owner = null): bool
    {
        $token = self::getAccessToken($owner);
        if (!$token) {
            return false;
        }
        self::writeGitCredentials($token);
        return true;
    }

    /**
     * Base64url encode (JWT-safe).
     */
    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
