<?php
/**
 * SOC 2 Type II readiness checks.
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;

class Soc2Check extends BaseAuditChecker
{
    /**
     * Run all SOC 2 readiness checks and return results.
     */
    public function runAll(): array
    {
        $this->checkSecretsEncrypted();
        $this->checkAuditLog();
        $this->checkDeployStrategy();
        $this->checkGitIntegrity();
        $this->checkCrontabRecovery();
        $this->checkKeyPermissions();
        $this->checkKeyRotation();

        return $this->results;
    }

    /**
     * CC6/C1: Verify secrets are encrypted and key is present.
     */
    protected function checkSecretsEncrypted(): void
    {
        $mode = Json::read('deployment.secrets', 'file', $this->repoDir);
        $hasKey = Secrets::hasKey();

        if ($mode === 'aws') {
            $this->addResult('Secrets management', 'pass', 'Secrets managed via AWS Secrets Manager');
        } elseif ($mode === 'encrypted' && $hasKey) {
            $this->addResult('Secrets management', 'pass', 'Secrets encrypted with AES-256-GCM, key present');
        } elseif ($mode === 'encrypted' && !$hasKey) {
            $this->addResult('Secrets management', 'fail', 'Encryption configured but key is missing on this node');
        } else {
            $this->addResult('Secrets management', 'fail', 'Secrets mode is "' . $mode . '" — should be "encrypted" or "aws" for production');
        }
    }

    /**
     * CC7: Verify audit logging is active.
     */
    protected function checkAuditLog(): void
    {
        $logPath = AuditLog::logPath();

        if (!is_file($logPath)) {
            $this->addResult('Audit logging', 'warn', 'No deployment log found — run a deploy to initialize');
            return;
        }

        // Check permissions
        $perms = fileperms($logPath) & 0777;
        $worldReadable = ($perms & 0004) !== 0;

        if ($worldReadable) {
            $this->addResult('Audit logging', 'warn', 'Audit log exists but is world-readable');
        } else {
            $this->addResult('Audit logging', 'pass', 'Audit log active with restricted permissions');
        }
    }

    /**
     * CC7/CC8: Verify release-based deployment (immutable, auditable).
     */
    protected function checkDeployStrategy(): void
    {
        $strategy = Json::read('deployment.strategy', 'branch', $this->repoDir);

        if ($strategy === 'release') {
            $this->addResult('Deploy strategy', 'pass', 'Release-based deployment (immutable tags, full audit trail)');
        } else {
            $this->addResult('Deploy strategy', 'warn', 'Branch-based deployment — no rollback history or approval gate');
        }
    }

    /**
     * CC7: Verify git remote is configured and code origin is traceable.
     */
    protected function checkGitIntegrity(): void
    {
        $remote = Git::RemoteUrl($this->repoDir);

        if (!$remote) {
            $this->addResult('Git integrity', 'fail', 'No git remote configured — code origin is untraceable');
            return;
        }

        // Check that protocol.json remote matches actual remote
        $configuredRemote = Json::read('git.remote', null, $this->repoDir);
        if ($configuredRemote && !self::remotesMatch($configuredRemote, $remote)) {
            $this->addResult('Git integrity', 'warn', 'Git remote does not match protocol.json (expected: ' . $configuredRemote . ', actual: ' . $remote . ')');
            return;
        }

        // Check HEAD is reachable from remote
        $dir = escapeshellarg($this->repoDir);
        $result = Shell::run("git -C {$dir} branch -r --contains HEAD 2>/dev/null");
        if ($result && trim($result)) {
            $this->addResult('Git integrity', 'pass', 'Code is from verified remote: ' . $remote);
        } else {
            $this->addResult('Git integrity', 'warn', 'Current HEAD is not on any remote branch');
        }
    }

    /**
     * A1: Verify reboot recovery is configured.
     */
    protected function checkCrontabRecovery(): void
    {
        if (Crontab::hasCrontabRestart($this->repoDir)) {
            $this->addResult('Reboot recovery', 'pass', 'Crontab @reboot entry configured');
        } else {
            $this->addResult('Reboot recovery', 'warn', 'No @reboot crontab entry — run "protocol cron:add"');
        }
    }

    /**
     * CC6/C1: Verify encryption key file permissions.
     */
    protected function checkKeyPermissions(): void
    {
        $keyPath = Secrets::keyPath();

        if (!is_file($keyPath)) {
            // No key = no permissions to check. The encrypted secrets check handles the missing key case.
            $this->addResult('Key permissions', 'pass', 'No encryption key on this node (skipped)');
            return;
        }

        $issues = [];

        $keyPerms = fileperms($keyPath) & 0777;
        if ($keyPerms !== 0600) {
            $issues[] = sprintf("Key file has %04o permissions (should be 0600)", $keyPerms);
        }

        $protocolDir = dirname($keyPath);
        $dirPerms = fileperms($protocolDir) & 0777;
        if ($dirPerms !== 0700) {
            $issues[] = sprintf("~/.protocol/.node/ has %04o permissions (should be 0700)", $dirPerms);
        }

        if (empty($issues)) {
            $this->addResult('Key permissions', 'pass', 'Encryption key has correct permissions (0600)');
        } else {
            $this->addResult('Key permissions', 'fail', implode('; ', $issues));
        }
    }

    /**
     * CC6: Verify encryption key is not older than 90 days.
     */
    protected function checkKeyRotation(): void
    {
        $keyPath = Secrets::keyPath();

        if (!is_file($keyPath)) {
            return; // No key to check — handled by other checks
        }

        $mtime = filemtime($keyPath);
        $ageDays = (int) ((time() - $mtime) / 86400);
        $maxAgeDays = 90;

        if ($ageDays <= $maxAgeDays) {
            $this->addResult('Key rotation', 'pass', "Encryption key is {$ageDays} days old (limit: {$maxAgeDays})");
        } else {
            $this->addResult('Key rotation', 'warn', "Encryption key is {$ageDays} days old — rotate quarterly (every {$maxAgeDays} days). See: protocol docs/key-rotation.md");
        }
    }

    /**
     * Compare two git remote URLs, treating SSH and HTTPS variants as equivalent.
     * e.g. git@github.com:org/repo.git == https://github.com/org/repo.git
     */
    private static function remotesMatch(string $a, string $b): bool
    {
        return self::normalizeRemote($a) === self::normalizeRemote($b);
    }

    private static function normalizeRemote(string $url): string
    {
        // git@github.com:org/repo.git → github.com/org/repo
        $url = preg_replace('#^git@([^:]+):#', 'https://$1/', $url);
        // Strip protocol
        $url = preg_replace('#^https?://#', '', $url);
        // Strip trailing .git
        $url = preg_replace('#\.git$#', '', $url);
        // Strip trailing slash
        return rtrim($url, '/');
    }

}
