<?php
/**
 * NOTICE OF LICENSE
 *
 * MIT License
 *
 * Copyright (c) 2019 Merchant Protocol
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 *
 * @category   merchantprotocol
 * @package    merchantprotocol/protocol
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Crontab;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\Soc2Check;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\DiskCheck;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Utils\NodeConfig;

Class ProtocolStatus extends Command {

    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Checks on the system to see its health';

    protected function configure(): void
    {
        $this
            ->setHelp('Displays a comprehensive dashboard of node health, services, Docker containers, and security status.')
            ->addArgument('project', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $nodeConfig = null;
        $nodeData = [];

        // Detect slave node mode so status works from anywhere
        $projectArg = $input->getArgument('project');
        $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        if ($resolved) {
            [$nodeConfig, $nodeData, $activeDir] = $resolved;
            $repo_dir = $nodeData['repo_dir'] ?? $repo_dir;
        }

        // For non-slave nodes, require a git repo as before
        if (!$nodeConfig) {
            Git::checkInitializedRepo($output, $repo_dir);
        }

        // Read config: slave nodes use NodeConfig, others use protocol.json
        if ($nodeConfig) {
            $strategy = $nodeData['deployment']['strategy'] ?? 'branch';
            $projectName = $nodeData['name'] ?? $nodeConfig;
            $releasesDir = $nodeData['bluegreen']['releases_dir'] ?? null;
            $currentRelease = $nodeData['release']['current'] ?? null;
            $currentBranch = $nodeData['deployment']['branch'] ?? null;
            $awaitingRelease = $nodeData['deployment']['awaiting_release'] ?? false;
            $dockerImage = $nodeData['docker']['image'] ?? null;
            $secretsMode = $nodeData['deployment']['secrets'] ?? 'file';
            $gitRemote = $nodeData['git']['remote'] ?? null;
        } else {
            $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);
            $projectName = Json::read('name', basename($repo_dir), $repo_dir);
            $releasesDir = null;
            $currentRelease = null;
            $currentBranch = null;
            $awaitingRelease = false;
            $dockerImage = Json::read('docker.image', null, $repo_dir);
            $secretsMode = Json::read('deployment.secrets', 'file', $repo_dir);
            $gitRemote = null;
            $activeDir = $repo_dir;
        }

        $output->writeln('');

        // ── Node Info ────────────────────────────────────────────
        $this->writeSection($output, 'Protocol Status');

        $hostname = trim(Shell::run('hostname'));
        $environment = Config::read('env', 'not set');

        $this->writeLine($output, 'Node', "<fg=white>{$hostname}</>");
        $this->writeLine($output, 'Project', "<fg=white>{$projectName}</>");
        $this->writeLine($output, 'Environment', "<fg=cyan>{$environment}</>");
        if ($nodeConfig) {
            $this->writeLine($output, 'Node type', "<fg=white>slave</>");
            $configPath = NodeConfig::configPath($nodeConfig);
            $this->writeLine($output, 'Config', "<fg=gray>{$configPath}</>");
        }
        if ($gitRemote) {
            $this->writeLine($output, 'Remote', "<fg=white>{$gitRemote}</>");
        }
        if ($nodeConfig) {
            $ghConfigured = \Gitcd\Helpers\GitHubApp::isConfigured();
            $this->writeLine($output, 'GitHub App', $ghConfigured
                ? '<fg=green>configured</>'
                : '<fg=yellow>not configured</>');
        }

        // Release info
        if ($strategy === 'release') {
            if ($nodeConfig) {
                $releaseDisplay = $currentRelease ?: '<fg=yellow>none</>';
            } else {
                $currentRelease = JsonLock::read('release.current', null, $repo_dir);
                $deployedAt = JsonLock::read('release.deployed_at', null, $repo_dir);
                $releaseDisplay = $currentRelease ?: '<fg=yellow>none</>';

                if ($deployedAt) {
                    $ago = $this->timeAgo($deployedAt);
                    $releaseDisplay .= " <fg=gray>(deployed {$ago})</>";
                }
            }
            $this->writeLine($output, 'Release', $releaseDisplay);
        } else {
            if ($currentBranch) {
                $branchDisplay = "<fg=white>{$currentBranch}</>";
                if ($awaitingRelease) {
                    $branchDisplay .= " <fg=yellow>(awaiting first release)</>";
                }
                $this->writeLine($output, 'Branch', $branchDisplay);
            } else {
                $branch = Git::isInitializedRepo($repo_dir) ? Git::branch($repo_dir) : 'unknown';
                $this->writeLine($output, 'Branch', "<fg=white>{$branch}</>");
            }
        }
        $this->writeLine($output, 'Strategy', "<fg=white>{$strategy}</>");

        // Releases directory info for slave nodes
        if ($nodeConfig && $releasesDir) {
            $this->writeLine($output, 'Releases dir', "<fg=white>{$releasesDir}/</>");
            if (is_dir($releasesDir)) {
                $releases = array_filter(scandir($releasesDir), fn($d) => $d !== '.' && $d !== '..' && is_dir($releasesDir . '/' . $d));
                sort($releases);
                if (!empty($releases)) {
                    foreach ($releases as $rel) {
                        $marker = ($rel === $currentRelease || $rel === $currentBranch) ? '<fg=green>●</>' : '<fg=gray>·</>';
                        $label = ($rel === $currentRelease || $rel === $currentBranch) ? "<fg=white;options=bold>{$rel}</>" : "<fg=gray>{$rel}</>";
                        $this->writeLine($output, '', "  {$marker} {$label}");
                    }
                } else {
                    $this->writeLine($output, '', '  <fg=yellow>no releases cloned</>');
                }
            } else {
                $this->writeLine($output, '', '  <fg=red>directory does not exist</>');
                $issues[] = "Releases directory {$releasesDir} does not exist";
            }
        }

        // Active directory
        if ($nodeConfig && $activeDir !== $repo_dir) {
            $this->writeLine($output, 'Active dir', "<fg=white>{$activeDir}/</>");
        }

        // Uptime
        $uptime = $this->getUptime();
        if ($uptime) {
            $this->writeLine($output, 'Uptime', "<fg=white>{$uptime}</>");
        }

        // ── Services ─────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Services');

        // Deploy watcher — check lock files in the active directory
        $lockDir = $nodeConfig ? $activeDir : $repo_dir;

        if ($strategy === 'release') {
            $pid = JsonLock::read('release.slave.pid', null, $lockDir);
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'deploy:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'deploy:slave', 'stopped');
                $issues[] = 'Release watcher is not running';

                $processes = Shell::hasProcess("release-watcher.php --dir=");
                if (!empty($processes)) {
                    $pids = array_column($processes, "PID");
                    $this->writeService($output, 'dangling watchers', 'warning', 'pids ' . implode(',', $pids));
                }
            }
        } else {
            $pid = JsonLock::read('slave.pid', null, $lockDir);
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'git:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'git:slave', 'stopped');
                $issues[] = 'Git watcher is not running';
            }
        }

        // Config watcher
        if (Git::isInitializedRepo($configrepo)) {
            $pid = JsonLock::read('configuration.slave.pid', null, $lockDir);
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'config:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'config:slave', 'stopped');
            }
        }

        // Crontab
        $hasCron = Crontab::hasCrontabRestart($repo_dir);
        if ($hasCron) {
            $this->writeService($output, 'crontab', 'installed');
        } else {
            $this->writeService($output, 'crontab', 'missing');
            $issues[] = 'Crontab reboot recovery not installed';
        }

        // Wazuh SIEM agent
        $wazuhInstalled = is_dir('/var/ossec') || is_dir('/Library/Ossec');
        if ($wazuhInstalled) {
            if (is_file('/var/ossec/bin/wazuh-control')) {
                $status = Shell::run('/var/ossec/bin/wazuh-control status 2>/dev/null');
                $wazuhRunning = strpos($status, 'running') !== false;
            } elseif (is_file('/Library/Ossec/bin/wazuh-control')) {
                $status = Shell::run('sudo /Library/Ossec/bin/wazuh-control status 2>/dev/null');
                $wazuhRunning = strpos($status, 'running') !== false;
            } elseif (trim(Shell::run('which systemctl 2>/dev/null'))) {
                $status = trim(Shell::run('systemctl is-active wazuh-agent 2>/dev/null'));
                $wazuhRunning = $status === 'active';
            }

            if ($wazuhRunning) {
                $lastEvent = $this->getWazuhLastEvent();
                $this->writeService($output, 'wazuh-agent', 'running', $lastEvent);
            } else {
                $this->writeService($output, 'wazuh-agent', 'stopped');
                $issues[] = 'Wazuh SIEM agent is not running';
            }
        }

        // ── Docker ───────────────────────────────────────────────
        $dockerDir = $nodeConfig ? $activeDir : $repo_dir;
        if (Docker::isDockerInitialized($dockerDir)) {
            $output->writeln('');
            $this->writeSection($output, 'Docker');

            $containers = Docker::getContainerNamesFromDockerComposeFile($dockerDir);

            foreach ($containers as $container) {
                $running = Docker::isDockerContainerRunning($container);

                if ($running) {
                    $stats = $this->getContainerStats($container);
                    $this->writeService($output, $container, 'running', $stats);
                } else {
                    $this->writeService($output, $container, 'stopped');
                    $issues[] = "Container '{$container}' is not running";
                }
            }

            // Image info
            $image = $dockerImage ?: Json::read('docker.image', null, $dockerDir);
            if ($image) {
                $this->writeLine($output, 'Image', "<fg=white>{$image}</>");
            }

            // Docker disk
            $diskUsage = $this->getDockerDiskUsage($containers);
            if ($diskUsage) {
                $this->writeLine($output, 'Disk', "<fg=white>{$diskUsage}</>");
            }
        }

        // ── Config ───────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Configuration');

        if (Git::isInitializedRepo($configrepo)) {
            $branch = Git::branch($configrepo);
            $envMatch = Config::read('env', 'not set') === $branch;
            $branchColor = $envMatch ? 'green' : 'yellow';
            $this->writeLine($output, 'Config branch', "<fg={$branchColor}>{$branch}</>");

            // Secrets status
            if ($secretsMode === 'aws') {
                $this->writeLine($output, 'Secrets', '<fg=green>AWS Secrets Manager</>');
            } else {
                $decryptedFiles = JsonLock::read('configuration.decrypted_files', [], $lockDir);
                if (!empty($decryptedFiles)) {
                    $this->writeLine($output, 'Secrets', '<fg=green>decrypted</> <fg=gray>(' . count($decryptedFiles) . ' file(s))</>');
                } elseif (Secrets::hasKey()) {
                    $encFiles = glob(rtrim($configrepo, '/') . '/*.enc');
                    if (!empty($encFiles)) {
                        $this->writeLine($output, 'Secrets', '<fg=yellow>encrypted but not linked</>');
                        $issues[] = 'Encrypted secrets not decrypted — run protocol start';
                    } else {
                        $this->writeLine($output, 'Secrets', '<fg=green>key present</>');
                    }
                } else {
                    if ($secretsMode === 'encrypted') {
                        $this->writeLine($output, 'Secrets', '<fg=red>encrypted but key MISSING</>');
                        $issues[] = 'Encryption key missing — run protocol secrets:setup';
                    } else {
                        $this->writeLine($output, 'Secrets', '<fg=white>plaintext</>');
                    }
                }
            }

            // Symlinks
            $symlinks = JsonLock::read('configuration.symlinks', [], $lockDir);
            if (!empty($symlinks)) {
                $this->writeLine($output, 'Symlinks', '<fg=white>' . count($symlinks) . ' linked</>');
            }
        } else {
            $this->writeLine($output, 'Config repo', '<fg=yellow>not initialized</>');
        }

        // ── Security ─────────────────────────────────────────────
        $output->writeln('');
        $this->writeSection($output, 'Security');

        // SOC 2 checks
        $soc2 = new Soc2Check($activeDir);
        $soc2->runAll();
        $soc2Results = $soc2->getResults();
        $soc2Failures = array_filter($soc2Results, fn($r) => $r['status'] === 'fail');
        $soc2Warns = array_filter($soc2Results, fn($r) => $r['status'] === 'warn');

        if (empty($soc2Failures) && empty($soc2Warns)) {
            $this->writeLine($output, 'SOC 2', '<fg=green>all checks passing</>');
        } elseif (empty($soc2Failures)) {
            $this->writeLine($output, 'SOC 2', '<fg=yellow>' . count($soc2Warns) . ' warning(s)</>');
        } else {
            $this->writeLine($output, 'SOC 2', '<fg=red>' . count($soc2Failures) . ' failing</>' . (count($soc2Warns) ? " <fg=yellow>" . count($soc2Warns) . " warning(s)</>" : ''));
            foreach ($soc2Failures as $f) {
                $issues[] = 'SOC 2: ' . $f['name'] . ' — ' . $f['message'];
            }
        }

        // SIEM
        if ($wazuhInstalled) {
            $configFile = is_file('/var/ossec/etc/ossec.conf') ? '/var/ossec/etc/ossec.conf' : '/Library/Ossec/etc/ossec.conf';
            $siemManager = '';
            $protocolLogConfigured = false;
            if (is_file($configFile)) {
                $config = file_get_contents($configFile);
                if (preg_match('/<address>(.*?)<\/address>/', $config, $m)) {
                    $siemManager = $m[1];
                }
                $protocolLogConfigured = strpos($config, 'Protocol deployment audit log') !== false;
            }

            if ($wazuhRunning) {
                $siemDetail = "connected";
                if ($siemManager) {
                    $siemDetail .= " to {$siemManager}";
                }
                if ($protocolLogConfigured) {
                    $siemDetail .= ' <fg=gray>log forwarding active</>';
                }
                $this->writeLine($output, 'SIEM', "<fg=green>{$siemDetail}</>");
            } else {
                $this->writeLine($output, 'SIEM', '<fg=yellow>installed but not running</>');
            }
        } else {
            $this->writeLine($output, 'SIEM', '<fg=gray>not installed</> <fg=gray>(run protocol siem:install)</>');
        }

        // Encryption key
        if ($secretsMode === 'encrypted' && Secrets::hasKey()) {
            $keyPerms = fileperms(Secrets::keyPath()) & 0777;
            $keyOk = $keyPerms === 0600;
            $this->writeLine($output, 'Encryption', $keyOk
                ? '<fg=green>AES-256-GCM key present (0600)</>'
                : sprintf('<fg=yellow>key permissions %04o (should be 0600)</>', $keyPerms));
        } elseif ($secretsMode === 'encrypted') {
            $this->writeLine($output, 'Encryption', '<fg=red>key missing</>');
        } else {
            $this->writeLine($output, 'Encryption', '<fg=gray>not configured</>');
        }

        // Audit log
        $logPath = AuditLog::logPath();
        if (is_file($logPath)) {
            $lastLines = AuditLog::read(1);
            $logDetail = 'active';
            if (!empty($lastLines)) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^\s]*)/', $lastLines[0], $m)) {
                    $ago = $this->timeAgo($m[1]);
                    $logDetail .= " <fg=gray>last entry {$ago}</>";
                }
            }
            $this->writeLine($output, 'Audit log', "<fg=green>{$logDetail}</>");
        } else {
            $this->writeLine($output, 'Audit log', '<fg=yellow>no entries yet</>');
        }

        // ── Disk ─────────────────────────────────────────────────
        $diskCheck = DiskCheck::check();
        $output->writeln('');
        $this->writeSection($output, 'Disk');

        $diskColor = match($diskCheck['level']) {
            'alert' => 'red',
            'warn' => 'yellow',
            default => 'green',
        };
        $this->writeLine($output, 'Usage', "<fg={$diskColor}>{$diskCheck['percent']}%</> — {$diskCheck['used']} of {$diskCheck['total']} used, {$diskCheck['available']} available");

        if ($diskCheck['docker']) {
            $d = $diskCheck['docker'];
            $this->writeLine($output, 'Docker', "images {$d['images']}, containers {$d['containers']}, volumes {$d['volumes']}, cache {$d['buildcache']}");
            if ($d['reclaimable'] && $d['reclaimable'] !== '0B') {
                $this->writeLine($output, 'Reclaimable', "<fg={$diskColor}>{$d['reclaimable']}</> <fg=gray>run: protocol docker:cleanup</>");
                $issues[] = "Docker has {$d['reclaimable']} reclaimable disk space";
            }
        }

        // ── Summary ──────────────────────────────────────────────
        $output->writeln('');
        if (empty($issues)) {
            $output->writeln('  <fg=green>✓</> All systems operational.');
        } else {
            $output->writeln("  <fg=yellow>!</> " . count($issues) . " issue(s) detected:");
            foreach ($issues as $issue) {
                $output->writeln("    <fg=yellow>-</> {$issue}");
            }
        }
        $output->writeln('');

        return empty($issues) ? Command::SUCCESS : Command::FAILURE;
    }

    // ── Display helpers ──────────────────────────────────────────

    private function writeSection(OutputInterface $output, string $title): void
    {
        $output->writeln("  <fg=white;options=bold>{$title}</>");
        $output->writeln('');
    }

    private function writeLine(OutputInterface $output, string $label, string $value): void
    {
        $padded = str_pad($label, 16);
        $output->writeln("    <fg=gray>{$padded}</> {$value}");
    }

    private function writeService(OutputInterface $output, string $name, string $status, string $detail = ''): void
    {
        $padded = str_pad($name, 16);
        $icon = match ($status) {
            'running', 'watching', 'installed' => '<fg=green>●</>',
            'stopped', 'missing' => '<fg=red>●</>',
            'warning' => '<fg=yellow>●</>',
            default => '<fg=gray>●</>',
        };
        $statusColor = match ($status) {
            'running', 'watching', 'installed' => 'green',
            'stopped', 'missing' => 'red',
            'warning' => 'yellow',
            default => 'gray',
        };

        $line = "    {$icon} <fg={$statusColor}>{$padded}</>";
        if ($detail) {
            $line .= " <fg=gray>{$detail}</>";
        }
        $output->writeln($line);
    }

    // ── Data helpers ─────────────────────────────────────────────

    private function timeAgo(string $datetime): string
    {
        try {
            $then = new \DateTime($datetime);
            $now = new \DateTime();
            $diff = $now->getTimestamp() - $then->getTimestamp();
        } catch (\Exception $e) {
            return '';
        }

        if ($diff < 0) return 'just now';
        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return floor($diff / 60) . 'm ago';
        if ($diff < 86400) return floor($diff / 3600) . 'h ago';
        if ($diff < 2592000) return floor($diff / 86400) . 'd ago';
        return floor($diff / 2592000) . 'mo ago';
    }

    private function getUptime(): ?string
    {
        $result = Shell::run('uptime 2>/dev/null');
        if (!$result) return null;

        if (preg_match('/up\s+(\d+)\s+days?,\s*(\d+):(\d+)/', $result, $m)) {
            return "{$m[1]}d {$m[2]}h {$m[3]}m";
        }
        if (preg_match('/up\s+(\d+):(\d+)/', $result, $m)) {
            return "{$m[1]}h {$m[2]}m";
        }
        if (preg_match('/up\s+(\d+)\s+days?/', $result, $m)) {
            return "{$m[1]}d";
        }

        return null;
    }

    private function getContainerStats(string $container): string
    {
        $stats = Shell::run("docker stats --no-stream --format '{{.CPUPerc}} {{.MemUsage}}' " . escapeshellarg($container) . " 2>/dev/null");
        if (!$stats) return '';

        $stats = trim($stats);
        if (empty($stats)) return '';

        $parts = preg_split('/\s+/', $stats, 2);
        if (count($parts) === 2) {
            return "cpu {$parts[0]}  mem {$parts[1]}";
        }

        return $stats;
    }

    private function getDockerDiskUsage(array $containers): ?string
    {
        if (empty($containers)) return null;

        $total = 0;
        foreach ($containers as $container) {
            $size = Shell::run("docker inspect --format='{{.SizeRootFs}}' " . escapeshellarg($container) . " 2>/dev/null");
            $size = (int) trim($size);
            $total += $size;
        }

        if ($total <= 0) return null;

        if ($total > 1073741824) {
            return sprintf('%.1fGB', $total / 1073741824);
        }
        if ($total > 1048576) {
            return sprintf('%.0fMB', $total / 1048576);
        }
        return sprintf('%.0fKB', $total / 1024);
    }

    private function getWazuhLastEvent(): string
    {
        $logPaths = ['/var/ossec/logs/ossec.log', '/Library/Ossec/logs/ossec.log'];
        foreach ($logPaths as $logPath) {
            if (!is_file($logPath)) continue;

            $result = Shell::run("tail -1 " . escapeshellarg($logPath) . " 2>/dev/null");
            if ($result && preg_match('/^(\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})/', $result, $m)) {
                $ago = $this->timeAgo(str_replace('/', '-', $m[1]));
                return "last event {$ago}";
            }
        }
        return '';
    }
}
