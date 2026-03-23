<?php
/**
 * Incident snapshot command — captures full system state for forensic evidence
 * preservation during Phase 2 (Triage) of the incident response process.
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\AuditLog;

class IncidentSnapshot extends Command
{
    protected static $defaultName = 'incident:snapshot';
    protected static $defaultDescription = 'Capture a forensic snapshot of the current system state for incident response';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Captures a complete snapshot of the current system state and saves it to a
            timestamped directory. This should be run IMMEDIATELY during triage (Phase 2)
            before any containment or remediation actions are taken.

            Captures:
            - Protocol audit log
            - Protocol configuration and lock files
            - Running processes
            - Network connections
            - Docker container state and logs
            - Git state (log, diff, status)
            - System info (uptime, disk, memory)
            - Crontab entries
            - SIEM agent status

            All files are saved to ~/.protocol/.node/incidents/snapshot-YYYY-MM-DD-HHMMSS/
            with 0700 directory permissions.

            HELP)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $timestamp = date('Y-m-d-His');
        $snapshotDir = NODE_DATA_DIR . "incidents/snapshot-{$timestamp}";

        $output->writeln('');
        $output->writeln('<fg=red;options=bold>  Incident Snapshot — Preserving Evidence</>');
        $output->writeln('');
        $output->writeln("  <comment>Do NOT modify, delete, or restart anything until this completes.</comment>");
        $output->writeln('');

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0700, true);
        }

        // ── Protocol state ───────────────────────────────────────
        $output->writeln('  Capturing Protocol state...');

        $nodeDir = NODE_DATA_DIR;
        if (is_file("{$nodeDir}deployments.log")) {
            copy("{$nodeDir}deployments.log", "{$snapshotDir}/deployments.log");
        }

        // Copy protocol.json from repo
        if (is_file("{$repo_dir}/protocol.json")) {
            copy("{$repo_dir}/protocol.json", "{$snapshotDir}/protocol.json");
        }

        // Copy node config for this project
        $project = \Gitcd\Helpers\DeploymentState::resolveProjectName($repo_dir);
        if ($project) {
            $nodePath = \Gitcd\Utils\NodeConfig::configPath($project);
            if (is_file($nodePath)) {
                copy($nodePath, "{$snapshotDir}/node-config.json");
            }
        }

        // Copy active release's deployment.json
        $current = \Gitcd\Helpers\DeploymentState::current($repo_dir);
        if ($current && !empty($current['dir'])) {
            $deployJson = rtrim($current['dir'], '/') . '/.protocol/deployment.json';
            if (is_file($deployJson)) {
                copy($deployJson, "{$snapshotDir}/deployment.json");
            }
        }

        // ── Running processes ────────────────────────────────────
        $output->writeln('  Capturing running processes...');
        $this->capture($snapshotDir, 'processes.txt', "ps auxf 2>/dev/null || ps aux");

        // ── Network connections ──────────────────────────────────
        $output->writeln('  Capturing network connections...');
        $isMac = PHP_OS_FAMILY === 'Darwin';
        if ($isMac) {
            $this->capture($snapshotDir, 'network-listeners.txt', "lsof -iTCP -sTCP:LISTEN -P -n 2>/dev/null || netstat -an 2>/dev/null");
            $this->capture($snapshotDir, 'network-established.txt', "lsof -iTCP -sTCP:ESTABLISHED -P -n 2>/dev/null || netstat -an 2>/dev/null | grep ESTABLISHED");
        } else {
            $this->capture($snapshotDir, 'network-listeners.txt', "ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null || netstat -an");
            $this->capture($snapshotDir, 'network-established.txt', "ss -tnp 2>/dev/null || netstat -tnp 2>/dev/null || netstat -an | grep ESTABLISHED");
        }

        // ── Docker state ─────────────────────────────────────────
        $output->writeln('  Capturing Docker state...');
        $this->capture($snapshotDir, 'docker-ps.txt', "docker ps -a 2>/dev/null");
        $this->capture($snapshotDir, 'docker-images.txt', "docker images 2>/dev/null");

        // Capture logs for each running container
        $containers = Shell::run("docker ps -a --format '{{.Names}}' 2>/dev/null");
        if ($containers) {
            $containerDir = "{$snapshotDir}/container-logs";
            mkdir($containerDir, 0700, true);
            foreach (explode("\n", trim($containers)) as $name) {
                $name = trim($name);
                if (!$name) continue;
                $this->capture($containerDir, "{$name}.log", "docker logs " . escapeshellarg($name) . " --tail 500 2>&1");
            }
        }

        // ── Git state ────────────────────────────────────────────
        $output->writeln('  Capturing Git state...');
        $escapedDir = escapeshellarg($repo_dir);
        $this->capture($snapshotDir, 'git-log.txt', "git -C {$escapedDir} log --oneline -50 2>/dev/null");
        $this->capture($snapshotDir, 'git-status.txt', "git -C {$escapedDir} status 2>/dev/null");
        $this->capture($snapshotDir, 'git-diff.txt', "git -C {$escapedDir} diff 2>/dev/null");
        $this->capture($snapshotDir, 'git-stash-list.txt', "git -C {$escapedDir} stash list 2>/dev/null");
        $this->capture($snapshotDir, 'git-reflog.txt', "git -C {$escapedDir} reflog --oneline -30 2>/dev/null");

        // ── System info ──────────────────────────────────────────
        $output->writeln('  Capturing system info...');
        $this->capture($snapshotDir, 'system-uname.txt', "uname -a 2>/dev/null");
        $this->capture($snapshotDir, 'system-uptime.txt', "uptime 2>/dev/null");
        $this->capture($snapshotDir, 'system-disk.txt', "df -h 2>/dev/null");
        if ($isMac) {
            $this->capture($snapshotDir, 'system-memory.txt', "vm_stat 2>/dev/null");
        } else {
            $this->capture($snapshotDir, 'system-memory.txt', "free -h 2>/dev/null || vm_stat 2>/dev/null");
        }
        $this->capture($snapshotDir, 'system-who.txt', "who 2>/dev/null");
        $this->capture($snapshotDir, 'system-last-logins.txt', "last -50 2>/dev/null");

        // ── Crontab ──────────────────────────────────────────────
        $output->writeln('  Capturing crontab...');
        $this->capture($snapshotDir, 'crontab.txt', "crontab -l 2>/dev/null");

        // ── SIEM status ──────────────────────────────────────────
        $output->writeln('  Capturing SIEM status...');
        $this->capture($snapshotDir, 'siem-status.txt', "sudo /var/ossec/bin/wazuh-control status 2>/dev/null || echo 'Wazuh not installed or not accessible'");

        // ── Auth logs (if accessible) ────────────────────────────
        $output->writeln('  Capturing auth logs...');
        $this->capture($snapshotDir, 'auth-log.txt', "tail -200 /var/log/auth.log 2>/dev/null || tail -200 /var/log/secure 2>/dev/null || echo 'Auth logs not accessible'");

        // ── Recently modified files ──────────────────────────────
        $output->writeln('  Capturing recently modified files...');
        $this->capture($snapshotDir, 'recent-files-24h.txt', "find " . escapeshellarg($repo_dir) . " -type f -mtime -1 -not -path '*/vendor/*' -not -path '*/.git/*' 2>/dev/null");

        // ── Set permissions ──────────────────────────────────────
        Shell::run("chmod -R 600 " . escapeshellarg($snapshotDir) . "/* 2>/dev/null");
        Shell::run("chmod 700 " . escapeshellarg($snapshotDir));
        // Fix directory permissions for subdirectories
        Shell::run("find " . escapeshellarg($snapshotDir) . " -type d -exec chmod 700 {} \\; 2>/dev/null");

        // ── Audit log entry ──────────────────────────────────────
        AuditLog::write('SNAPSHOT', $repo_dir, [
            'path' => $snapshotDir,
            'user' => get_current_user(),
        ]);

        // ── Summary ──────────────────────────────────────────────
        $fileCount = Shell::run("find " . escapeshellarg($snapshotDir) . " -type f | wc -l 2>/dev/null") ?: '0';

        $output->writeln('');
        $output->writeln("  <info>Snapshot captured:</info> {$snapshotDir}");
        $output->writeln("  Files: " . trim($fileCount));
        $output->writeln('');
        $output->writeln('  <comment>This snapshot is forensic evidence. Do not modify or delete it.</comment>');
        $output->writeln('  <comment>Proceed with Phase 3 (Isolation & Containment) per the runbook.</comment>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    /**
     * Run a command and save its output to a file in the snapshot directory.
     */
    private function capture(string $dir, string $filename, string $command): void
    {
        $result = Shell::run($command);
        if ($result !== null && $result !== '') {
            file_put_contents("{$dir}/{$filename}", $result . "\n");
        }
    }
}
