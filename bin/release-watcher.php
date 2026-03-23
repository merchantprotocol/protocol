#!/usr/bin/env php
<?php
/**
 * Release Watcher Daemon
 *
 * Polls the GitHub repository variable for release changes and automatically
 * deploys when a new release is detected.
 *
 * State is stored in:
 *   NodeConfig (~/.protocol/.node/nodes/<project>.json):
 *     release.target  — version we want deployed (set immediately on detection)
 *     release.active  — version that is successfully deployed
 *     release.previous — rollback target
 *
 *   Per-release (.protocol/deployment.json):
 *     watcher_pid, deployed_at, port_http, status, container_name
 *
 * Usage: php release-watcher.php --dir=/path/to/repo [--interval=60]
 */

require __DIR__ . '/../src/bootstrap.php';

use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Log;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

// Redirect ALL logging to watcher.log — completely separate from protocol.log
$watcherLogDir = is_writable('/var/log/protocol/') ? '/var/log/protocol/' : sys_get_temp_dir() . '/protocol/log/';
if (!is_dir($watcherLogDir)) {
    @mkdir($watcherLogDir, 0755, true);
}
Log::setFile($watcherLogDir . 'watcher.log');

// Parse arguments
$options = getopt('', ['dir:', 'interval:']);
$rawDir = $options['dir'] ?? null;
$interval = (int) ($options['interval'] ?? 60);

$inheritedCwd = @getcwd();
$repo_dir = $rawDir ?? ($inheritedCwd ?: '/tmp');
$repo_dir = realpath($repo_dir) . DIRECTORY_SEPARATOR;

// Anchor working directory to repo_dir so that shell commands don't break.
chdir($repo_dir);

/**
 * Run `protocol stop` on the old directory, then `protocol start` on the new one.
 */
function protocolStopStart(string $stopDir, string $startDir): void
{
    $protocolBin = dirname(__DIR__) . '/protocol';
    if (!is_file($protocolBin)) {
        Log::warn('watcher', "protocol binary not found at {$protocolBin} — cannot stop/start");
        return;
    }

    $logFile = Log::getLogFile();

    $phpBin = escapeshellarg($protocolBin);
    $stopArg = escapeshellarg($stopDir);
    $startArg = escapeshellarg($startDir);

    // Use proc_open() to avoid exec() pipe-blocking on daemon spawn.
    $cmd = "nohup sh -c 'php {$phpBin} stop --dir={$stopArg} && php {$phpBin} start --dir={$startArg}'"
        . " >> " . escapeshellarg($logFile) . " 2>&1 </dev/null &";

    Log::context('watcher', [
        'action'    => 'stop+start',
        'stop_dir'  => $stopDir,
        'start_dir' => $startDir,
    ]);

    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $process = proc_open($cmd, $descriptors, $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }

    sleep(2);
    Log::info('watcher', "stop+start spawned, watcher (PID: " . getmypid() . ") exiting");
    exit(0);
}

// Log startup state
Log::context('watcher', [
    'event'         => 'started',
    'pid'           => getmypid(),
    'inherited_cwd' => $inheritedCwd ?: 'FALSE',
    'dir_arg'       => $rawDir ?: '(not set)',
    'repo_dir'      => $repo_dir,
    'repo_exists'   => is_dir($repo_dir) ? 'yes' : 'NO',
    'interval'      => $interval,
]);

$pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
$strategy = BlueGreen::getStrategy($repo_dir);
$projectName = DeploymentState::resolveProjectName($repo_dir);
$currentRelease = $projectName ? NodeConfig::read($projectName, 'release.active') : null;

// Check for failed deploy on startup (target != active means previous attempt failed)
if ($projectName) {
    $target = NodeConfig::read($projectName, 'release.target');
    if ($target && $target !== $currentRelease) {
        Log::info('watcher', "detected incomplete deploy: target={$target}, active={$currentRelease} — will retry");
    }
}

Log::context('watcher', [
    'pointer'  => $pointerName,
    'strategy' => $strategy,
    'current'  => $currentRelease ?: 'none',
    'releases' => BlueGreen::isEnabled($repo_dir) ? BlueGreen::getReleasesDir($repo_dir) : 'n/a',
]);

$pollCount = 0;

while (true) {
    $pollCount++;

    try {
        // Re-anchor working directory every cycle
        $cycleCwd = @getcwd();
        if ($cycleCwd === false) {
            Log::warn('watcher', "cwd invalid at cycle #{$pollCount}, re-anchoring to {$repo_dir}");
        }
        chdir($repo_dir);

        // Clear singleton caches so we re-read files from disk each cycle
        Json::clearInstances();

        // Refresh credentials before each poll (tokens expire after 1 hour)
        if (GitHubApp::isConfigured()) {
            $creds = GitHubApp::loadCredentials();
            $appOwner = $creds['owner'] ?? null;
            if ($appOwner) {
                $refreshed = GitHubApp::refreshGitCredentials($appOwner);
                if (!$refreshed) {
                    Log::warn('watcher', "failed to refresh GitHub App credentials");
                }
            }
        }

        $activeRelease = GitHub::getVariable($pointerName, $repo_dir);

        // Re-read current from NodeConfig each cycle
        $projectName = DeploymentState::resolveProjectName($repo_dir);
        $currentRelease = $projectName ? NodeConfig::read($projectName, 'release.active') : null;

        // Log poll state every 10th cycle
        if ($pollCount % 10 === 1) {
            Log::context('watcher', [
                'poll'    => $pollCount,
                'pointer' => $pointerName,
                'active'  => $activeRelease ?: 'null',
                'current' => $currentRelease ?: 'none',
            ], Log::DEBUG);
        }

        if (!$activeRelease) {
            if ($pollCount === 1) {
                Log::warn('watcher', "could not read {$pointerName} from GitHub — check API access / variable exists");
            }
            sleep($interval);
            continue;
        }

        if ($activeRelease === $currentRelease) {
            if ($pollCount === 1) {
                Log::info('watcher', "already at {$activeRelease}, watching for changes");
            }
            sleep($interval);
            continue;
        }

        // ── New release detected ─────────────────────────────
        Log::context('watcher', [
            'event'    => 'new_release',
            'active'   => $activeRelease,
            'previous' => $currentRelease ?: 'none',
        ]);

        // Immediately write target to NodeConfig
        Log::info('watcher', "Setting release.target={$activeRelease}");
        DeploymentState::setTarget($repo_dir, $activeRelease);

        // Fetch latest tags
        $gitRemote = BlueGreen::getGitRemote($repo_dir) ?: Git::RemoteUrl($repo_dir);
        $fetchUrl = GitHubApp::resolveUrl($gitRemote);
        $fetchResult = Shell::run(
            "GIT_TERMINAL_PROMPT=0 timeout 30 git -C " . escapeshellarg($repo_dir) . " fetch " . escapeshellarg($fetchUrl) . " --tags 2>&1",
            $fetchReturn
        );
        if ($fetchReturn !== 0) {
            Log::warn('watcher', "tag fetch failed (exit={$fetchReturn}): " . trim($fetchResult));
        }

        // Verify tag exists
        if (!GitHub::tagExists($activeRelease, $repo_dir)) {
            Log::warn('watcher', "tag {$activeRelease} not found locally after fetch, skipping");
            sleep($interval);
            continue;
        }
        Log::info('watcher', "tag {$activeRelease} verified");

        // ── Determine strategy ────────────────────────────────
        $strategy = BlueGreen::getStrategy($repo_dir);

        if ($strategy === 'release') {
            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeRelease);

            Log::context('watcher', [
                'action'      => 'release_deploy',
                'version'     => $activeRelease,
                'remote'      => $gitRemote,
                'release_dir' => $releaseDir,
            ]);

            if (!BlueGreen::initReleaseDir($repo_dir, $activeRelease, $gitRemote)) {
                Log::error('watcher', "git clone failed for {$activeRelease} into {$releaseDir}");
                sleep($interval);
                continue;
            }

            if (!BlueGreen::checkoutVersion($releaseDir, $activeRelease)) {
                Log::error('watcher', "failed to checkout tag {$activeRelease} in {$releaseDir}");
                sleep($interval);
                continue;
            }

            if (!BlueGreen::patchComposeFile($releaseDir)) {
                Log::error('watcher', "docker-compose.yml not found in {$releaseDir}");
                sleep($interval);
                continue;
            }

            BlueGreen::writeReleaseEnv(
                $releaseDir,
                BlueGreen::PRODUCTION_HTTP,
                BlueGreen::PRODUCTION_HTTPS,
                $activeRelease
            );

            Log::info('watcher', "Setting {$activeRelease} status=pending");
            BlueGreen::setReleaseState($repo_dir, $activeRelease, BlueGreen::PRODUCTION_HTTP, 'pending');

            // NOTE: We do NOT set release.active here. The watcher already set
            // release.target above. ProtocolStart will set release.active after
            // containers start successfully. This way, if start fails,
            // target != active and the next watcher startup will retry.

            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'initiated', 'release-watcher');
            Log::info('watcher', "release {$activeRelease} prepared, running stop+start");

            $stopDir = $repo_dir;
            if ($currentRelease) {
                $oldReleaseDir = BlueGreen::getReleaseDir($repo_dir, $currentRelease);
                if (is_dir($oldReleaseDir)) {
                    $stopDir = $oldReleaseDir;
                }
            }
            protocolStopStart($stopDir, $releaseDir);

        } elseif ($strategy === 'bluegreen') {
            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeRelease);

            Log::context('watcher', [
                'action'      => 'bluegreen_deploy',
                'version'     => $activeRelease,
                'remote'      => $gitRemote,
                'release_dir' => $releaseDir,
            ]);

            if (!BlueGreen::initReleaseDir($repo_dir, $activeRelease, $gitRemote)) {
                Log::error('watcher', "git clone failed for {$activeRelease} into {$releaseDir}");
                sleep($interval);
                continue;
            }

            if (!BlueGreen::checkoutVersion($releaseDir, $activeRelease)) {
                Log::error('watcher', "failed to checkout tag {$activeRelease} in {$releaseDir}");
                sleep($interval);
                continue;
            }

            if (!BlueGreen::patchComposeFile($releaseDir)) {
                Log::error('watcher', "docker-compose.yml not found in {$releaseDir}");
                sleep($interval);
                continue;
            }

            BlueGreen::stopContainers($releaseDir);

            [$shadowHttp, $shadowHttps] = BlueGreen::findAvailableShadowPorts();
            BlueGreen::writeReleaseEnv($releaseDir, $shadowHttp, $shadowHttps, $activeRelease);

            Log::context('watcher', [
                'action'       => 'building_shadow',
                'version'      => $activeRelease,
                'shadow_http'  => $shadowHttp,
                'shadow_https' => $shadowHttps,
            ]);

            $buildOutput = null;
            if (!BlueGreen::buildContainers($releaseDir, $buildOutput)) {
                Log::error('watcher', "docker build failed for {$activeRelease}: " . trim($buildOutput ?? '(no output)'));
                Log::error('watcher', "Marking {$activeRelease} as failed (build)");
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                sleep($interval);
                continue;
            }
            Log::info('watcher', "shadow containers built on port {$shadowHttp}");

            $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
            Log::info('watcher', "running " . count($healthChecks) . " health check(s)");
            $healthy = BlueGreen::runHealthChecks($repo_dir, $shadowHttp, $healthChecks, $activeRelease);

            if ($healthy) {
                Log::info('watcher', "Shadow {$activeRelease} healthy, marking ready");
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'ready');
                BlueGreen::setShadowVersion($repo_dir, $activeRelease);
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease);
                Log::info('watcher', "shadow {$activeRelease} ready");

                $autoPromote = Json::read('bluegreen.auto_promote', false, $repo_dir);
                if ($autoPromote) {
                    $promoted = BlueGreen::promote($repo_dir, $activeRelease);
                    if ($promoted) {
                        AuditLog::logShadow($repo_dir, 'promote', $activeRelease, $activeRelease);
                        AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'shadow-auto-promote');

                        Log::context('watcher', [
                            'event'    => 'auto_promoted',
                            'version'  => $activeRelease,
                            'previous' => $currentRelease ?: 'none',
                        ]);

                        // NOTE: We do NOT set release.active here. ProtocolStart will
                        // set it after containers are confirmed running (same as release strategy).

                        $bgStopDir = $repo_dir;
                        if ($currentRelease) {
                            $oldDir = BlueGreen::getReleaseDir($repo_dir, $currentRelease);
                            if (is_dir($oldDir)) {
                                $bgStopDir = $oldDir;
                            }
                        }
                        protocolStopStart($bgStopDir, $releaseDir);
                    } else {
                        Log::error('watcher', "auto-promote failed for {$activeRelease}, will retry next cycle");
                    }
                } else {
                    Log::info('watcher', "shadow ready, run 'protocol shadow:start' to promote");
                }
            } else {
                Log::error('watcher', "Marking {$activeRelease} as failed (health check)");
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                Log::error('watcher', "health check failed for {$activeRelease}, will retry next cycle");
            }

        } else {
            // BRANCH STRATEGY (fallback)
            Log::context('watcher', [
                'action'   => 'branch_deploy',
                'version'  => $activeRelease,
                'repo_dir' => $repo_dir,
            ]);

            Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout " . escapeshellarg($activeRelease) . " 2>&1");
            Log::info('watcher', "checked out {$activeRelease}");

            // Update NodeConfig
            if ($projectName) {
                Log::info('watcher', "Branch deploy: setting release.active={$activeRelease}");
                NodeConfig::modify($projectName, function (array $nd) use ($activeRelease, $currentRelease) {
                    $nd['release']['active'] = $activeRelease;
                    if ($currentRelease) {
                        $nd['release']['previous'] = $currentRelease;
                    }
                    return $nd;
                });
            }

            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'watcher');
            Log::info('watcher', "tag {$activeRelease} checked out, running stop+start");

            protocolStopStart($repo_dir, $repo_dir);
        }
    } catch (\Exception $e) {
        Log::error('watcher', $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    }

    sleep($interval);
}
