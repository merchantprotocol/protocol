<?php
/**
 * Deployment audit log for SOC 2 readiness.
 */
namespace Gitcd\Helpers;

class AuditLog
{
    /**
     * Path to the deployment audit log.
     */
    public static function logPath(): string
    {
        $dir = '/var/log/protocol/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_writable($dir)) {
            // Fallback for local dev
            $dir = NODE_DATA_DIR;
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
        return $dir . 'deployments.log';
    }

    /**
     * Write a structured log entry. Automatically appends the current user.
     */
    public static function write(string $action, string $repo_dir, array $data = []): void
    {
        $data['user'] = $data['user'] ?? get_current_user();

        $entry = date('Y-m-d\TH:i:sP') . " {$action} repo=" . escapeshellarg($repo_dir);
        foreach ($data as $key => $value) {
            $entry .= " {$key}=" . escapeshellarg($value);
        }
        $entry .= "\n";

        $path = self::logPath();
        $isNew = !is_file($path);
        file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);

        // Restrict permissions on new log files (SOC 2 CC7)
        if ($isNew) {
            chmod($path, 0600);
        }

        // Auto-rotate if log exceeds 5 MB
        self::rotate();
    }

    /**
     * Log a deployment event.
     */
    public static function logDeploy(string $repo_dir, string $from, string $to, string $status = 'success', string $scope = 'global'): void
    {
        self::write('DEPLOY', $repo_dir, compact('from', 'to', 'status', 'scope'));
    }

    /**
     * Log a rollback event.
     */
    public static function logRollback(string $repo_dir, string $from, string $to, string $status = 'success', string $scope = 'global'): void
    {
        self::write('ROLLBACK', $repo_dir, compact('from', 'to', 'status', 'scope'));
    }

    /**
     * Log a config change event.
     */
    public static function logConfig(string $repo_dir, string $action, string $detail = ''): void
    {
        self::write('CONFIG', $repo_dir, compact('action', 'detail'));
    }

    /**
     * Log a docker event.
     */
    public static function logDocker(string $repo_dir, string $action, string $detail = ''): void
    {
        self::write('DOCKER', $repo_dir, compact('action', 'detail'));
    }

    /**
     * Log a shadow deployment event (build, promote, rollback).
     */
    public static function logShadow(string $repo_dir, string $action, string $slot, string $version, string $status = 'success'): void
    {
        self::write('SHADOW', $repo_dir, compact('action', 'slot', 'version', 'status'));
    }

    /**
     * Log a change-approval event capturing PR metadata.
     */
    public static function logApproval(string $repo_dir, string $version, array $prData): void
    {
        self::write('APPROVAL', $repo_dir, [
            'version' => $version,
            'pr_number' => $prData['number'] ?? '',
            'pr_title' => $prData['title'] ?? '',
            'pr_author' => $prData['author'] ?? '',
            'pr_approvers' => $prData['approvers'] ?? '',
            'pr_merged_by' => $prData['merged_by'] ?? '',
            'pr_merged_at' => $prData['merged_at'] ?? '',
            'pr_url' => $prData['url'] ?? '',
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

    /**
     * Rotate the audit log if it exceeds the size threshold.
     *
     * Archives the current log with a date-stamped suffix and starts a fresh
     * file. Keeps up to 12 months of archives (52 weekly files).
     */
    public static function rotate(int $maxBytes = 5242880, int $maxArchives = 52): void
    {
        $path = self::logPath();
        if (!is_file($path)) return;

        if (filesize($path) < $maxBytes) return;

        $archivePath = $path . '.' . date('Y-m-d') . '.gz';

        // Don't rotate if today's archive already exists
        if (is_file($archivePath)) return;

        // Compress the current log into a gzipped archive
        $contents = file_get_contents($path);
        $gz = gzopen($archivePath, 'w9');
        if ($gz) {
            gzwrite($gz, $contents);
            gzclose($gz);
            chmod($archivePath, 0600);

            // Truncate the live log
            file_put_contents($path, '', LOCK_EX);
        }

        // Prune old archives beyond retention limit
        $dir = dirname($path);
        $base = basename($path);
        $archives = glob($dir . '/' . $base . '.*.gz');
        if ($archives && count($archives) > $maxArchives) {
            sort($archives); // oldest first
            $toRemove = array_slice($archives, 0, count($archives) - $maxArchives);
            foreach ($toRemove as $old) {
                unlink($old);
            }
        }
    }
}
