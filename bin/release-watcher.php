#!/usr/bin/env php
<?php
/**
 * Release Watcher Daemon
 *
 * Polls the GitHub repository variable for release changes and automatically
 * deploys when a new release is detected.
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
use Gitcd\Helpers\GitHubApp;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

// Redirect ALL logging (including Shell::run) to watcher.log
$watcherLogDir = is_writable('/var/log/protocol/') ? '/var/log/protocol/' : sys_get_temp_dir() . '/protocol/log/';
if (!is_dir($watcherLogDir)) {
    @mkdir($watcherLogDir, 0755, true);
}
Log::setFile($watcherLogDir . 'watcher.log');

// Parse arguments
$options = getopt('', ['dir:', 'interval:']);
$rawDir = $options['dir'] ?? null;
$interval = (int) ($options['interval'] ?? 60);

// Diagnostic: log the inherited cwd and --dir before any changes.
// This helps debug "shell-init: getcwd" errors — if the inherited cwd
// is already invalid, every shell command will fail until we chdir.
$inheritedCwd = @getcwd();
$repo_dir = $rawDir ?? ($inheritedCwd ?: '/tmp');
$repo_dir = realpath($repo_dir) . DIRECTORY_SEPARATOR;

// Anchor working directory to repo_dir so that shell commands don't break.
// This MUST happen before any shell commands or file operations.
chdir($repo_dir);

/**
 * Log a message with timestamp.
 */
function wlog(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
}

/**
 * Run `protocol stop` on the old directory, then `protocol start` on the new one.
 *
 * This ensures full bootstrapping on both sides:
 *   - stop:  config unlinking, stopping ALL containers, crontab removal, watcher kill
 *   - start: config linking, container build+start, health checks, crontab, fresh watcher
 *
 * Both commands are chained in a single background shell so that stop completes
 * before start begins. This watcher will be killed by `protocol stop`, but the
 * background shell continues and runs `protocol start` afterward.
 *
 * @param string $stopDir   Directory to stop (old release dir, or repo_dir for branch strategy)
 * @param string $startDir  Directory to start (new release dir, or repo_dir for branch strategy)
 */
function protocolStopStart(string $stopDir, string $startDir): void
{
    $protocolBin = dirname(__DIR__) . '/protocol';
    if (!is_file($protocolBin)) {
        wlog("WARNING: protocol binary not found at {$protocolBin} — cannot stop/start");
        return;
    }

    $logFile = Log::getLogFile();

    $phpBin = escapeshellarg($protocolBin);
    $stopArg = escapeshellarg($stopDir);
    $startArg = escapeshellarg($startDir);

    // Chain stop then start in a background shell.
    // protocol stop kills this watcher process, but the background shell survives
    // and proceeds to run protocol start.
    $cmd = "nohup sh -c 'php {$phpBin} stop --dir={$stopArg} && php {$phpBin} start --dir={$startArg}'"
        . " >> " . escapeshellarg($logFile) . " 2>&1 &";

    wlog("protocol stop --dir={$stopDir}");
    wlog("protocol start --dir={$startDir}");
    wlog("Spawning: {$cmd}");
    Shell::run($cmd);

    // Give the background process a moment to start before we exit
    sleep(2);
    wlog("Stop+start spawned. Watcher (PID: " . getmypid() . ") exiting.");
    exit(0);
}

wlog("Release watcher started (PID: " . getmypid() . ")");
wlog("  inherited cwd: " . ($inheritedCwd ?: 'FALSE (invalid/deleted directory!)'));
wlog("  --dir arg:     " . ($rawDir ?: '(not set, used cwd)'));
wlog("  repo_dir:      {$repo_dir}");
wlog("  repo_dir exists: " . (is_dir($repo_dir) ? 'yes' : 'NO'));
wlog("  cwd after chdir: " . (getcwd() ?: 'FALSE'));
wlog("  interval:      {$interval}s");

$pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
wlog("  pointer:   {$pointerName}");

// Log initial state
$strategy = BlueGreen::getStrategy($repo_dir);
wlog("  strategy:  {$strategy}");
if (BlueGreen::isEnabled($repo_dir)) {
    wlog("  releases:  " . BlueGreen::getReleasesDir($repo_dir));
}

$currentRelease = JsonLock::read('release.current', null, $repo_dir);
wlog("  current:   " . ($currentRelease ?: 'none'));

$pollCount = 0;

while (true) {
    $pollCount++;

    try {
        // Re-anchor working directory every cycle. If the cwd has become
        // invalid, every shell command would fail with "getcwd: cannot
        // access parent directories". Log the stale cwd so we can see
        // which directory disappeared.
        $cycleCwd = @getcwd();
        if ($cycleCwd === false) {
            wlog("WARNING: cwd is invalid at start of cycle #{$pollCount} — re-anchoring to {$repo_dir}");
        }
        chdir($repo_dir);

        // Clear singleton caches so we re-read files from disk each cycle
        JsonLock::clearInstances();
        Json::clearInstances();


        // Refresh credentials before each poll (tokens expire after 1 hour)
        if (GitHubApp::isConfigured()) {
            $creds = GitHubApp::loadCredentials();
            $appOwner = $creds['owner'] ?? null;
            if ($appOwner) {
                $refreshed = GitHubApp::refreshGitCredentials($appOwner);
                if (!$refreshed) {
                    wlog("WARNING: Failed to refresh GitHub App credentials");
                }
            }
        }

        $activeRelease = GitHub::getVariable($pointerName, $repo_dir);
        $currentRelease = JsonLock::read('release.current', null, $repo_dir);

        // Log every poll cycle
        if ($pollCount % 10 === 1) {
            // Detailed log every 10th cycle
            wlog("Poll #{$pollCount}: pointer={$pointerName} active=" . ($activeRelease ?: 'null') . " current=" . ($currentRelease ?: 'none'));
        }

        if (!$activeRelease) {
            if ($pollCount === 1) {
                wlog("WARNING: Could not read {$pointerName} from GitHub — check API access / variable exists");
            }
            sleep($interval);
            continue;
        }

        if ($activeRelease === $currentRelease) {
            // Already deployed — quiet poll
            if ($pollCount === 1) {
                wlog("Already at {$activeRelease}, watching for changes...");
            }
            sleep($interval);
            continue;
        }

        // ── New release detected ─────────────────────────────
        wlog("New release detected: {$activeRelease} (was: " . ($currentRelease ?: 'none') . ")");

        // Fetch latest tags — use resolved HTTPS URL so the GitHub App
        // credential helper works (the remote may point to an SSH URL).
        $gitRemote = BlueGreen::getGitRemote($repo_dir) ?: Git::RemoteUrl($repo_dir);
        $fetchUrl = GitHubApp::resolveUrl($gitRemote);
        wlog("Fetching tags from {$fetchUrl}...");
        $fetchResult = Shell::run(
            "GIT_TERMINAL_PROMPT=0 timeout 30 git -C " . escapeshellarg($repo_dir) . " fetch " . escapeshellarg($fetchUrl) . " --tags 2>&1",
            $fetchReturn
        );
        if ($fetchReturn !== 0) {
            wlog("WARNING: Tag fetch failed (exit {$fetchReturn}): " . trim($fetchResult));
        }

        // Verify tag exists
        if (!GitHub::tagExists($activeRelease, $repo_dir)) {
            wlog("WARNING: Tag {$activeRelease} not found locally after fetch. Skipping.");
            sleep($interval);
            continue;
        }
        wlog("Tag {$activeRelease} verified");

        // ── Determine strategy ────────────────────────────────
        // Re-read strategy each cycle in case it changed.
        $strategy = BlueGreen::getStrategy($repo_dir);

        if ($strategy === 'release') {
            // ─────────────────────────────────────────────────────
            // RELEASE STRATEGY — Simple one-at-a-time deployment
            //
            // 1. Clone tag into release directory
            // 2. Patch compose file + write env with versioned name
            // 3. Update state so protocol start knows which release
            // 4. Run `protocol restart` for full bootstrapping:
            //    config linking, container stop/start, health checks,
            //    crontab, and fresh watcher spawn
            // ─────────────────────────────────────────────────────
            wlog("Release deploy: cloning {$activeRelease} into releases dir");

            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeRelease);
            wlog("  remote:      {$gitRemote}");
            wlog("  release dir: {$releaseDir}");

            // Initialize release directory with git clone
            wlog("Cloning into {$releaseDir}...");
            if (!BlueGreen::initReleaseDir($repo_dir, $activeRelease, $gitRemote)) {
                wlog("ERROR: Git clone failed for {$activeRelease} into {$releaseDir}");
                wlog("  Check: git remote accessible? Credentials valid? Disk space?");
                sleep($interval);
                continue;
            }
            wlog("Clone complete");

            // Checkout version tag
            if (!BlueGreen::checkoutVersion($releaseDir, $activeRelease)) {
                wlog("ERROR: Failed to checkout tag {$activeRelease} in {$releaseDir}");
                sleep($interval);
                continue;
            }
            wlog("Checked out {$activeRelease}");

            // Patch compose file for parameterized ports and container name
            if (!BlueGreen::patchComposeFile($releaseDir)) {
                wlog("ERROR: docker-compose.yml not found in {$releaseDir}");
                sleep($interval);
                continue;
            }

            // Write production port config (80/443) — no shadow ports for release strategy
            BlueGreen::writeReleaseEnv(
                $releaseDir,
                BlueGreen::PRODUCTION_HTTP,
                BlueGreen::PRODUCTION_HTTPS,
                $activeRelease
            );

            // Update state BEFORE restart so protocol start knows which release to boot
            BlueGreen::setActiveVersion($repo_dir, $activeRelease);
            BlueGreen::setReleaseState($repo_dir, $activeRelease, BlueGreen::PRODUCTION_HTTP, 'pending');
            JsonLock::write('release.previous', $currentRelease, $repo_dir);
            JsonLock::write('release.current', $activeRelease, $repo_dir);
            JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
            JsonLock::save($repo_dir);

            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'release-watcher');
            wlog("Release {$activeRelease} prepared. Running protocol stop+start for full bootstrapping...");

            // Stop the old release, start the new one.
            // Stop dir: old release dir (or repo_dir if no previous release)
            // Start dir: new release dir
            $stopDir = $repo_dir;
            if ($currentRelease) {
                $oldReleaseDir = BlueGreen::getReleaseDir($repo_dir, $currentRelease);
                if (is_dir($oldReleaseDir)) {
                    $stopDir = $oldReleaseDir;
                }
            }
            protocolStopStart($stopDir, $releaseDir);

        } elseif ($strategy === 'bluegreen') {
            // ─────────────────────────────────────────────────────
            // BLUEGREEN STRATEGY — Zero-downtime shadow deployment
            //
            // Clones the tag into its own release directory, builds
            // containers on SHADOW ports (18080-18280), runs health
            // checks, then promotes by swapping to production ports.
            // Old version stays on standby for instant rollback.
            // Two containers may run simultaneously during swap.
            // ─────────────────────────────────────────────────────
            wlog("Blue-green deploy: cloning {$activeRelease} into releases dir");

            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeRelease);
            wlog("  remote:      {$gitRemote}");
            wlog("  release dir: {$releaseDir}");

            // Initialize release directory with git clone
            wlog("Cloning into {$releaseDir}...");
            if (!BlueGreen::initReleaseDir($repo_dir, $activeRelease, $gitRemote)) {
                wlog("ERROR: Git clone failed for {$activeRelease} into {$releaseDir}");
                wlog("  Check: git remote accessible? Credentials valid? Disk space?");
                sleep($interval);
                continue;
            }
            wlog("Clone complete");

            // Checkout version tag
            if (!BlueGreen::checkoutVersion($releaseDir, $activeRelease)) {
                wlog("ERROR: Failed to checkout tag {$activeRelease} in {$releaseDir}");
                wlog("  Check: does tag exist? Run 'git -C {$releaseDir} tag -l'");
                sleep($interval);
                continue;
            }
            wlog("Checked out {$activeRelease}");

            // Patch compose file for parameterized ports
            if (!BlueGreen::patchComposeFile($releaseDir)) {
                wlog("ERROR: docker-compose.yml not found in {$releaseDir}");
                wlog("  Cannot deploy without a compose file. Skipping.");
                sleep($interval);
                continue;
            }

            // Stop any existing containers in this release dir before building.
            // On retry after a failed build, stale containers may hold the ports.
            wlog("Stopping any existing containers in {$releaseDir} before build...");
            BlueGreen::stopContainers($releaseDir);

            // Find available shadow ports (bluegreen builds on shadow, not production)
            [$shadowHttp, $shadowHttps] = BlueGreen::findAvailableShadowPorts();

            // Write shadow port config
            BlueGreen::writeReleaseEnv($releaseDir, $shadowHttp, $shadowHttps, $activeRelease);

            // Build containers on shadow ports (slow step)
            wlog("Building containers on shadow ports ({$shadowHttp}/{$shadowHttps})...");
            $buildOutput = null;
            if (!BlueGreen::buildContainers($releaseDir, $buildOutput)) {
                wlog("ERROR: Docker build failed for {$activeRelease}");
                wlog("BUILD OUTPUT: " . trim($buildOutput ?? '(no output)'));
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                sleep($interval);
                continue;
            }
            wlog("Shadow containers built on port {$shadowHttp}");

            // Run health checks (bluegreen-only — release strategy skips this)
            $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
            wlog("Running health checks (" . count($healthChecks) . " configured)...");
            $healthy = BlueGreen::runHealthChecks($repo_dir, $shadowHttp, $healthChecks, $activeRelease);

            if ($healthy) {
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'ready');
                BlueGreen::setShadowVersion($repo_dir, $activeRelease);
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease);
                wlog("Shadow {$activeRelease} ready");

                // Auto-promote if configured
                $autoPromote = Json::read('bluegreen.auto_promote', false, $repo_dir);
                if ($autoPromote) {
                    wlog("Auto-promoting {$activeRelease} to production...");
                    $promoted = BlueGreen::promote($repo_dir, $activeRelease);
                    if ($promoted) {
                        AuditLog::logShadow($repo_dir, 'promote', $activeRelease, $activeRelease);
                        AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'shadow-auto-promote');
                        wlog("Auto-promoted {$activeRelease} to production");

                        // Update state
                        JsonLock::write('release.previous', $currentRelease, $repo_dir);
                        JsonLock::write('release.current', $activeRelease, $repo_dir);
                        JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
                        JsonLock::save($repo_dir);
                        wlog("Release state updated: current={$activeRelease}");

                        // Stop old release, start new release with full bootstrapping
                        $bgStopDir = $repo_dir;
                        if ($currentRelease) {
                            $oldDir = BlueGreen::getReleaseDir($repo_dir, $currentRelease);
                            if (is_dir($oldDir)) {
                                $bgStopDir = $oldDir;
                            }
                        }
                        protocolStopStart($bgStopDir, $releaseDir);
                    } else {
                        wlog("ERROR: Auto-promote failed for {$activeRelease} — will retry next cycle");
                    }
                } else {
                    wlog("Shadow ready. Run 'protocol shadow:start' to promote.");
                }
            } else {
                BlueGreen::setReleaseState($repo_dir, $activeRelease, $shadowHttp, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                wlog("ERROR: Health check FAILED for {$activeRelease} — will retry next cycle");
            }

        } else {
            // ─────────────────────────────────────────────────────
            // BRANCH STRATEGY (fallback) — In-place deployment
            //
            // Checks out the tag directly in repo_dir (detached HEAD)
            // and rebuilds containers in-place. No release directories.
            // This is the legacy mode used when strategy is "branch"
            // or any unrecognized value.
            // ─────────────────────────────────────────────────────
            wlog("In-place deploy: checking out {$activeRelease} in {$repo_dir}");

            // Checkout the tag (detached HEAD)
            Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout " . escapeshellarg($activeRelease) . " 2>&1");
            wlog("Checked out {$activeRelease}");

            // Update lock file
            JsonLock::write('release.previous', $currentRelease, $repo_dir);
            JsonLock::write('release.current', $activeRelease, $repo_dir);
            JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
            JsonLock::save($repo_dir);

            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'watcher');
            wlog("Tag {$activeRelease} checked out. Running protocol stop+start for full bootstrapping...");

            // Branch strategy: stop and start both use repo_dir (in-place deploy)
            protocolStopStart($repo_dir, $repo_dir);
        }
    } catch (\Exception $e) {
        wlog("ERROR: " . $e->getMessage());
        wlog("  " . $e->getFile() . ":" . $e->getLine());
    }

    sleep($interval);
}
