<?php
/**
 * Disk usage checker — detects low disk space and whether Docker is a contributor.
 */
namespace Gitcd\Helpers;

use Gitcd\Helpers\Shell;

class DiskCheck
{
    // Thresholds (percentage used)
    const WARN_THRESHOLD  = 80;
    const ALERT_THRESHOLD = 90;

    /**
     * Check disk usage on the root partition.
     * Returns an associative array with disk info and warnings.
     *
     * @return array{percent: int, total: string, used: string, available: string, level: string, docker: array|null}
     */
    public static function check(): array
    {
        $result = [
            'percent'   => 0,
            'total'     => '',
            'used'      => '',
            'available' => '',
            'level'     => 'ok',       // ok | warn | alert
            'docker'    => null,
        ];

        // Get root partition usage
        $df = Shell::run("df -h / 2>/dev/null | tail -1");
        if (!$df) return $result;

        // Parse: Filesystem Size Used Avail Use% Mounted
        $parts = preg_split('/\s+/', trim($df));
        if (count($parts) < 5) return $result;

        $result['total']     = $parts[1];
        $result['used']      = $parts[2];
        $result['available'] = $parts[3];
        $result['percent']   = (int) str_replace('%', '', $parts[4]);

        if ($result['percent'] >= self::ALERT_THRESHOLD) {
            $result['level'] = 'alert';
        } elseif ($result['percent'] >= self::WARN_THRESHOLD) {
            $result['level'] = 'warn';
        }

        // If disk is getting full, check Docker's contribution
        if ($result['level'] !== 'ok') {
            $result['docker'] = self::checkDockerUsage();
        }

        return $result;
    }

    /**
     * Get Docker's disk usage breakdown.
     *
     * @return array{images: string, containers: string, volumes: string, buildcache: string, total: string, reclaimable: string}|null
     */
    public static function checkDockerUsage(): ?array
    {
        $raw = Shell::run('docker system df --format "{{.Type}}\t{{.Size}}\t{{.Reclaimable}}" 2>/dev/null');
        if (!$raw || trim($raw) === '') return null;

        $docker = [
            'images'      => '0B',
            'containers'  => '0B',
            'volumes'     => '0B',
            'buildcache'  => '0B',
            'total'       => '',
            'reclaimable' => '',
        ];

        foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
            $cols = explode("\t", $line);
            if (count($cols) < 3) continue;

            $type = strtolower(trim($cols[0]));
            $size = trim($cols[1]);
            $reclaim = trim($cols[2]);

            if (strpos($type, 'image') !== false) {
                $docker['images'] = $size;
            } elseif (strpos($type, 'container') !== false) {
                $docker['containers'] = $size;
            } elseif (strpos($type, 'volume') !== false) {
                $docker['volumes'] = $size;
            } elseif (strpos($type, 'build') !== false) {
                $docker['buildcache'] = $size;
            }
        }

        // Get the total reclaimable from docker system df (verbose-free)
        $totalRaw = Shell::run('docker system df 2>/dev/null');
        if ($totalRaw) {
            $totalReclaim = '0B';
            foreach (array_filter(array_map('trim', explode("\n", $totalRaw))) as $line) {
                if (preg_match('/\((\d+[\d.]*\s*[A-Za-z]+)\s+reclaimable\)/i', $line)) {
                    // We just note that reclaimable space exists
                }
            }
        }

        // Sum reclaimable from formatted output
        $reclaimRaw = Shell::run('docker system df --format "{{.Reclaimable}}" 2>/dev/null');
        if ($reclaimRaw) {
            $totalBytes = 0;
            foreach (array_filter(array_map('trim', explode("\n", $reclaimRaw))) as $val) {
                $totalBytes += self::parseSize($val);
            }
            $docker['reclaimable'] = self::humanSize($totalBytes);
        }

        return $docker;
    }

    /**
     * Parse a human-readable size string to bytes.
     */
    private static function parseSize(string $size): int
    {
        // Handle formats like "1.2GB", "500MB", "1.2GB (50%)"
        if (!preg_match('/([\d.]+)\s*(B|KB|MB|GB|TB|kB)/i', $size, $m)) return 0;

        $val = (float) $m[1];
        $unit = strtoupper($m[2]);

        return (int) match($unit) {
            'TB' => $val * 1099511627776,
            'GB' => $val * 1073741824,
            'MB' => $val * 1048576,
            'KB', 'KB' => $val * 1024,
            default => $val,
        };
    }

    /**
     * Convert bytes to human-readable.
     */
    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1073741824) return sprintf('%.1fGB', $bytes / 1073741824);
        if ($bytes >= 1048576) return sprintf('%.0fMB', $bytes / 1048576);
        if ($bytes >= 1024) return sprintf('%.0fKB', $bytes / 1024);
        return $bytes . 'B';
    }

    /**
     * Format disk check results as output lines for StageRunner or status commands.
     *
     * @return string[] Lines to display as warnings (empty if disk is fine)
     */
    public static function formatWarnings(array $check): array
    {
        if ($check['level'] === 'ok') return [];

        $lines = [];
        $color = $check['level'] === 'alert' ? 'red' : 'yellow';

        $lines[] = "<fg={$color}>Disk {$check['percent']}% full</> — {$check['used']} of {$check['total']} used, {$check['available']} available";

        if ($check['docker']) {
            $d = $check['docker'];
            $lines[] = "<fg=gray>Docker usage:</> images {$d['images']}, containers {$d['containers']}, volumes {$d['volumes']}, build cache {$d['buildcache']}";

            if ($d['reclaimable'] && $d['reclaimable'] !== '0B') {
                $lines[] = "<fg={$color}>Docker has {$d['reclaimable']} reclaimable</> — run <fg=white>protocol docker:cleanup</>";
            }
        }

        return $lines;
    }
}
