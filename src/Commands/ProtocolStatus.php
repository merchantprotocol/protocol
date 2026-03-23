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
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\ContainerName;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Helpers\DiskCheck;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

Class ProtocolStatus extends Command {

    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Checks on the system to see its health';

    private OutputInterface $output;
    private array $issues = [];

    protected function configure(): void
    {
        $this
            ->setHelp('Displays a comprehensive dashboard of node health, services, Docker containers, and security status.')
            ->addArgument('project', \Symfony\Component\Console\Input\InputArgument::OPTIONAL, 'Project name (for slave nodes, run from anywhere)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTER
    // ═══════════════════════════════════════════════════════════════

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->output = $output;
        $this->issues = [];

        $ctx = $this->buildContext($input);

        $output->writeln('');

        $this->renderNodeInfo($ctx);
        $this->renderServices($ctx);
        $this->renderDocker($ctx);
        $this->renderConfiguration($ctx);
        if ($ctx['strategy'] !== 'none') {
            $this->renderSecurity($ctx);
        }
        $this->renderDisk();
        $this->renderSummary();

        return empty($this->issues) ? Command::SUCCESS : Command::FAILURE;
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTEXT BUILDER
    // ═══════════════════════════════════════════════════════════════

    private function buildContext(InputInterface $input): array
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $nodeConfig = null;
        $nodeData = [];
        $activeDir = null;
        $resolveError = null;

        $projectArg = $input->getArgument('project');
        try {
            $resolved = NodeConfig::resolveSlaveNode($projectArg ?: null, $repo_dir ?: null);
        } catch (\RuntimeException $e) {
            $resolved = null;
            $resolveError = $e->getMessage();
        }
        if ($resolved) {
            [$nodeConfig, $nodeData, $activeDir] = $resolved;
            $repo_dir = $activeDir ?? $repo_dir;
        }

        if (!$nodeConfig && !$resolveError) {
            Git::checkInitializedRepo($this->output, $repo_dir);
        }

        if ($resolveError && !$nodeConfig) {
            $projects = NodeConfig::listProjects();
            $project = ($projectArg && NodeConfig::exists($projectArg)) ? $projectArg : ($projects[0] ?? null);
            if ($project) {
                $nodeConfig = $project;
                $nodeData = NodeConfig::load($project);
                $relDir = $nodeData['bluegreen']['releases_dir'] ?? null;
                if ($relDir && is_dir($relDir)) {
                    $activeDir = rtrim($relDir, '/') . '/';
                }
            }
        }

        if ($nodeConfig) {
            $strategy = $nodeData['deployment']['strategy'] ?? 'none';
            $projectName = $nodeData['name'] ?? $nodeConfig;
            $releasesDir = $nodeData['bluegreen']['releases_dir'] ?? null;
            $currentRelease = $nodeData['release']['current'] ?? null;
            $currentBranch = $nodeData['deployment']['branch'] ?? null;
            $awaitingRelease = $nodeData['deployment']['awaiting_release'] ?? false;
            $dockerImage = $nodeData['docker']['image'] ?? null;
            $secretsMode = $nodeData['deployment']['secrets'] ?? 'file';
            $gitRemote = $nodeData['git']['remote'] ?? null;
        } else {
            $strategy = Json::read('deployment.strategy', 'none', $repo_dir);
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

        $configrepo = Config::repo($repo_dir);
        $lockDir = ($nodeConfig && $activeDir) ? $activeDir : $repo_dir;

        return [
            'repo_dir'        => $repo_dir,
            'nodeConfig'      => $nodeConfig,
            'nodeData'        => $nodeData,
            'activeDir'       => $activeDir,
            'resolveError'    => $resolveError,
            'strategy'        => $strategy,
            'projectName'     => $projectName,
            'releasesDir'     => $releasesDir,
            'currentRelease'  => $currentRelease,
            'currentBranch'   => $currentBranch,
            'awaitingRelease' => $awaitingRelease,
            'dockerImage'     => $dockerImage,
            'secretsMode'     => $secretsMode,
            'gitRemote'       => $gitRemote,
            'configrepo'      => $configrepo,
            'lockDir'         => $lockDir,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    //  SECTION RENDERERS — each renders one dashboard section
    // ═══════════════════════════════════════════════════════════════

    private function renderNodeInfo(array $ctx): void
    {
        $output = $this->output;
        $this->writeSection($output, 'Protocol Status');

        $hostname = trim(Shell::run('hostname'));
        $environment = Config::read('env', 'not set');

        $this->writeLine($output, 'Node', "<fg=white>{$hostname}</>");
        $this->writeLine($output, 'Project', "<fg=white>{$ctx['projectName']}</>");
        $this->writeLine($output, 'Environment', "<fg=cyan>{$environment}</>");

        if ($ctx['nodeConfig']) {
            $this->writeLine($output, 'Node type', "<fg=white>slave</>");
            $configPath = NodeConfig::configPath($ctx['nodeConfig']);
            $this->writeLine($output, 'Config', "<fg=gray>{$configPath}</>");
        }
        if ($ctx['gitRemote']) {
            $this->writeLine($output, 'Remote', "<fg=white>{$ctx['gitRemote']}</>");
        }
        if ($ctx['nodeConfig']) {
            $ghConfigured = \Gitcd\Helpers\GitHubApp::isConfigured();
            $this->writeLine($output, 'GitHub App', $ghConfigured
                ? '<fg=green>configured</>'
                : '<fg=yellow>not configured</>');
        }

        $this->renderReleaseInfo($ctx);

        // Config repo branch
        if ($ctx['configrepo'] && Git::isInitializedRepo($ctx['configrepo'])) {
            $configBranch = Git::branch($ctx['configrepo']);
            $this->writeLine($output, 'Config branch', "<fg=white>{$configBranch}</>");
        }

        $this->writeLine($output, 'Strategy', "<fg=white>{$ctx['strategy']}</>");

        $this->renderReleasesDir($ctx);

        if ($ctx['nodeConfig'] && $ctx['activeDir'] && $ctx['activeDir'] !== $ctx['repo_dir']) {
            $this->writeLine($output, 'Active dir', "<fg=white>{$ctx['activeDir']}</>");
        }

        if ($ctx['resolveError']) {
            $this->writeLine($output, 'Deploy status', '<fg=yellow>not deployed yet</>');
            $this->issues[] = $ctx['resolveError'];
        }

        $uptime = $this->getUptime();
        if ($uptime) {
            $this->writeLine($output, 'Uptime', "<fg=white>{$uptime}</>");
        }
    }

    private function renderReleaseInfo(array $ctx): void
    {
        $output = $this->output;
        $strategy = $ctx['strategy'];
        $repo_dir = $ctx['repo_dir'];

        if ($strategy === 'release' || $strategy === 'bluegreen') {
            $currentRelease = $ctx['currentRelease'];
            if (!$currentRelease) {
                $currentRelease = BlueGreen::getActiveVersion($repo_dir);
            }
            $currentState = DeploymentState::current($repo_dir);
            $deployedAt = $currentState ? ($currentState['deployed_at'] ?? null) : null;
            $releaseDisplay = $currentRelease ?: '<fg=yellow>none</>';

            if ($currentRelease && $deployedAt) {
                $ago = $this->timeAgo($deployedAt);
                $releaseDisplay .= " <fg=gray>(deployed {$ago})</>";
            }
            $this->writeLine($output, 'Release', $releaseDisplay);
        } else {
            if ($ctx['currentBranch']) {
                $branchDisplay = "<fg=white>{$ctx['currentBranch']}</>";
                if ($ctx['awaitingRelease']) {
                    $branchDisplay .= " <fg=yellow>(awaiting first release)</>";
                }
                $this->writeLine($output, 'Branch', $branchDisplay);
            } else {
                $branch = Git::isInitializedRepo($repo_dir) ? Git::branch($repo_dir) : 'unknown';
                $this->writeLine($output, 'Branch', "<fg=white>{$branch}</>");
            }
        }
    }

    private function renderReleasesDir(array $ctx): void
    {
        if (!$ctx['nodeConfig'] || !$ctx['releasesDir']) {
            return;
        }

        $output = $this->output;
        $releasesDir = $ctx['releasesDir'];
        $currentRelease = $ctx['currentRelease'];
        $currentBranch = $ctx['currentBranch'];

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
            $this->issues[] = "Releases directory {$releasesDir} does not exist";
        }
    }

    private function renderServices(array $ctx): void
    {
        $output = $this->output;
        $strategy = $ctx['strategy'];
        $lockDir = $ctx['lockDir'];
        $repo_dir = $ctx['repo_dir'];
        $configrepo = $ctx['configrepo'];

        $output->writeln('');
        $this->writeSection($output, 'Services');

        // Deploy watcher — skip for strategy=none (local dev)
        if ($strategy === 'none') {
            // No watchers in local dev mode
        } elseif (!$lockDir || !is_dir($lockDir)) {
            $this->writeService($output, 'watchers', 'stopped', 'no active deployment directory');
        } elseif ($strategy === 'release') {
            $pid = DeploymentState::watcherPid($lockDir);
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'deploy:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'deploy:slave', 'stopped');
                $this->issues[] = 'Release watcher is not running';

                $processes = Shell::hasProcess("release-watcher.php --dir=");
                if (!empty($processes)) {
                    $pids = array_column($processes, "PID");
                    $this->writeService($output, 'dangling watchers', 'warning', 'pids ' . implode(',', $pids));
                }
            }
        } else {
            $pid = DeploymentState::watcherPid($lockDir);
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'git:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'git:slave', 'stopped');
                $this->issues[] = 'Git watcher is not running';
            }
        }

        // Config watcher
        if ($configrepo && Git::isInitializedRepo($configrepo)) {
            $configProject = DeploymentState::resolveProjectName($lockDir);
            $pid = $configProject ? NodeConfig::read($configProject, 'configuration.slave_pid') : null;
            $running = $pid && Shell::isRunning($pid);
            if ($running) {
                $this->writeService($output, 'config:slave', 'watching', "pid {$pid}");
            } else {
                $this->writeService($output, 'config:slave', 'stopped');
            }
        }

        // Crontab + Wazuh — skip for strategy=none
        if ($strategy !== 'none') {
            $this->renderCrontabService($ctx);
            $this->renderWazuhService($ctx);
        }
    }

    private function renderCrontabService(array $ctx): void
    {
        $hasCron = Crontab::hasCrontabRestart($ctx['repo_dir']);
        if ($hasCron) {
            $this->writeService($this->output, 'crontab', 'installed');
        } else {
            $this->writeService($this->output, 'crontab', 'missing');
            $this->issues[] = 'Crontab reboot recovery not installed';
        }
    }

    private function renderWazuhService(array &$ctx): void
    {
        $wazuhInstalled = is_dir('/var/ossec') || is_dir('/Library/Ossec');
        $wazuhRunning = false;

        if (!$wazuhInstalled) {
            $ctx['wazuhInstalled'] = false;
            $ctx['wazuhRunning'] = false;
            return;
        }

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
            $this->writeService($this->output, 'wazuh-agent', 'running', $lastEvent);
        } else {
            $this->writeService($this->output, 'wazuh-agent', 'stopped');
            $this->issues[] = 'Wazuh SIEM agent is not running';
        }

        $ctx['wazuhInstalled'] = $wazuhInstalled;
        $ctx['wazuhRunning'] = $wazuhRunning;
    }

    private function renderDocker(array $ctx): void
    {
        $output = $this->output;
        $repo_dir = $ctx['repo_dir'];
        $dockerDir = ($ctx['nodeConfig'] && $ctx['activeDir']) ? $ctx['activeDir'] : $repo_dir;

        $containers = ContainerName::resolveAll($repo_dir);
        if (empty($containers) && $dockerDir && is_dir($dockerDir) && Docker::isDockerInitialized($dockerDir)) {
            $containers = Docker::getContainerNamesFromDockerComposeFile($dockerDir);
        }

        $releaseDockerDir = null;
        if (BlueGreen::isEnabled($repo_dir)) {
            $activeVersion = BlueGreen::getActiveVersion($repo_dir);
            if ($activeVersion) {
                $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeVersion);
                if (is_dir($releaseDir)) {
                    $releaseDockerDir = $releaseDir;
                }
            }
        }

        $effectiveDockerDir = $releaseDockerDir ?: $dockerDir;
        if (empty($containers) && (!$effectiveDockerDir || !is_dir($effectiveDockerDir) || !Docker::isDockerInitialized($effectiveDockerDir))) {
            return;
        }

        $output->writeln('');
        $this->writeSection($output, 'Docker');

        foreach ($containers as $container) {
            $running = Docker::isDockerContainerRunning($container);
            if ($running) {
                $stats = $this->getContainerStats($container);
                $this->writeService($output, $container, 'running', $stats);
            } else {
                $this->writeService($output, $container, 'stopped');
                $this->issues[] = "Container '{$container}' is not running";
            }
        }

        $image = $ctx['dockerImage'] ?: Json::read('docker.image', null, $effectiveDockerDir);
        if ($image) {
            $this->writeLine($output, 'Image', "<fg=white>{$image}</>");
        }

        $diskUsage = $this->getDockerDiskUsage($containers);
        if ($diskUsage) {
            $this->writeLine($output, 'Disk', "<fg=white>{$diskUsage}</>");
        }
    }

    private function renderConfiguration(array $ctx): void
    {
        $output = $this->output;
        $configrepo = $ctx['configrepo'];
        $secretsMode = $ctx['secretsMode'];
        $lockDir = $ctx['lockDir'];

        $output->writeln('');
        $this->writeSection($output, 'Configuration');

        if (!$configrepo || !Git::isInitializedRepo($configrepo)) {
            $this->writeLine($output, 'Config repo', '<fg=yellow>not initialized</>');
            return;
        }

        $branch = Git::branch($configrepo);
        $envMatch = Config::read('env', 'not set') === $branch;
        $branchColor = $envMatch ? 'green' : 'yellow';
        $this->writeLine($output, 'Config branch', "<fg={$branchColor}>{$branch}</>");

        $this->renderSecretsStatus($secretsMode, $configrepo, $lockDir);

        $symProject = DeploymentState::resolveProjectName($lockDir);
        $symlinks = $symProject ? NodeConfig::read($symProject, 'configuration.symlinks', []) : [];
        if (!empty($symlinks)) {
            $this->writeLine($output, 'Symlinks', '<fg=white>' . count($symlinks) . ' linked</>');
        }
    }

    private function renderSecretsStatus(string $secretsMode, string $configrepo, ?string $lockDir): void
    {
        $output = $this->output;

        if ($secretsMode === 'aws') {
            $this->writeLine($output, 'Secrets', '<fg=green>AWS Secrets Manager</>');
            return;
        }

        $cfgProject = DeploymentState::resolveProjectName($lockDir);
        $decryptedFiles = $cfgProject ? NodeConfig::read($cfgProject, 'configuration.decrypted_files', []) : [];
        if (!empty($decryptedFiles)) {
            $this->writeLine($output, 'Secrets', '<fg=green>decrypted</> <fg=gray>(' . count($decryptedFiles) . ' file(s))</>');
        } elseif (Secrets::hasKey()) {
            $encFiles = glob(rtrim($configrepo, '/') . '/*.enc');
            if (!empty($encFiles)) {
                $this->writeLine($output, 'Secrets', '<fg=yellow>encrypted but not linked</>');
                $this->issues[] = 'Encrypted secrets not decrypted — run protocol start';
            } else {
                $this->writeLine($output, 'Secrets', '<fg=green>key present</>');
            }
        } else {
            if ($secretsMode === 'encrypted') {
                $this->writeLine($output, 'Secrets', '<fg=red>encrypted but key MISSING</>');
                $this->issues[] = 'Encryption key missing — run protocol secrets:setup';
            } else {
                $this->writeLine($output, 'Secrets', '<fg=white>plaintext</>');
            }
        }
    }

    private function renderSecurity(array $ctx): void
    {
        $output = $this->output;
        $activeDir = $ctx['activeDir'];
        $repo_dir = $ctx['repo_dir'];
        $secretsMode = $ctx['secretsMode'];
        $wazuhInstalled = $ctx['wazuhInstalled'] ?? false;
        $wazuhRunning = $ctx['wazuhRunning'] ?? false;

        $output->writeln('');
        $this->writeSection($output, 'Security');

        $this->renderSoc2($activeDir ?: $repo_dir);
        $this->renderSiem($wazuhInstalled, $wazuhRunning);
        $this->renderEncryption($secretsMode);
        $this->renderAuditLog();
    }

    private function renderSoc2(?string $dir): void
    {
        $output = $this->output;

        if (!$dir || !is_dir($dir)) {
            $this->writeLine($output, 'SOC 2', '<fg=gray>skipped (no active directory)</>');
            return;
        }

        $soc2 = new Soc2Check($dir);
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
                $this->issues[] = 'SOC 2: ' . $f['name'] . ' — ' . $f['message'];
            }
        }
    }

    private function renderSiem(bool $wazuhInstalled, bool $wazuhRunning): void
    {
        $output = $this->output;

        if (!$wazuhInstalled) {
            $this->writeLine($output, 'SIEM', '<fg=gray>not installed</> <fg=gray>(run protocol siem:install)</>');
            return;
        }

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
    }

    private function renderEncryption(string $secretsMode): void
    {
        $output = $this->output;

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
    }

    private function renderAuditLog(): void
    {
        $output = $this->output;
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
    }

    private function renderDisk(): void
    {
        $output = $this->output;
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
                $this->issues[] = "Docker has {$d['reclaimable']} reclaimable disk space";
            }
        }
    }

    private function renderSummary(): void
    {
        $output = $this->output;
        $output->writeln('');

        if (empty($this->issues)) {
            $output->writeln('  <fg=green>✓</> All systems operational.');
        } else {
            $output->writeln("  <fg=yellow>!</> " . count($this->issues) . " issue(s) detected:");
            foreach ($this->issues as $issue) {
                $output->writeln("    <fg=yellow>-</> {$issue}");
            }
        }
        $output->writeln('');
    }

    // ═══════════════════════════════════════════════════════════════
    //  DISPLAY HELPERS
    // ═══════════════════════════════════════════════════════════════

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

    // ═══════════════════════════════════════════════════════════════
    //  DATA HELPERS
    // ═══════════════════════════════════════════════════════════════

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
