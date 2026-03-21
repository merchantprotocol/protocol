<?php
/**
 * NOTICE OF LICENSE
 *
 * MIT License
 *
 * Copyright (c) 2019 Merchant Protocol
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 *
 * @category   merchantprotocol
 * @package    merchantprotocol/protocol
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;

class SecretsProvider
{
    /**
     * Resolve secrets to a RAM-backed temp file based on the deployment.secrets mode.
     *
     * For 'aws' mode: merges the existing .env (non-secret config like LOG_LEVEL)
     * with secrets from AWS Secrets Manager. AWS values override .env duplicates.
     *
     * For 'encrypted' mode: decrypts the full .env.enc (which contains everything).
     *
     * Returns the temp file path, or null if mode is 'file' or resolution fails.
     * Caller MUST unlink() the returned path after use.
     *
     * @param string $repoDir The repository directory
     * @return string|null Path to temp file containing .env contents, or null
     */
    public static function resolveToTempFile(string $repoDir): ?string
    {
        $mode = Json::read('deployment.secrets', 'file', $repoDir);

        if ($mode === 'encrypted') {
            $configRepo = Config::repo($repoDir);
            if (!$configRepo) {
                return null;
            }
            $encFile = $configRepo . '.env.enc';
            if (!is_file($encFile) || !Secrets::hasKey()) {
                return null;
            }
            return Secrets::decryptToTempFile($encFile);
        }

        if ($mode === 'aws') {
            // Dynamic load to avoid hard dependency on the plugin
            if (!class_exists('\\Gitcd\\Plugins\\awssecrets\\AwsSecretsHelper')) {
                return null;
            }

            // Pull secrets from AWS
            $awsEnv = \Gitcd\Plugins\awssecrets\AwsSecretsHelper::pullSecret($repoDir);
            if ($awsEnv === null) {
                return null;
            }

            // Read the existing .env (non-secret config like LOG_LEVEL, APP_ENV)
            $baseEnv = self::readBaseEnv($repoDir);

            // Merge: start with base .env, then overlay AWS secrets
            $merged = self::mergeEnv($baseEnv, $awsEnv);

            // Write to RAM-backed temp file
            $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
            $tmpFile = $tmpDir . '/.protocol-env-' . getmypid();
            file_put_contents($tmpFile, $merged);
            chmod($tmpFile, 0600);

            return $tmpFile;
        }

        return null;
    }

    /**
     * Read the base .env file from the config repo or project directory.
     */
    private static function readBaseEnv(string $repoDir): string
    {
        // Check config repo first (where config:link symlinks from)
        $configRepo = Config::repo($repoDir);
        if ($configRepo && is_file($configRepo . '.env')) {
            return file_get_contents($configRepo . '.env');
        }

        // Fall back to project directory .env (may be a symlink from config:link)
        $projectEnv = rtrim($repoDir, '/') . '/.env';
        if (is_file($projectEnv)) {
            return file_get_contents($projectEnv);
        }

        return '';
    }

    /**
     * Merge two .env strings. Values from $overlay override $base.
     * Comments and blank lines from $base are preserved.
     */
    private static function mergeEnv(string $base, string $overlay): string
    {
        // Parse overlay into key => value map
        $overlayVars = [];
        foreach (explode("\n", $overlay) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos !== false) {
                $key = trim(substr($line, 0, $eqPos));
                $overlayVars[$key] = substr($line, $eqPos + 1);
            }
        }

        // Process base: update existing keys, keep comments/blanks
        $seenKeys = [];
        $result = [];
        foreach (explode("\n", $base) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $result[] = $line;
                continue;
            }
            $eqPos = strpos($trimmed, '=');
            if ($eqPos !== false) {
                $key = trim(substr($trimmed, 0, $eqPos));
                $seenKeys[$key] = true;
                if (isset($overlayVars[$key])) {
                    // Override with AWS value
                    $result[] = "{$key}={$overlayVars[$key]}";
                } else {
                    $result[] = $line;
                }
            } else {
                $result[] = $line;
            }
        }

        // Append any AWS keys not already in base
        foreach ($overlayVars as $key => $value) {
            if (!isset($seenKeys[$key])) {
                $result[] = "{$key}={$value}";
            }
        }

        return implode("\n", $result) . "\n";
    }
}
