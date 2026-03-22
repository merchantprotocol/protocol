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
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\GitHubApp;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

// Parse arguments
$options = getopt('', ['dir:', 'interval:']);
$repo_dir = $options['dir'] ?? getcwd();
$interval = (int) ($options['interval'] ?? 60);

$repo_dir = realpath($repo_dir) . DIRECTORY_SEPARATOR;

// Anchor working directory to repo_dir so that shell commands don't break
// when a release directory is removed underneath us. This MUST happen before
// any shell commands or file operations. Re-applied at the top of each cycle.
chdir($repo_dir);

/**
 * Log a message with timestamp.
 */
function wlog(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
}

/**
 * Run `protocol restart` to do a full stop+start cycle with all bootstrapping.
 *
 * This replaces the old selfRestart approach. Instead of just respawning the
 * watcher, we run the full `protocol restart` which handles:
 *   - Config unlinking/relinking
 *   - Stopping ALL containers (including bare legacy ones)
 *   - Starting containers from the active release directory
 *   - Health checks
 *   - Crontab setup
 *   - Spawning a fresh watcher (which picks up new code)
 *
 * The restart is spawned as a detached background process so this watcher
 * can exit immediately. `protocol stop` (part of restart) will clean up
 * any remaining watcher processes.
 */
function protocolRestart(string $repo_dir): void
{
    $protocolBin = dirname(__DIR__) . '/protocol';
    if (!is_file($protocolBin)) {
        wlog("WARNING: protocol binary not found at {$protocolBin} — cannot restart");
        return;
    }

    $logDir = is_writable('/var/log/protocol/') ? '/var/log/protocol/' : $repo_dir;
    $logFile = $logDir . 'protocol-restart.log';

    // Run protocol restart as a detached background process.
    // This watcher will be killed by `protocol stop` (part of restart),
    // and a fresh watcher will be spawned by `protocol start`.
    $cmd = "nohup php " . escapeshellarg($protocolBin)
        . " restart --dir=" . escapeshellarg($repo_dir)
        . " >> " . escapeshellarg($logFile) . " 2>&1 &";

    wlog("Triggering protocol restart: {$cmd}");
    Shell::run($cmd);

    // Give the background process a moment to start before we exit
    sleep(2);
    wlog("Protocol restart spawned. Watcher (PID: " . getmypid() . ") exiting.");
    exit(0);
}

wlog("Release watcher started");
wlog("  repo_dir:  {$repo_dir}");
wlog("  interval:  {$interval}s");

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
        // Re-anchor working directory every cycle. A previous cycle may have
        // deleted the release dir we were sitting in, causing every subsequent
        // shell command to fail with "getcwd: cannot access parent directories".
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
            wlog("Release {$activeRelease} prepared. Running protocol restart for full bootstrapping...");

            // Hand off to protocol restart for full stop+start cycle.
            // This handles config linking, container start, health checks,
            // crontab, and spawns a fresh watcher with updated code.
            // This watcher exits as part of the restart.
            protocolRestart($repo_dir);

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

                        // Full restart for bootstrapping + fresh watcher
                        protocolRestart($repo_dir);
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
            wlog("Tag {$activeRelease} checked out. Running protocol restart for full bootstrapping...");

            // Hand off to protocol restart for full stop+start cycle.
            // Handles secrets, container build, config linking, crontab, etc.
            protocolRestart($repo_dir);
        }
    } catch (\Exception $e) {
        wlog("ERROR: " . $e->getMessage());
        wlog("  " . $e->getFile() . ":" . $e->getLine());
    }

    sleep($interval);
}
