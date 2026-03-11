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
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

// Parse arguments
$options = getopt('', ['dir:', 'interval:']);
$repo_dir = $options['dir'] ?? getcwd();
$interval = (int) ($options['interval'] ?? 60);

$repo_dir = realpath($repo_dir) . DIRECTORY_SEPARATOR;

echo "[" . date('Y-m-d H:i:s') . "] Release watcher started for: {$repo_dir}\n";
echo "[" . date('Y-m-d H:i:s') . "] Poll interval: {$interval}s\n";

$pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);

while (true) {
    try {
        $activeRelease = GitHub::getVariable($pointerName, $repo_dir);
        $currentRelease = JsonLock::read('release.current', null, $repo_dir);

        if ($activeRelease && $activeRelease !== $currentRelease) {
            echo "[" . date('Y-m-d H:i:s') . "] New release detected: {$activeRelease} (was: " . ($currentRelease ?: 'none') . ")\n";

            // Fetch latest tags
            $remote = Git::remoteName($repo_dir) ?: 'origin';
            Shell::run("git -C " . escapeshellarg($repo_dir) . " fetch {$remote} --tags 2>/dev/null");

            // Verify tag exists
            if (!GitHub::tagExists($activeRelease, $repo_dir)) {
                echo "[" . date('Y-m-d H:i:s') . "] WARNING: Tag {$activeRelease} not found. Skipping.\n";
                sleep($interval);
                continue;
            }

            // ── Blue-green mode ──────────────────────────────────
            $bluegreenEnabled = BlueGreen::isEnabled($repo_dir);

            if ($bluegreenEnabled) {
                echo "[" . date('Y-m-d H:i:s') . "] Shadow mode: building slots/{$activeRelease}/\n";

                $slotDir = BlueGreen::getSlotDir($repo_dir, $activeRelease);

                // Initialize slot directory with git clone
                $gitRemote = Git::RemoteUrl($repo_dir);
                BlueGreen::initSlotDir($repo_dir, $activeRelease, $gitRemote);

                // Checkout version tag
                if (!BlueGreen::checkoutVersion($slotDir, $activeRelease)) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to checkout {$activeRelease}\n";
                    sleep($interval);
                    continue;
                }
                echo "[" . date('Y-m-d H:i:s') . "] Checked out {$activeRelease} in slots/{$activeRelease}/\n";

                // Patch compose file for parameterized ports
                BlueGreen::patchComposeFile($slotDir);

                // Write shadow port config
                BlueGreen::writeSlotEnv($slotDir, BlueGreen::SHADOW_HTTP, BlueGreen::SHADOW_HTTPS, $activeRelease);

                // Build containers on shadow ports (slow step)
                echo "[" . date('Y-m-d H:i:s') . "] Building containers on shadow ports...\n";
                if (!BlueGreen::buildContainers($slotDir)) {
                    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Docker build failed for {$activeRelease}\n";
                    BlueGreen::setSlotState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'failed');
                    AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                    sleep($interval);
                    continue;
                }
                echo "[" . date('Y-m-d H:i:s') . "] Shadow containers built on port " . BlueGreen::SHADOW_HTTP . "\n";

                // Run health checks
                $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
                $healthy = BlueGreen::runHealthChecks($repo_dir, BlueGreen::SHADOW_HTTP, $healthChecks, $activeRelease);

                if ($healthy) {
                    BlueGreen::setSlotState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'ready');
                    BlueGreen::setShadowVersion($repo_dir, $activeRelease);
                    AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease);
                    echo "[" . date('Y-m-d H:i:s') . "] Shadow {$activeRelease} ready\n";

                    // Auto-promote if configured
                    $autoPromote = Json::read('bluegreen.auto_promote', false, $repo_dir);
                    if ($autoPromote) {
                        echo "[" . date('Y-m-d H:i:s') . "] Auto-promoting {$activeRelease} to production...\n";
                        $promoted = BlueGreen::promote($repo_dir, $activeRelease);
                        if ($promoted) {
                            AuditLog::logShadow($repo_dir, 'promote', $activeRelease, $activeRelease);
                            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'shadow-auto-promote');
                            echo "[" . date('Y-m-d H:i:s') . "] Auto-promoted {$activeRelease} to production\n";
                        } else {
                            echo "[" . date('Y-m-d H:i:s') . "] ERROR: Auto-promote failed\n";
                        }
                    } else {
                        echo "[" . date('Y-m-d H:i:s') . "] Shadow ready. Run 'protocol shadow:start' to promote.\n";
                    }
                } else {
                    BlueGreen::setSlotState($repo_dir, $activeRelease, BlueGreen::SHADOW_HTTP, 'failed');
                    AuditLog::logShadow($repo_dir, 'build', $activeRelease, $activeRelease, 'failure');
                    echo "[" . date('Y-m-d H:i:s') . "] Health check FAILED for {$activeRelease}\n";
                }

                // Update release tracking
                JsonLock::write('release.previous', $currentRelease, $repo_dir);
                JsonLock::write('release.current', $activeRelease, $repo_dir);
                JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
                JsonLock::save($repo_dir);

            } else {
                // ── Standard in-place deployment ─────────────────

                // Checkout the tag (detached HEAD)
                Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout " . escapeshellarg($activeRelease) . " 2>&1");
                echo "[" . date('Y-m-d H:i:s') . "] Checked out {$activeRelease}\n";

                // Update lock file
                JsonLock::write('release.previous', $currentRelease, $repo_dir);
                JsonLock::write('release.current', $activeRelease, $repo_dir);
                JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
                JsonLock::save($repo_dir);

                // Handle encrypted secrets
                $secretsMode = Json::read('deployment.secrets', 'file', $repo_dir);
                $configRepo = Config::repo($repo_dir);

                if ($secretsMode === 'encrypted' && $configRepo) {
                    $encFile = $configRepo . '.env.enc';
                    if (is_file($encFile) && Secrets::hasKey()) {
                        $tmpEnv = Secrets::decryptToTempFile($encFile);
                        if ($tmpEnv) {
                            Shell::run("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " --env-file " . escapeshellarg($tmpEnv) . " up -d --build 2>&1");
                            unlink($tmpEnv);
                            echo "[" . date('Y-m-d H:i:s') . "] Docker rebuilt with decrypted secrets\n";
                        } else {
                            echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to decrypt secrets\n";
                        }
                    } else {
                        Shell::run("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " up -d --build 2>&1");
                        echo "[" . date('Y-m-d H:i:s') . "] Docker rebuilt\n";
                    }
                } else {
                    Shell::run("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " up -d --build 2>&1");
                    echo "[" . date('Y-m-d H:i:s') . "] Docker rebuilt\n";
                }

                // Audit log
                AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $activeRelease, 'success', 'watcher');
                echo "[" . date('Y-m-d H:i:s') . "] Deploy complete: {$activeRelease}\n";
            }
        }
    } catch (\Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}
