<?php
/**
 * Centralized logging for Protocol CLI.
 *
 * All log output goes to a single file: /var/log/protocol/protocol.log
 * Every entry is tagged with a component label for easy filtering:
 *   [HH:MM:SS] [shell] git pull origin main
 *   [HH:MM:SS] [shell]   → exit=0 (0.3s)
 *   [HH:MM:SS] [docker] pulling image ghcr.io/...
 *   [HH:MM:SS] [config] cloning config repo
 *
 * Usage:
 *   Log::write('shell', 'running command: git pull');
 *   Log::write('docker', 'container started');
 *   Log::cmd('git pull origin main', $output, $exitCode, $duration);
 */
namespace Gitcd\Helpers;

class Log
{
    private static ?string $logFile = null;
    private static bool $initialized = false;

    /**
     * Resolve and return the log file path.
     */
    public static function getLogFile(): string
    {
        if (self::$logFile !== null) {
            return self::$logFile;
        }

        self::$logFile = self::initLogFile();
        return self::$logFile;
    }

    /**
     * Initialize the log file path, creating directories as needed.
     */
    private static function initLogFile(): string
    {
        $logDir = '/var/log/protocol/';

        if (!is_dir($logDir)) {
            @Shell::run("sudo mkdir -p /var/log/protocol 2>/dev/null");
        }
        if (is_dir($logDir) && !is_writable($logDir)) {
            @Shell::run("sudo chmod 1777 /var/log/protocol 2>/dev/null");
        }

        // Fallback if /var/log/protocol still isn't available
        if (!is_dir($logDir) || !is_writable($logDir)) {
            $logDir = (defined('NODE_DATA_DIR') ? NODE_DATA_DIR : sys_get_temp_dir() . '/protocol/') . 'log/';
            if (!is_dir($logDir)) {
                @mkdir($logDir, 0755, true);
            }
        }

        $logFile = $logDir . 'protocol.log';

        // Rotate at 5MB
        if (is_file($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
            @rename($logFile, $logFile . '.' . date('Y-m-d-His'));
        }

        return $logFile;
    }

    /**
     * Write a tagged log line.
     *
     * @param string $tag   Component tag (e.g. 'shell', 'docker', 'config', 'git', 'deploy')
     * @param string $message  Log message
     */
    public static function write(string $tag, string $message): void
    {
        $logFile = self::getLogFile();
        $timestamp = date('H:i:s');

        // Write session header on first call
        if (!self::$initialized) {
            self::$initialized = true;
            @file_put_contents(
                $logFile,
                "\n[{$timestamp}] [protocol] === Session started at " . date('Y-m-d H:i:s') . " (PID " . getmypid() . ") ===\n",
                FILE_APPEND | LOCK_EX
            );
        }

        @file_put_contents(
            $logFile,
            "[{$timestamp}] [{$tag}] {$message}\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Log a shell command execution with its output and result.
     *
     * @param string      $command   The command that was run
     * @param string|null $output    Command stdout/stderr
     * @param int         $exitCode  Process exit code
     * @param float|null  $duration  Execution time in seconds
     */
    public static function cmd(string $command, ?string $output, int $exitCode, ?float $duration = null): void
    {
        // Skip diagnostic noise — these run every 10s via IncidentDetector and clutter the log
        if (self::isDiagnosticCommand($command) && $exitCode === 0) {
            return;
        }

        $safeCmd = self::sanitize($command);
        $durationStr = $duration !== null ? ' (' . round($duration, 2) . 's)' : '';
        $status = $exitCode === 0 ? 'ok' : "FAIL(exit={$exitCode})";

        // One line per command: command → result
        self::write('shell', "{$safeCmd} → {$status}{$durationStr}");

        // Only log output on failure so you can see what went wrong
        if ($exitCode !== 0 && $output !== null && trim($output) !== '') {
            $lines = explode("\n", trim($output));
            $lines = array_slice($lines, 0, 10);
            foreach ($lines as $line) {
                self::write('shell', "  | " . self::sanitize($line));
            }
        }
    }

    /**
     * Sanitize a string by masking tokens, keys, and secrets.
     */
    private static function sanitize(string $text): string
    {
        // GitHub access tokens: x-access-token:ghs_xxxx@
        $text = preg_replace('/x-access-token:[^@]+@/', 'x-access-token:***@', $text);
        // Authorization headers: Bearer xxx, token xxx
        $text = preg_replace('/(?:Bearer|token)\s+[A-Za-z0-9_\-\.]+/i', 'Bearer ***', $text);
        // Authorization header value in curl -H
        $text = preg_replace('/Authorization:\s*[^\'"]+/i', 'Authorization: ***', $text);
        // GitHub App tokens (ghs_, ghu_, ghp_)
        $text = preg_replace('/\b(ghs_|ghu_|ghp_)[A-Za-z0-9_]+/', '$1***', $text);
        // JWT tokens (eyJ...)
        $text = preg_replace('/\beyJ[A-Za-z0-9_\-]+\.eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+/', 'JWT:***', $text);

        return $text;
    }

    /**
     * Check if a command is a diagnostic/system probe that should not be logged.
     * These run every ~10s via IncidentDetector and create excessive noise.
     */
    private static function isDiagnosticCommand(string $command): bool
    {
        $prefixes = ['ps ', 'find ', 'who ', 'who|', 'grep '];
        foreach ($prefixes as $prefix) {
            if (strncmp($command, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Log an error.
     */
    public static function error(string $tag, string $message): void
    {
        self::write($tag, "ERROR: {$message}");
    }
}
