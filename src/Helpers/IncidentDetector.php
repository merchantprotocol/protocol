<?php
/**
 * Lightweight incident detection — checks system state quickly to determine
 * if there is an active incident condition that the operator should know about.
 *
 * Used by the alert banner and incident:status command.
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

class IncidentDetector
{
    private const DEV_ENVS = ['localhost', 'local', 'dev', 'development'];

    /**
     * Check if the current environment is a development environment.
     * Dev environments have relaxed detection — containers down and watchers
     * stopped are normal on a dev box.
     */
    public static function isDev(?string $repoDir = null): bool
    {
        $environment = Config::read('env', false);
        if (!$environment) return true; // No env set = assume dev

        if (in_array($environment, self::DEV_ENVS)) return true;
        if (strpos($environment, 'localhost') !== false) return true;

        return false;
    }

    /**
     * Quick check — returns an array of detected issues, or empty if all clear.
     * Each issue: ['level' => 'P1'|'P2'|'P3'|'P4', 'message' => string]
     *
     * In dev environments, infrastructure checks (containers, watchers) are
     * skipped — only security-critical checks (suspicious processes, SSH keys,
     * /tmp executables) are run.
     */
    public static function detect(?string $repoDir = null): array
    {
        $issues = [];
        $isDev = self::isDev($repoDir);

        // ── Infrastructure checks (production only) ────────────
        if (!$isDev) {
            // Check for containers that should be running but aren't
            if ($repoDir && Docker::isDockerInitialized($repoDir)) {
                $containerNames = Docker::getContainerNamesFromDockerComposeFile($repoDir);
                $downContainers = [];
                foreach ($containerNames as $name) {
                    if (!Docker::isDockerContainerRunning($name)) {
                        $downContainers[] = $name;
                    }
                }
                if (count($downContainers) > 1) {
                    $issues[] = ['level' => 'P1', 'message' => count($downContainers) . ' containers down: ' . implode(', ', $downContainers)];
                } elseif (count($downContainers) === 1) {
                    $issues[] = ['level' => 'P2', 'message' => 'Container down: ' . $downContainers[0]];
                }
            }

            // Check for release watcher not running when it should be
            if ($repoDir) {
                $strategy = Json::read('deployment.strategy', 'branch', $repoDir);
                $watcherPidKey = $strategy === 'release' ? 'release.slave.pid' : 'slave.pid';
                $watcherPid = JsonLock::read($watcherPidKey, null, $repoDir);
                if ($watcherPid && !Shell::isRunning($watcherPid)) {
                    $issues[] = ['level' => 'P3', 'message' => ucfirst($strategy) . ' watcher (PID ' . $watcherPid . ') is not running'];
                }
            }

            // Check for active root login (normal on dev machines)
            $rootSsh = Shell::run("who 2>/dev/null | grep -i root");
            if ($rootSsh && trim($rootSsh) !== '') {
                $issues[] = ['level' => 'P2', 'message' => 'Active root login session detected'];
            }
        }

        // ── Security checks (always run, all environments) ─────

        // Check for suspicious processes (fast check)
        $suspicious = ['xmrig', 'cryptominer', 'kinsing', 'dota', 'tsunami', 'ncrack', 'hydra'];
        $procs = strtolower(Shell::run("ps -eo comm 2>/dev/null") ?: '');
        foreach ($suspicious as $name) {
            if (strpos($procs, $name) !== false) {
                $issues[] = ['level' => 'P1', 'message' => 'Suspicious process detected: ' . $name];
            }
        }

        // Check for SSH authorized_keys modified recently
        $authKeys = Shell::run("find /root/.ssh /home/*/.ssh -name authorized_keys -mtime -1 2>/dev/null");
        if ($authKeys && trim($authKeys) !== '') {
            $issues[] = ['level' => 'P1', 'message' => 'SSH authorized_keys modified in last 24h'];
        }

        // Check for executables in /tmp
        $tmpExec = Shell::run("find /tmp /var/tmp -maxdepth 2 -type f -perm +111 -not -name '*.sh' 2>/dev/null | head -3");
        if (!$tmpExec) {
            $tmpExec = Shell::run("find /tmp /var/tmp -maxdepth 2 -type f -executable -not -name '*.sh' 2>/dev/null | head -3");
        }
        if ($tmpExec && trim($tmpExec) !== '') {
            $n = count(array_filter(explode("\n", $tmpExec)));
            $issues[] = ['level' => 'P2', 'message' => $n . ' executable(s) found in /tmp'];
        }

        return $issues;
    }

    /**
     * Get the highest (most severe) level from a set of issues.
     */
    public static function highestSeverity(array $issues): ?string
    {
        if (empty($issues)) return null;

        $priority = ['P1' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4];
        $highest = 'P4';

        foreach ($issues as $issue) {
            $level = $issue['level'] ?? 'P4';
            if (($priority[$level] ?? 4) < ($priority[$highest] ?? 4)) {
                $highest = $level;
            }
        }

        return $highest;
    }

    /**
     * Returns true if any issues are P1 or P2 (warrant an alert banner).
     */
    public static function hasActiveIncident(?string $repoDir = null): bool
    {
        $issues = self::detect($repoDir);
        if (empty($issues)) return false;

        $severity = self::highestSeverity($issues);
        return in_array($severity, ['P1', 'P2']);
    }
}
