<?php
/**
 * Centralized file encryption/decryption operations.
 *
 * Extracts the repeated file-iteration and encrypt/decrypt patterns
 * from ConfigInit, ConfigLink, and other commands into one place.
 */
namespace Gitcd\Helpers;

use Symfony\Component\Console\Output\OutputInterface;

class FileEncryption
{
    /**
     * Encrypt all matching files in a directory.
     *
     * Scans for files NOT ending in .enc and encrypts them, creating .enc versions.
     *
     * @param string $directory  Directory to scan for files to encrypt
     * @param OutputInterface $output  Console output for progress messages
     * @param array $exclude  Filenames to skip (e.g., ['.gitignore', 'README.md'])
     * @return int  Number of files encrypted
     */
    public static function encryptDirectory(string $directory, OutputInterface $output, array $exclude = ['.gitignore', 'README.md', '.git']): int
    {
        if (!Secrets::hasKey()) {
            $output->writeln('<error>No encryption key found. Run: protocol secrets:setup</error>');
            return 0;
        }

        $count = 0;
        $files = Dir::dirToArray($directory, $exclude);

        foreach ($files as $fullPath) {
            if (is_dir($fullPath)) continue;
            if (str_ends_with($fullPath, '.enc')) continue;

            $encPath = $fullPath . '.enc';

            if (Secrets::encryptFile($fullPath, $encPath)) {
                $output->writeln("  <fg=green>✓</> Encrypted: " . basename($fullPath));
                $count++;
            } else {
                $output->writeln("  <fg=red>✗</> Failed to encrypt: " . basename($fullPath));
            }
        }

        return $count;
    }

    /**
     * Decrypt all .enc files in a directory, writing plaintext alongside them.
     *
     * @param string $directory  Directory to scan for .enc files
     * @param OutputInterface $output  Console output for progress messages
     * @param array $exclude  Filenames to skip
     * @return array  Array of ['source' => encName, 'decrypted' => plainName, 'path' => plainPath] entries
     */
    public static function decryptDirectory(string $directory, OutputInterface $output, array $exclude = ['.gitignore', 'README.md', '.git']): array
    {
        if (!Secrets::hasKey()) {
            $output->writeln('<error>No encryption key found. Run: protocol secrets:setup</error>');
            return [];
        }

        $decrypted = [];
        $files = Dir::dirToArray($directory, $exclude);

        foreach ($files as $fullPath) {
            if (is_dir($fullPath)) continue;
            if (!str_ends_with($fullPath, '.enc')) continue;

            $plaintext = Secrets::decryptFile($fullPath);
            if ($plaintext === null) {
                $output->writeln("  <fg=red>✗</> Failed to decrypt: " . basename($fullPath));
                continue;
            }

            $plainPath = substr($fullPath, 0, -4);
            file_put_contents($plainPath, $plaintext);
            chmod($plainPath, 0600);

            $encName = basename($fullPath);
            $plainName = basename($plainPath);
            $output->writeln("  <fg=green>✓</> Decrypted: {$encName}");

            $decrypted[] = [
                'source'    => $encName,
                'decrypted' => $plainName,
                'path'      => $plainPath,
            ];
        }

        return $decrypted;
    }

    /**
     * Encrypt specific .env files and remove the plaintext originals.
     *
     * Used by ConfigInit flows that target only unencrypted .env* files.
     *
     * @param string $directory  Config repo directory
     * @param array $envNames   Basenames of files to encrypt (e.g. ['.env', '.env.local'])
     * @param OutputInterface $output
     * @return array  Array of basenames that were successfully encrypted
     */
    public static function encryptEnvFiles(string $directory, array $envNames, OutputInterface $output): array
    {
        $encrypted = [];
        $dir = rtrim($directory, '/') . '/';

        foreach ($envNames as $envName) {
            $envPath = $dir . $envName;
            $encPath = $envPath . '.enc';

            if (Secrets::encryptFile($envPath, $encPath)) {
                unlink($envPath);
                Git::addIgnore($envName, $directory);
                $output->writeln("    <fg=green>✓</> <fg=white>{$envName}</> → <fg=white>{$envName}.enc</>");
                $encrypted[] = $envName;
            } else {
                $output->writeln("    <error>  Failed to encrypt {$envName}</error>");
            }
        }

        return $encrypted;
    }

    /**
     * Find unencrypted .env files in a directory.
     *
     * @param string $directory  Directory to scan
     * @return array  Basenames of .env* files that don't end in .enc
     */
    public static function findUnencryptedEnvFiles(string $directory): array
    {
        $envFiles = glob(rtrim($directory, '/') . '/.env*');
        $unencrypted = [];
        foreach ($envFiles as $f) {
            if (!str_ends_with($f, '.enc') && is_file($f)) {
                $unencrypted[] = basename($f);
            }
        }
        return $unencrypted;
    }
}
