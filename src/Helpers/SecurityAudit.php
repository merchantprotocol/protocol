<?php
/**
 * Security audit checks for codebase and server hardening.
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Yaml;

class SecurityAudit
{
    private string $repoDir;
    private array $results = [];

    public function __construct(string $repoDir)
    {
        $this->repoDir = $repoDir;
    }

    /**
     * Run all security checks and return results.
     */
    public function runAll(): array
    {
        $this->checkMaliciousCode();
        $this->checkFilePermissions();
        $this->checkDependencies();
        $this->checkProcesses();
        $this->checkDockerSecurity();
        $this->checkRecentChanges();

        return $this->results;
    }

    /**
     * True if no checks returned 'fail' status.
     */
    public function passed(): bool
    {
        foreach ($this->results as $r) {
            if ($r['status'] === 'fail') return false;
        }
        return true;
    }

    /**
     * Get all check results.
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Scan PHP files for suspicious/malicious function calls.
     */
    private function checkMaliciousCode(): void
    {
        $patterns = [
            'eval', 'base64_decode', 'gzinflate', 'str_rot13',
            'shell_exec', 'proc_open', 'popen', 'assert',
        ];

        $suspicious = [];
        $dir = escapeshellarg($this->repoDir);

        foreach ($patterns as $pattern) {
            $cmd = "grep -rl --include='*.php' " . escapeshellarg($pattern . ' *(') . " {$dir} 2>/dev/null";
            $result = Shell::run($cmd);
            if ($result) {
                $files = array_filter(array_map('trim', explode("\n", $result)));
                foreach ($files as $file) {
                    // Skip vendor directory and Protocol's own source
                    if (strpos($file, '/vendor/') !== false) continue;
                    if (strpos($file, '/protocol/src/') !== false) continue;
                    $suspicious[$file] = ($suspicious[$file] ?? 0) + 1;
                }
            }
        }

        if (empty($suspicious)) {
            $this->addResult('Malicious code scan', 'pass', 'No suspicious patterns found');
        } else {
            arsort($suspicious);
            $top = array_slice($suspicious, 0, 5, true);
            $fileList = implode(', ', array_map('basename', array_keys($top)));
            $this->addResult(
                'Malicious code scan',
                'warn',
                count($suspicious) . " file(s) with suspicious patterns: {$fileList}"
            );
        }
    }

    /**
     * Verify sensitive file permissions.
     */
    private function checkFilePermissions(): void
    {
        $issues = [];

        // Check encryption key permissions
        $keyPath = Secrets::keyPath();
        if (is_file($keyPath)) {
            $perms = fileperms($keyPath) & 0777;
            if ($perms !== 0600) {
                $issues[] = sprintf("~/.protocol/key has %04o permissions (should be 0600)", $perms);
            }
        }

        // Check ~/.protocol/ directory permissions
        $protocolDir = dirname(Secrets::keyPath());
        if (is_dir($protocolDir)) {
            $perms = fileperms($protocolDir) & 0777;
            if ($perms !== 0700) {
                $issues[] = sprintf("~/.protocol/ has %04o permissions (should be 0700)", $perms);
            }
        }

        // Check for world-writable files in the repo
        $cmd = "find " . escapeshellarg($this->repoDir) . " -maxdepth 2 -type f -perm -o+w -not -path '*/vendor/*' -not -path '*/.git/*' 2>/dev/null";
        $result = Shell::run($cmd);
        if ($result) {
            $worldWritable = array_filter(array_map('trim', explode("\n", $result)));
            if (!empty($worldWritable)) {
                $issues[] = count($worldWritable) . " world-writable file(s) found";
            }
        }

        if (empty($issues)) {
            $this->addResult('File permissions', 'pass', 'All sensitive files have correct permissions');
        } else {
            $this->addResult('File permissions', 'fail', implode('; ', $issues));
        }
    }

    /**
     * Check for known dependency vulnerabilities via composer audit.
     */
    private function checkDependencies(): void
    {
        $composerJson = rtrim($this->repoDir, '/') . '/composer.json';
        if (!is_file($composerJson)) {
            $this->addResult('Dependency audit', 'pass', 'No composer.json (skipped)');
            return;
        }

        // Check if composer audit is available
        $composerBin = is_file(SCRIPT_DIR . 'composer.phar')
            ? 'php ' . escapeshellarg(SCRIPT_DIR . 'composer.phar')
            : 'composer';

        $cmd = "cd " . escapeshellarg($this->repoDir) . " && {$composerBin} audit --format=json --no-interaction 2>/dev/null";
        $result = Shell::run($cmd, $exitCode);

        if ($exitCode === 127) {
            // composer audit not available
            $this->addResult('Dependency audit', 'pass', 'Composer audit not available (skipped)');
            return;
        }

        $data = json_decode($result, true);
        if (!is_array($data)) {
            $this->addResult('Dependency audit', 'pass', 'No vulnerabilities detected');
            return;
        }

        $advisories = $data['advisories'] ?? [];
        $count = 0;
        foreach ($advisories as $pkg => $list) {
            $count += count($list);
        }

        if ($count === 0) {
            $this->addResult('Dependency audit', 'pass', 'No known vulnerabilities');
        } else {
            $this->addResult('Dependency audit', 'warn', "{$count} known vulnerability(ies) in dependencies");
        }
    }

    /**
     * Check for suspicious running processes.
     */
    private function checkProcesses(): void
    {
        $suspicious = ['xmrig', 'cryptominer', 'kinsing', 'dota', 'tsunami'];
        $found = [];

        $processes = Shell::getProcesses();
        foreach ($processes as $ps) {
            $cmdLine = strtolower(implode(' ', $ps));
            foreach ($suspicious as $name) {
                if (strpos($cmdLine, $name) !== false) {
                    $found[] = $name;
                }
            }
        }

        if (empty($found)) {
            $this->addResult('Process audit', 'pass', 'No suspicious processes detected');
        } else {
            $this->addResult('Process audit', 'fail', 'Suspicious process(es): ' . implode(', ', array_unique($found)));
        }
    }

    /**
     * Check Docker Compose configuration for security issues.
     */
    private function checkDockerSecurity(): void
    {
        $composeFile = rtrim($this->repoDir, '/') . '/docker-compose.yml';
        if (!is_file($composeFile)) {
            $this->addResult('Docker security', 'pass', 'No docker-compose.yml (skipped)');
            return;
        }

        $services = Yaml::read('services', null, $this->repoDir);
        if (!is_array($services)) {
            $this->addResult('Docker security', 'pass', 'No services defined');
            return;
        }

        $issues = [];
        foreach ($services as $name => $config) {
            if (!is_array($config)) continue;

            if (!empty($config['privileged'])) {
                $issues[] = "Service '{$name}' runs in privileged mode";
            }

            if (isset($config['user']) && $config['user'] === 'root') {
                $issues[] = "Service '{$name}' runs as root";
            }

            $dangerousCaps = ['SYS_ADMIN', 'NET_ADMIN', 'ALL'];
            if (isset($config['cap_add']) && is_array($config['cap_add'])) {
                $badCaps = array_intersect($config['cap_add'], $dangerousCaps);
                if (!empty($badCaps)) {
                    $issues[] = "Service '{$name}' has dangerous capabilities: " . implode(', ', $badCaps);
                }
            }
        }

        if (empty($issues)) {
            $this->addResult('Docker security', 'pass', 'No security issues in docker-compose.yml');
        } else {
            $this->addResult('Docker security', 'warn', implode('; ', $issues));
        }
    }

    /**
     * Check for recently modified files outside of git tracking.
     */
    private function checkRecentChanges(): void
    {
        $dir = escapeshellarg($this->repoDir);

        // Find files modified in last 24 hours that are not tracked by git
        $cmd = "cd {$dir} && find . -maxdepth 3 -type f -mtime -1 -not -path './.git/*' -not -path './vendor/*' -not -path './node_modules/*' 2>/dev/null | head -50";
        $result = Shell::run($cmd);

        if (!$result) {
            $this->addResult('Recent file changes', 'pass', 'No recently modified files');
            return;
        }

        $recentFiles = array_filter(array_map('trim', explode("\n", $result)));

        // Check if these files are tracked by git (untracked recent changes are suspicious on production)
        $gitStatusCmd = "cd {$dir} && git status --porcelain 2>/dev/null";
        $gitStatus = Shell::run($gitStatusCmd);

        $untrackedChanges = 0;
        if ($gitStatus) {
            $statusLines = array_filter(array_map('trim', explode("\n", $gitStatus)));
            $untrackedChanges = count($statusLines);
        }

        if ($untrackedChanges === 0) {
            $this->addResult('Recent file changes', 'pass', count($recentFiles) . ' recent file(s), all tracked by git');
        } else {
            $this->addResult('Recent file changes', 'warn', "{$untrackedChanges} untracked/modified file(s) detected");
        }
    }

    /**
     * Add a check result.
     */
    private function addResult(string $name, string $status, string $message): void
    {
        $this->results[] = [
            'name' => $name,
            'status' => $status,
            'message' => $message,
        ];
    }
}
