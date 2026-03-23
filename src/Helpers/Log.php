<?php
/**
 * Centralized logging for Protocol CLI.
 *
 * All log output goes to a single file: /var/log/protocol/protocol.log
 * Every entry is tagged with a component label and log level:
 *   [HH:MM:SS] [INFO] [deploy] new release detected: v2.1.0
 *   [HH:MM:SS] [ERROR] [deploy] clone failed for v2.1.0
 *   [HH:MM:SS] [INFO] [deploy] strategy=release active=v2.1.0 current=v2.0.9
 *
 * Log levels (controlled by PROTOCOL_LOG_LEVEL env var, default: info):
 *   Log::debug('tag', 'message');   // suppressed by default
 *   Log::info('tag', 'message');    // default threshold
 *   Log::warn('tag', 'message');    // always shown
 *   Log::error('tag', 'message');   // always shown
 *
 * Structured variable logging:
 *   Log::context('deploy', ['strategy' => 'release', 'version' => 'v2.1.0']);
 *   // Output: [HH:MM:SS] [INFO] [deploy] strategy=release version=v2.1.0
 */
namespace Gitcd\Helpers;

class Log
{
    const DEBUG = 0;
    const INFO  = 1;
    const WARN  = 2;
    const ERROR = 3;

    private static ?string $logFile = null;
    private static bool $initialized = false;
    private static ?int $level = null;
    private static ?string $operationId = null;

    private static array $levelNames = [
        self::DEBUG => 'DEBUG',
        self::INFO  => 'INFO',
        self::WARN  => 'WARN',
        self::ERROR => 'ERROR',
    ];

    /**
     * Override the log file path. Use this to redirect all logging
     * to a different file (e.g. watcher.log for the release watcher daemon).
     */
    public static function setFile(string $path): void
    {
        self::$logFile = $path;
    }

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
     * Get the configured log level threshold.
     * Set via PROTOCOL_LOG_LEVEL env var (debug, info, warn, error).
     */
    public static function getLevel(): int
    {
        if (self::$level !== null) {
            return self::$level;
        }

        $env = strtolower(getenv('PROTOCOL_LOG_LEVEL') ?: 'info');
        $map = [
            'debug' => self::DEBUG,
            'info'  => self::INFO,
            'warn'  => self::WARN,
            'error' => self::ERROR,
        ];

        self::$level = $map[$env] ?? self::INFO;
        return self::$level;
    }

    /**
     * Override the log level programmatically.
     */
    public static function setLevel(int $level): void
    {
        self::$level = $level;
    }

    /**
     * Generate or retrieve the current operation ID.
     * Used to correlate log lines across a single command invocation.
     */
    public static function getOperationId(): string
    {
        if (self::$operationId === null) {
            self::$operationId = substr(bin2hex(random_bytes(2)), 0, 4);
        }
        return self::$operationId;
    }

    /**
     * Set a specific operation ID (useful when resuming or correlating).
     */
    public static function setOperationId(string $id): void
    {
        self::$operationId = $id;
    }

    /**
     * Initialize the log file path, creating directories as needed.
     */
    private static function initLogFile(): string
    {
        $logDir = '/var/log/protocol/';

        if (!is_dir($logDir)) {
            @exec("sudo mkdir -p /var/log/protocol 2>/dev/null");
        }
        if (is_dir($logDir) && !is_writable($logDir)) {
            @exec("sudo chmod 1777 /var/log/protocol 2>/dev/null");
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
     * Write a tagged, leveled log line.
     */
    public static function write(string $tag, string $message, int $level = self::INFO): void
    {
        if ($level < self::getLevel()) {
            return;
        }

        $logFile = self::getLogFile();
        $timestamp = date('H:i:s');
        $levelName = self::$levelNames[$level] ?? 'INFO';
        $opId = self::getOperationId();

        // Write session header on first call
        if (!self::$initialized) {
            self::$initialized = true;
            @file_put_contents(
                $logFile,
                "\n[{$timestamp}] [INFO] [protocol] op={$opId} === Session started at " . date('Y-m-d H:i:s') . " (PID " . getmypid() . ") ===\n",
                FILE_APPEND | LOCK_EX
            );
        }

        @file_put_contents(
            $logFile,
            "[{$timestamp}] [{$levelName}] [{$tag}] op={$opId} {$message}\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Log at DEBUG level. Suppressed unless PROTOCOL_LOG_LEVEL=debug.
     */
    public static function debug(string $tag, string $message): void
    {
        self::write($tag, $message, self::DEBUG);
    }

    /**
     * Log at INFO level. Default threshold.
     */
    public static function info(string $tag, string $message): void
    {
        self::write($tag, $message, self::INFO);
    }

    /**
     * Log at WARN level.
     */
    public static function warn(string $tag, string $message): void
    {
        self::write($tag, $message, self::WARN);
    }

    /**
     * Log at ERROR level.
     */
    public static function error(string $tag, string $message): void
    {
        self::write($tag, $message, self::ERROR);
    }

    /**
     * Log structured key-value context at a decision point.
     *
     * Usage:
     *   Log::context('deploy', ['strategy' => 'release', 'version' => 'v2.1.0']);
     *   // Output: [HH:MM:SS] [INFO] [deploy] op=a3f2 strategy=release version=v2.1.0
     */
    public static function context(string $tag, array $data, int $level = self::INFO): void
    {
        $parts = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $value = 'null';
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = implode(',', $value);
            }
            $parts[] = "{$key}={$value}";
        }
        self::write($tag, implode(' ', $parts), $level);
    }

    /**
     * Sanitize a string by masking tokens, keys, and secrets.
     */
    public static function sanitize(string $text): string
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
}
