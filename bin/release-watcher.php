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
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\SecretsProvider;
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

/**
 * Log a message with timestamp.
 */
function wlog(string $msg): void {
    echo "[" . date('Y-m-d H:i:s') . "] {$msg}\n";
}

wlog("Release watcher started");
wlog("  repo_dir:  {$repo_dir}");
wlog("  interval:  {$interval}s");

$pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
wlog("  pointer:   {$pointerName}");

// Log initial state
$bluegreenEnabled = BlueGreen::isEnabled($repo_dir);
wlog("  bluegreen: " . ($bluegreenEnabled ? 'enabled' : 'disabled'));
if ($bluegreenEnabled) {
    wlog("  releases:  " . BlueGreen::getReleasesDir($repo_dir));
}

$currentRelease = JsonLock::read('release.current', null, $repo_dir);
wlog("  current:   " . ($currentRelease ?: 'none'));

$pollCount = 0;

while (true) {
    $pollCount++;

    try {
        // Refresh credentials before each poll (tokens expire after 1 hour)
        if (GitHubApp::isConfigured()) {
            $creds = GitHubApp::loadCredentials();
            $appOwner = $creds['owner'] ?? null;
            if ($appOwner) {
                GitHubApp::refreshGitCredentials($appOwner);
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

        // Fetch latest tags
        $remote = Git::remoteName($repo_dir) ?: 'origin';
        wlog("Fetching tags from {$remote}...");
        Shell::run("GIT_TERMINAL_PROMPT=0 timeout 30 git -C " . escapeshellarg($repo_dir) . " fetch {$remote} --tags 2>/dev/null");

        // Verify tag exists
        if (!GitHub::tagExists($activeRelease, $repo_dir)) {
            wlog("WARNING: Tag {$activeRelease} not found locally after fetch. Skipping.");
            sleep($interval);
            continue;
        }
        wlog("Tag {$activeRelease} verified");

        // ── Blue-green mode ──────────────────────────────────
        $bluegreenEnabled = BlueGreen::isEnabled($repo_dir);

        if ($bluegreenEnabled) {
            wlog("Blue-green deploy: cloning {$activeRelease} into releases dir");

            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            $releaseDir = BlueGreen::getReleaseDir($repo_dir, $activeRelease);
            wlog("  remote:      {$gitRemote}");
            wlog("  release dir: {$releaseDir}");

            // Initialize release directory with git clone
            wlog("Cloning into {$releaseDir}...");
            BlueGreen::initReleaseDir($repo_dir, $activeRelease, $gitRemote);

            // Checkout version tag
            if (!BlueGreen::checkoutVersion($releaseDir, $activeRelease)) {
                wlog("ERROR: Failed to checkout {$activeRelease} in {$releaseDir}");
                sleep($interval);
                continue;
            }
            wlog("Checked out {$activeRelease}");

            // Patch compose file for parameterized ports
            BlueGreen::patchComposeFile($releaseDir);

            // Write shadow port config
            BlueGreen::writeReleaseEnv($releaseDir, BlueGreen::SHADOW_HTTP, BlueGreen::SHADOW_HTTPS, $activeRelease);

            // Build containers on shadow ports (slow step)
            wlog("Building containers on shadow ports (" . BlueGreen::SHADOW_HTTP . "/" . BlueGreen::SHADOW_HTTPS . ")...");
            if (!BlueGreen::buildContainers($releaseDir)) {
                wlog("ERROR: Docker build failed for {$activeRelease}");
                BlueGreen::setReleaseState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                sleep($interval);
                continue;
            }
            wlog("Shadow containers built on port " . BlueGreen::SHADOW_HTTP);

            // Run health checks
            $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
            wlog("Running health checks (" . count($healthChecks) . " configured)...");
            $healthy = BlueGreen::runHealthChecks($repo_dir, BlueGreen::SHADOW_HTTP, $healthChecks, $activeRelease);

            if ($healthy) {
                BlueGreen::setReleaseState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'ready');
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
                    } else {
                        wlog("ERROR: Auto-promote failed for {$activeRelease}");
                    }
                } else {
                    wlog("Shadow ready. Run 'protocol shadow:start' to promote.");
                }
            } else {
                BlueGreen::setReleaseState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'failed');
                AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                wlog("ERROR: Health check FAILED for {$activeRelease}");
            }

            // Update release tracking
            JsonLock::write('release.previous', $currentRelease, $repo_dir);
            JsonLock::write('release.current', $activeRelease, $repo_dir);
            JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
            JsonLock::save($repo_dir);
            wlog("Release state updated: current={$activeRelease}");

        } else {
            // ── Standard in-place deployment ─────────────────
            wlog("In-place deploy: checking out {$activeRelease} in {$repo_dir}");

            // Checkout the tag (detached HEAD)
            Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout " . escapeshellarg($activeRelease) . " 2>&1");
            wlog("Checked out {$activeRelease}");

            // Update lock file
            JsonLock::write('release.previous', $currentRelease, $repo_dir);
            JsonLock::write('release.current', $activeRelease, $repo_dir);
            JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
            JsonLock::save($repo_dir);

            // Handle secrets (encrypted or AWS Secrets Manager)
            $tmpEnv = SecretsProvider::resolveToTempFile($repo_dir);

            if ($tmpEnv) {
                wlog("Rebuilding Docker containers with secrets...");
                Shell::run("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " --env-file " . escapeshellarg($tmpEnv) . " up -d --build 2>&1");
                unlink($tmpEnv);
            } else {
                wlog("Rebuilding Docker containers...");
                Shell::run("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " up -d --build 2>&1");
            }

            // Audit log
            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'watcher');
            wlog("Deploy complete: {$activeRelease}");
        }
    } catch (\Exception $e) {
        wlog("ERROR: " . $e->getMessage());
        wlog("  " . $e->getFile() . ":" . $e->getLine());
    }

    sleep($interval);
}
