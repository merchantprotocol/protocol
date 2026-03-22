<?php
/**
 * AES-256-GCM encryption/decryption for secrets management.
 */
namespace Gitcd\Helpers;

class Secrets
{
    const CIPHER = 'aes-256-gcm';
    const KEY_LENGTH = 32;
    const NONCE_LENGTH = 12;
    const TAG_LENGTH = 16;

    /**
     * Determine whether secrets should be stored globally (production)
     * or per-project (all other environments).
     */
    public static function isGlobal(): bool
    {
        return !IncidentDetector::isDev();
    }

    /**
     * Path to the project-local .protocol directory.
     */
    public static function projectDataDir(): string
    {
        return WORKING_DIR . '.protocol' . DIRECTORY_SEPARATOR;
    }

    /**
     * Path to the encryption key file.
     * Production stores globally in NODE_DATA_DIR, all other envs store per-project.
     */
    public static function keyPath(): string
    {
        if (self::isGlobal()) {
            return NODE_DATA_DIR . 'key';
        }
        return self::projectDataDir() . 'key';
    }

    /**
     * Check if an encryption key exists on this node.
     */
    public static function hasKey(): bool
    {
        return is_file(self::keyPath());
    }

    /**
     * Generate a new random encryption key.
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(self::KEY_LENGTH));
    }

    /**
     * Store the encryption key to disk.
     * Location is determined by the current environment (config:env).
     */
    public static function storeKey(string $hexKey): bool
    {
        $path = self::keyPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $written = file_put_contents($path, $hexKey);
        if ($written !== false) {
            chmod($path, 0600);
        }

        if (!self::isGlobal()) {
            self::ensureGitignore();
        }

        return $written !== false;
    }

    /**
     * Read the encryption key from disk (returns raw binary key).
     */
    public static function readKey(): ?string
    {
        if (!self::hasKey()) return null;

        $hex = trim(file_get_contents(self::keyPath()));
        $key = hex2bin($hex);
        if ($key === false || strlen($key) !== self::KEY_LENGTH) {
            return null;
        }
        return $key;
    }

    /**
     * Encrypt plaintext. Returns base64(nonce + tag + ciphertext).
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::readKey();
        if (!$key) return null;

        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) return null;

        return base64_encode($nonce . $tag . $ciphertext);
    }

    /**
     * Decrypt base64(nonce + tag + ciphertext) back to plaintext.
     */
    public static function decrypt(string $encoded): ?string
    {
        $key = self::readKey();
        if (!$key) return null;

        $raw = base64_decode($encoded, true);
        if ($raw === false) return null;

        $minLength = self::NONCE_LENGTH + self::TAG_LENGTH + 1;
        if (strlen($raw) < $minLength) return null;

        $nonce = substr($raw, 0, self::NONCE_LENGTH);
        $tag = substr($raw, self::NONCE_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        return $plaintext === false ? null : $plaintext;
    }

    /**
     * Encrypt a file to another file.
     */
    public static function encryptFile(string $inputPath, string $outputPath): bool
    {
        if (!is_file($inputPath)) return false;

        $plaintext = file_get_contents($inputPath);
        $encrypted = self::encrypt($plaintext);
        if ($encrypted === null) return false;

        return file_put_contents($outputPath, $encrypted) !== false;
    }

    /**
     * Decrypt a file and return its contents.
     */
    public static function decryptFile(string $inputPath): ?string
    {
        if (!is_file($inputPath)) return null;

        $encoded = file_get_contents($inputPath);
        return self::decrypt($encoded);
    }

    /**
     * Decrypt to a temporary file (prefers /dev/shm on Linux for RAM-backed storage).
     * Returns the temp file path.
     */
    public static function decryptToTempFile(string $inputPath): ?string
    {
        $plaintext = self::decryptFile($inputPath);
        if ($plaintext === null) return null;

        // Prefer RAM-backed tmpfs on Linux
        $tmpDir = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
        $tmpFile = $tmpDir . '/.protocol-env-' . getmypid();

        $written = file_put_contents($tmpFile, $plaintext);
        if ($written === false) return null;

        chmod($tmpFile, 0600);
        return $tmpFile;
    }

    /**
     * Ensure .protocol/ is listed in the project's .gitignore.
     */
    public static function ensureGitignore(): void
    {
        $gitignorePath = WORKING_DIR . '.gitignore';
        $entry = '.protocol/';

        if (is_file($gitignorePath)) {
            $contents = file_get_contents($gitignorePath);
            // Check if already present (exact line match)
            $lines = array_map('trim', explode("\n", $contents));
            if (in_array($entry, $lines)) {
                return;
            }
            // Append with a newline if file doesn't end with one
            $append = (substr($contents, -1) === "\n" ? '' : "\n") . $entry . "\n";
            file_put_contents($gitignorePath, $contents . $append);
        } else {
            file_put_contents($gitignorePath, $entry . "\n");
        }
    }
}
