<?php
namespace Gitcd\Plugins\awssecrets;

use Gitcd\Utils\Json;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Shell;
use Aws\SecretsManager\SecretsManagerClient;
use Aws\Exception\AwsException;

class AwsSecretsHelper
{
    /**
     * Read a project-level AWS config value from protocol.json.
     */
    public static function config(string $key, $default = null, $repoDir = false)
    {
        return Json::read("aws.{$key}", $default, $repoDir);
    }

    /**
     * Get the configured AWS region.
     */
    public static function region($repoDir = false): string
    {
        return self::config('region', 'us-east-1', $repoDir);
    }

    /**
     * Get the secret name in AWS Secrets Manager.
     */
    public static function secretName($repoDir = false): string
    {
        $default = self::defaultSecretName($repoDir);
        return self::config('secret_name', $default, $repoDir);
    }

    /**
     * Generate the default secret name based on project and environment.
     */
    public static function defaultSecretName($repoDir = false): string
    {
        $projectName = Json::read('name', '', $repoDir);
        if (!$projectName && $repoDir) {
            $projectName = basename(rtrim($repoDir, '/'));
        }
        $environment = Config::read('env', 'production');
        return "protocol/{$projectName}/{$environment}";
    }

    /**
     * Get an AWS Secrets Manager client.
     */
    public static function getClient($repoDir = false): SecretsManagerClient
    {
        $config = [
            'region' => self::region($repoDir),
            'version' => 'latest',
        ];

        $profile = self::config('profile', null, $repoDir);
        if ($profile) {
            $config['profile'] = $profile;
        }

        return new SecretsManagerClient($config);
    }

    // ─── Logging ────────────────────────────────────────────────

    /**
     * Write a log entry to the aws-secrets log.
     */
    public static function log(string $message): void
    {
        $dir = NODE_DATA_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $logFile = $dir . 'aws-secrets.log';
        $entry = date('Y-m-d\TH:i:sP') . ' ' . $message . "\n";
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    // ─── .env ↔ JSON Conversion ─────────────────────────────────

    /**
     * Parse .env file contents into a JSON string.
     * Skips blank lines and comments.
     */
    public static function envToJson(string $envContents): string
    {
        $result = [];
        $lines = explode("\n", $envContents);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blank lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split on first = only
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eqPos));
            $value = substr($line, $eqPos + 1);

            // Strip surrounding quotes if present
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Convert a JSON string of key-value pairs back to .env format.
     */
    public static function jsonToEnv(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return '';
        }

        $lines = [];
        foreach ($data as $key => $value) {
            // Quote values that contain spaces, #, or special chars
            if (preg_match('/[\s#\$"\'\\\\]/', $value)) {
                $value = '"' . addcslashes($value, '"\\') . '"';
            }
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    // ─── AWS Secrets Manager Operations ─────────────────────────

    /**
     * Push .env contents to AWS Secrets Manager.
     * Creates the secret if it doesn't exist, updates it otherwise.
     *
     * @return bool True on success
     */
    public static function pushSecretAs(string $envContents, string $secretName, $repoDir = false): bool
    {
        $client = self::getClient($repoDir);
        $secretString = self::envToJson($envContents);

        self::log("Pushing secret: {$secretName}");

        try {
            $client->putSecretValue([
                'SecretId' => $secretName,
                'SecretString' => $secretString,
            ]);
            self::log("Secret updated: {$secretName}");
            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                try {
                    $client->createSecret([
                        'Name' => $secretName,
                        'SecretString' => $secretString,
                        'Description' => 'Protocol managed environment secrets',
                    ]);
                    self::log("Secret created: {$secretName}");
                    return true;
                } catch (AwsException $createEx) {
                    self::log("ERROR creating secret: " . $createEx->getMessage());
                    return false;
                }
            }
            self::log("ERROR pushing secret: " . $e->getMessage());
            return false;
        }
    }

    public static function pushSecret(string $envContents, $repoDir = false): bool
    {
        $client = self::getClient($repoDir);
        $secretName = self::secretName($repoDir);
        $secretString = self::envToJson($envContents);

        self::log("Pushing secret: {$secretName}");

        try {
            // Try to update existing secret
            $client->putSecretValue([
                'SecretId' => $secretName,
                'SecretString' => $secretString,
            ]);
            self::log("Secret updated: {$secretName}");
            return true;
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                // Secret doesn't exist yet, create it
                try {
                    $client->createSecret([
                        'Name' => $secretName,
                        'SecretString' => $secretString,
                        'Description' => 'Protocol managed environment secrets',
                    ]);
                    self::log("Secret created: {$secretName}");
                    return true;
                } catch (AwsException $createEx) {
                    self::log("ERROR creating secret: " . $createEx->getMessage());
                    return false;
                }
            }
            self::log("ERROR pushing secret: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Pull secret from AWS Secrets Manager and return as .env string.
     *
     * @return string|null .env formatted string, or null on failure
     */
    public static function pullSecret($repoDir = false): ?string
    {
        $client = self::getClient($repoDir);
        $secretName = self::secretName($repoDir);

        self::log("Pulling secret: {$secretName}");

        try {
            $result = $client->getSecretValue([
                'SecretId' => $secretName,
            ]);

            $secretString = $result['SecretString'] ?? null;
            if (!$secretString) {
                self::log("ERROR: Secret has no SecretString");
                return null;
            }

            self::log("Secret retrieved: {$secretName}");
            return self::jsonToEnv($secretString);
        } catch (AwsException $e) {
            self::log("ERROR pulling secret: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Pull secret and write to a RAM-backed temp file.
     * Caller MUST unlink() the returned path after use.
     *
     * @return string|null Temp file path, or null on failure
     */
    public static function pullToTempFile($repoDir = false): ?string
    {
        $envContents = self::pullSecret($repoDir);
        if ($envContents === null) {
            return null;
        }

        // Prefer RAM-backed tmpfs on Linux
        $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $tmpFile = $tmpDir . '/.protocol-env-' . getmypid();

        file_put_contents($tmpFile, $envContents);
        chmod($tmpFile, 0600);

        self::log("Secret written to temp file: {$tmpFile}");

        return $tmpFile;
    }

    /**
     * Get secret metadata (ARN, last changed, version info).
     *
     * @return array|null Secret description, or null on failure
     */
    public static function describeSecret($repoDir = false): ?array
    {
        $client = self::getClient($repoDir);
        $secretName = self::secretName($repoDir);

        try {
            $result = $client->describeSecret([
                'SecretId' => $secretName,
            ]);
            return $result->toArray();
        } catch (AwsException $e) {
            self::log("ERROR describing secret: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the key names stored in the secret (without values).
     *
     * @return array List of key names, or empty array on failure
     */
    public static function getSecretKeys($repoDir = false): array
    {
        $client = self::getClient($repoDir);
        $secretName = self::secretName($repoDir);

        try {
            $result = $client->getSecretValue([
                'SecretId' => $secretName,
            ]);
            $data = json_decode($result['SecretString'] ?? '{}', true);
            return is_array($data) ? array_keys($data) : [];
        } catch (AwsException $e) {
            return [];
        }
    }
}
