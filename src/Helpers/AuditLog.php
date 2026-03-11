<?php
/**
 * Deployment audit log for SOC 2 compliance.
 */
namespace Gitcd\Helpers;

class AuditLog
{
    /**
     * Path to the deployment audit log.
     */
    public static function logPath(): string
    {
        $dir = ($_SERVER['HOME'] ?? getenv('HOME')) . '/.protocol';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        return $dir . '/deployments.log';
    }

    /**
     * Write a structured log entry.
     */
    protected static function write(string $action, string $repo_dir, array $data = []): void
    {
        $entry = date('Y-m-d\TH:i:sP') . " {$action} repo=" . escapeshellarg($repo_dir);
        foreach ($data as $key => $value) {
            $entry .= " {$key}=" . escapeshellarg($value);
        }
        $entry .= "\n";

        file_put_contents(self::logPath(), $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log a deployment event.
     */
    public static function logDeploy(string $repo_dir, string $from, string $to, string $status = 'success', string $scope = 'global'): void
    {
        self::write('DEPLOY', $repo_dir, [
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'scope' => $scope,
            'user' => get_current_user(),
        ]);
    }

    /**
     * Log a rollback event.
     */
    public static function logRollback(string $repo_dir, string $from, string $to, string $status = 'success', string $scope = 'global'): void
    {
        self::write('ROLLBACK', $repo_dir, [
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'scope' => $scope,
            'user' => get_current_user(),
        ]);
    }

    /**
     * Log a config change event.
     */
    public static function logConfig(string $repo_dir, string $action, string $detail = ''): void
    {
        self::write('CONFIG', $repo_dir, [
            'action' => $action,
            'detail' => $detail,
            'user' => get_current_user(),
        ]);
    }

    /**
     * Log a docker event.
     */
    public static function logDocker(string $repo_dir, string $action, string $detail = ''): void
    {
        self::write('DOCKER', $repo_dir, [
            'action' => $action,
            'detail' => $detail,
            'user' => get_current_user(),
        ]);
    }

    /**
     * Read log entries (returns array of lines).
     */
    public static function read(int $limit = 50): array
    {
        $path = self::logPath();
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return [];

        return array_slice($lines, -$limit);
    }
}
