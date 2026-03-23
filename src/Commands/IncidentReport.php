<?php
/**
 * Incident report command — gathers system state, creates a GitHub issue,
 * and sends the report to configured webhooks.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\SecurityAudit;
use Gitcd\Helpers\Soc2Check;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Webhook;
use Gitcd\Helpers\ContainerName;
use Gitcd\Helpers\IncidentDetector;
use Gitcd\Utils\Json;
use Gitcd\Helpers\DeploymentState;

class IncidentReport extends Command
{
    protected static $defaultName = 'incident:report';
    protected static $defaultDescription = 'Create an incident report with full system state, open a GitHub issue, and notify webhooks';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Gathers all available system state — deployment logs, security audit results,
            SOC 2 check results, container status, process list, network connections — and
            compiles a structured incident report.

            The report is:
            1. Written to ~/.protocol/.node/incidents/YYYY-MM-DD-HHMMSS.md
            2. Logged as an INCIDENT entry in the audit log
            3. Opened as a GitHub issue (if gh CLI is available)
            4. Sent to all configured webhooks

            Severity is auto-detected from system state but can be overridden:
              P1 — Security audit failures or multiple containers down
              P2 — SOC 2 check failures or single container down
              P3 — Warnings from audits or checks
              P4 — Informational, no failures detected

            Usage:
                protocol incident:report "Unauthorized deploy detected at 3am"
                protocol incident:report 1 "SIEM alert: file integrity change"
                protocol incident:report P2 "Degraded service on node-3"
            HELP)
            ->addArgument('severity_or_message', InputArgument::REQUIRED, 'Severity (1-4 or P1-P4) or incident message if severity is omitted')
            ->addArgument('message', InputArgument::OPTIONAL, 'Incident message (when severity is provided as first argument)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('no-issue', null, InputOption::VALUE_NONE, 'Skip creating a GitHub issue')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $skipIssue = $input->getOption('no-issue');
        $timestamp = date('Y-m-d\TH:i:sP');
        $fileTimestamp = date('Y-m-d-His');

        // ── Parse severity and message from positional args ─────
        $firstArg = $input->getArgument('severity_or_message');
        $secondArg = $input->getArgument('message');

        $severityOverride = null;
        if ($secondArg !== null) {
            // Two args provided: first is severity, second is message
            $severityOverride = self::parseSeverity($firstArg);
            $message = $secondArg;
        } else {
            // One arg: could be severity or message
            $parsed = self::parseSeverity($firstArg);
            if ($parsed !== null) {
                // Looks like a severity with no message
                $severityOverride = $parsed;
                $message = 'Incident reported';
            } else {
                $message = $firstArg;
            }
        }

        $output->writeln('');
        $output->writeln('<fg=white;options=bold>  Incident Report Generator</>');
        $output->writeln('');

        // ── Gather system state ──────────────────────────────────
        $output->writeln('  Gathering system state...');

        $sections = [];

        // Deployment info
        $sections['deployment'] = $this->gatherDeploymentInfo($repo_dir);

        // Recent audit log entries
        $sections['audit_log'] = $this->gatherAuditLog();

        // Security audit
        $output->writeln('  Running security audit...');
        $securityAudit = new SecurityAudit($repo_dir);
        $securityAudit->runAll();
        $securityResults = $securityAudit->getResults();
        $sections['security_audit'] = $this->formatAuditResults('Security Audit Results', $securityResults);

        // SOC 2 check
        $output->writeln('  Running SOC 2 check...');
        $soc2Check = new Soc2Check($repo_dir);
        $soc2Check->runAll();
        $soc2Results = $soc2Check->getResults();
        $sections['soc2_check'] = $this->formatAuditResults('SOC 2 Readiness Check', $soc2Results);

        // Container status
        $sections['containers'] = $this->gatherContainerStatus($repo_dir);

        // ── Determine severity ─────────────────────────────────
        if ($severityOverride !== null) {
            $severity = $severityOverride;
            $output->writeln("  Severity override: <fg=red;options=bold>{$severity}</>");
        } else {
            $severity = $this->detectSeverity($repo_dir, $securityResults, $soc2Results);
            $output->writeln("  Auto-detected severity: <fg=red;options=bold>{$severity}</>");
        }

        // Header (now that severity is resolved)
        $sections = array_merge(['header' => $this->buildHeader($severity, $message, $timestamp)], $sections);

        // Detected issues — the "why" right at the top
        $sections = array_merge(
            ['header' => $sections['header'], 'detected_issues' => $this->buildDetectedIssues($repo_dir, $securityResults, $soc2Results)],
            array_diff_key($sections, ['header' => true])
        );

        // System info
        $sections['system'] = $this->gatherSystemInfo();

        // Network connections
        $sections['network'] = $this->gatherNetworkInfo();

        // Git status
        $sections['git'] = $this->gatherGitInfo($repo_dir);

        // ── Compile report ───────────────────────────────────────
        $report = $this->compileReport($sections);

        // ── Save to file ─────────────────────────────────────────
        $incidentDir = NODE_DATA_DIR . 'incidents';
        if (!is_dir($incidentDir)) {
            mkdir($incidentDir, 0700, true);
        }
        $filePath = $incidentDir . '/' . $fileTimestamp . '.md';
        file_put_contents($filePath, $report);
        chmod($filePath, 0600);
        $output->writeln("  Saved report to: <comment>{$filePath}</comment>");

        // ── Capture forensic snapshot ──────────────────────────
        $output->writeln('  Capturing forensic snapshot...');
        $snapshotCmd = $this->getApplication()->find('incident:snapshot');
        $snapshotInput = new \Symfony\Component\Console\Input\ArrayInput(['--dir' => $repo_dir]);
        $snapshotCmd->run($snapshotInput, new \Symfony\Component\Console\Output\NullOutput());
        $output->writeln('  <info>Snapshot captured</info>');

        // ── Log to audit trail ───────────────────────────────────
        AuditLog::write('INCIDENT', $repo_dir, [
            'severity' => $severity,
            'message' => $message,
            'report' => $filePath,
            'user' => get_current_user(),
        ]);

        // ── Create GitHub issue ──────────────────────────────────
        $issueUrl = null;
        if (!$skipIssue) {
            $output->writeln('  Creating GitHub issue...');
            $issueUrl = $this->createGitHubIssue($repo_dir, $severity, $message, $report, $output);
        }

        // ── Send to webhooks ─────────────────────────────────────
        if (Webhook::isConfigured($repo_dir)) {
            $output->writeln('  Sending to webhooks...');
            Webhook::notifyIncident($repo_dir, $severity, $message, $report, $issueUrl);
            $output->writeln('  <info>Webhook notifications sent</info>');
        }

        $output->writeln('');
        $output->writeln("  <fg=red;options=bold>{$severity} Incident Report Created</>");
        $output->writeln("  File: {$filePath}");
        if ($issueUrl) {
            $output->writeln("  Issue: {$issueUrl}");
        }
        $output->writeln('');
        $output->writeln('  <comment>Next steps: Follow the Incident Response Runbook (docs/incident-response.md)</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    private function buildHeader(string $severity, string $message, string $timestamp): string
    {
        $hostname = gethostname();
        $user = get_current_user();

        // Get IP addresses for server identification
        $ipv4 = trim(Shell::run("hostname -I 2>/dev/null | awk '{print \$1}'") ?: '');
        if (!$ipv4) {
            // macOS fallback
            $ipv4 = trim(Shell::run("ipconfig getifaddr en0 2>/dev/null") ?: '');
            if (!$ipv4) {
                $ipv4 = trim(Shell::run("ipconfig getifaddr en1 2>/dev/null") ?: '');
            }
        }
        $publicIp = trim(Shell::run("curl -s --connect-timeout 3 --max-time 5 ifconfig.me 2>/dev/null") ?: '');
        $ipLine = $ipv4 ?: 'unknown';
        if ($publicIp && $publicIp !== $ipv4) {
            $ipLine .= " (public: {$publicIp})";
        }

        $severityEmoji = match($severity) {
            'P1' => '🔴',
            'P2' => '🟠',
            'P3' => '🟡',
            default => '🔵',
        };

        return <<<MD
## {$severityEmoji} Incident Report: {$message}

| Field | Value |
|-------|-------|
| **Severity** | {$severityEmoji} {$severity} |
| **Reported** | {$timestamp} |
| **Reporter** | {$user} |
| **Hostname** | {$hostname} |
| **IP Address** | {$ipLine} |

### Summary

{$message}
MD;
    }

    /**
     * Build the "why this is an incident" section — detected issues prominently at the top.
     */
    private function buildDetectedIssues(string $repoDir, array $securityResults, array $soc2Results): string
    {
        $issues = [];

        // Gather issues from IncidentDetector (containers, watchers, suspicious processes, etc.)
        $detected = IncidentDetector::detect($repoDir);
        foreach ($detected as $d) {
            $emoji = match($d['level']) {
                'P1' => '🔴',
                'P2' => '🟠',
                'P3' => '🟡',
                default => '🔵',
            };
            $issues[] = "{$emoji} **[{$d['level']}]** {$d['message']}";
        }

        // Security audit failures
        foreach ($securityResults as $r) {
            if ($r['status'] === 'fail') {
                $issues[] = "❌ **Security: {$r['name']}** — {$r['message']}";
            }
        }

        // SOC 2 failures
        foreach ($soc2Results as $r) {
            if ($r['status'] === 'fail') {
                $issues[] = "❌ **SOC 2: {$r['name']}** — {$r['message']}";
            }
        }

        // Security warnings
        foreach ($securityResults as $r) {
            if ($r['status'] === 'warn') {
                $issues[] = "⚠️ **Security: {$r['name']}** — {$r['message']}";
            }
        }

        // SOC 2 warnings
        foreach ($soc2Results as $r) {
            if ($r['status'] === 'warn') {
                $issues[] = "⚠️ **SOC 2: {$r['name']}** — {$r['message']}";
            }
        }

        if (empty($issues)) {
            return "### 🟢 Detected Issues\n\nNo issues detected. This incident was reported manually.";
        }

        $lines = "### 🚨 Detected Issues\n\n";
        $lines .= "The following issues triggered this incident:\n\n";
        foreach ($issues as $issue) {
            $lines .= "- {$issue}\n";
        }

        return $lines;
    }

    private function gatherDeploymentInfo(string $repoDir): string
    {
        $current = DeploymentState::current($repoDir);
        $version = $current ? $current['version'] : 'unknown';
        $deployedAt = $current ? ($current['deployed_at'] ?? 'unknown') : 'unknown';
        $prev = DeploymentState::previous($repoDir);
        $previous = $prev ? $prev['version'] : 'unknown';
        $strategy = Json::read('deployment.strategy', 'unknown', $repoDir);

        return <<<MD
### Deployment State

| Field | Value |
|-------|-------|
| Current release | {$version} |
| Previous release | {$previous} |
| Deployed at | {$deployedAt} |
| Strategy | {$strategy} |
MD;
    }

    private function gatherAuditLog(): string
    {
        $entries = AuditLog::read(20);
        if (empty($entries)) {
            return "### Recent Audit Log\n\nNo entries found.";
        }

        $lines = "### Recent Audit Log (last 20 entries)\n\n```\n";
        foreach ($entries as $entry) {
            $lines .= $entry . "\n";
        }
        $lines .= "```";

        return $lines;
    }

    private function formatAuditResults(string $title, array $results): string
    {
        $lines = "### {$title}\n\n";
        $lines .= "| Status | Check | Detail |\n|--------|-------|--------|\n";
        foreach ($results as $r) {
            $emoji = match($r['status']) {
                'pass' => '✅ PASS',
                'warn' => '⚠️ WARN',
                'fail' => '❌ FAIL',
                default => $r['status'],
            };
            $lines .= "| {$emoji} | {$r['name']} | {$r['message']} |\n";
        }

        return $lines;
    }

    /**
     * Parse a severity value from user input. Returns null if not a valid severity.
     * Accepts: 1, 2, 3, 4, P1, P2, P3, P4 (case-insensitive)
     */
    private static function parseSeverity(string $value): ?string
    {
        $value = strtoupper(trim($value));

        if (in_array($value, ['P1', 'P2', 'P3', 'P4'])) {
            return $value;
        }

        if (in_array($value, ['1', '2', '3', '4'])) {
            return 'P' . $value;
        }

        return null;
    }

    /**
     * Auto-detect incident severity from system state.
     *
     * P1 — Security audit failures or multiple containers down
     * P2 — SOC 2 failures or single container down
     * P3 — Warnings from audits/checks
     * P4 — Informational, no issues detected
     */
    private function detectSeverity(string $repoDir, array $securityResults, array $soc2Results): string
    {
        $securityFails = count(array_filter($securityResults, fn($r) => $r['status'] === 'fail'));
        $soc2Fails = count(array_filter($soc2Results, fn($r) => $r['status'] === 'fail'));
        $warnings = count(array_filter($securityResults, fn($r) => $r['status'] === 'warn'))
                  + count(array_filter($soc2Results, fn($r) => $r['status'] === 'warn'));

        // Check container health
        $containerNames = ContainerName::resolveAll($repoDir);
        $downContainers = 0;
        foreach ($containerNames as $name) {
            if (!Docker::isDockerContainerRunning($name)) {
                $downContainers++;
            }
        }

        // P1: Security failures or multiple containers down
        if ($securityFails > 0 || $downContainers > 1) {
            return 'P1';
        }

        // P2: SOC 2 failures or single container down
        if ($soc2Fails > 0 || $downContainers === 1) {
            return 'P2';
        }

        // P3: Warnings present
        if ($warnings > 0) {
            return 'P3';
        }

        // P4: No issues detected
        return 'P4';
    }

    private function gatherContainerStatus(string $repoDir): string
    {
        $dockerPs = Shell::run("docker ps -a --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}' 2>/dev/null");

        if (!$dockerPs) {
            return "### Container Status\n\nDocker not available or no containers running.";
        }

        return "### Container Status\n\n```\n{$dockerPs}\n```";
    }

    private function gatherSystemInfo(): string
    {
        $hostname = gethostname();
        $uname = Shell::run("uname -a 2>/dev/null") ?: 'unknown';
        $uptime = Shell::run("uptime 2>/dev/null") ?: 'unknown';
        $diskUsage = Shell::run("df -h / 2>/dev/null") ?: 'unknown';
        // macOS doesn't have `free` — use vm_stat first on Darwin
        $isMac = PHP_OS_FAMILY === 'Darwin';
        if ($isMac) {
            $memUsage = Shell::run("vm_stat 2>/dev/null") ?: 'unknown';
        } else {
            $memUsage = Shell::run("free -h 2>/dev/null") ?: Shell::run("vm_stat 2>/dev/null") ?: 'unknown';
        }

        return <<<MD
### System Information

**Hostname:** {$hostname}
**OS:** {$uname}
**Uptime:** {$uptime}

#### Disk Usage
```
{$diskUsage}
```

#### Memory
```
{$memUsage}
```
MD;
    }

    private function gatherNetworkInfo(): string
    {
        // macOS netstat doesn't support -tulpn — use lsof for listening sockets
        $isMac = PHP_OS_FAMILY === 'Darwin';
        if ($isMac) {
            $connections = Shell::run("lsof -iTCP -sTCP:LISTEN -P -n 2>/dev/null | head -30") ?: Shell::run("netstat -an 2>/dev/null | head -30") ?: 'Not available';
        } else {
            $connections = Shell::run("ss -tulpn 2>/dev/null") ?: Shell::run("netstat -tulpn 2>/dev/null") ?: Shell::run("netstat -an 2>/dev/null | head -30") ?: 'Not available';
        }

        return "### Active Network Connections\n\n```\n{$connections}\n```";
    }

    private function gatherGitInfo(string $repoDir): string
    {
        $branch = Shell::run("git -C " . escapeshellarg($repoDir) . " branch --show-current 2>/dev/null") ?: 'detached';
        $head = Shell::run("git -C " . escapeshellarg($repoDir) . " log --oneline -5 2>/dev/null") ?: 'unknown';
        $status = Shell::run("git -C " . escapeshellarg($repoDir) . " status --short 2>/dev/null") ?: 'clean';
        $remoteUrl = Git::RemoteUrl($repoDir) ?: 'unknown';

        return <<<MD
### Git State

**Branch:** {$branch}
**Remote:** {$remoteUrl}

#### Recent Commits
```
{$head}
```

#### Working Tree
```
{$status}
```
MD;
    }

    private function compileReport(array $sections): string
    {
        return implode("\n\n---\n\n", $sections);
    }

    private function createGitHubIssue(string $repoDir, string $severity, string $message, string $report, OutputInterface $output): ?string
    {
        $slug = GitHub::getRepoSlug($repoDir);
        if (!$slug) {
            $output->writeln('  <comment>Skipping GitHub issue — no repo slug found</comment>');
            return null;
        }

        if (!GitHubApp::isConfigured()) {
            $output->writeln('  <comment>Skipping GitHub issue — GitHub App not configured</comment>');
            return null;
        }

        $title = "[{$severity}] Incident: {$message}";

        // Truncate report body if too long for GitHub (max ~65k chars)
        $body = $report;
        if (strlen($body) > 60000) {
            $body = substr($body, 0, 60000) . "\n\n---\n\n*Report truncated. Full report saved locally.*";
        }

        // Create issue via GitHub REST API using App token
        $token = GitHubApp::getAccessToken();
        if (!$token) {
            return null;
        }

        $issueBody = [
            'title' => $title,
            'body' => $body,
            'labels' => ['incident', strtolower($severity)],
        ];

        $cmd = "curl -s -X POST"
            . " -H " . escapeshellarg("Authorization: token {$token}")
            . " -H 'Accept: application/vnd.github+json'"
            . " -H 'Content-Type: application/json'"
            . " -d " . escapeshellarg(json_encode($issueBody))
            . " " . escapeshellarg("https://api.github.com/repos/{$slug}/issues")
            . " 2>/dev/null";

        $result = Shell::run($cmd, $error);

        if ($error || !$result) {
            // Retry without labels (labels may not exist in the repo)
            $issueBody = ['title' => $title, 'body' => $body];
            $cmd = "curl -s -X POST"
                . " -H " . escapeshellarg("Authorization: token {$token}")
                . " -H 'Accept: application/vnd.github+json'"
                . " -H 'Content-Type: application/json'"
                . " -d " . escapeshellarg(json_encode($issueBody))
                . " " . escapeshellarg("https://api.github.com/repos/{$slug}/issues")
                . " 2>/dev/null";

            $result = Shell::run($cmd, $error);
        }

        if (!$error && $result) {
            $data = json_decode($result, true);
            $url = $data['html_url'] ?? null;
            if ($url) {
                $output->writeln("  <info>Created GitHub issue:</info> {$url}");
                return $url;
            }
        }

        $output->writeln("  <comment>Could not create GitHub issue: {$result}</comment>");
        return null;
    }
}
