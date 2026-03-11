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
    } catch (\Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    }

    sleep($interval);
}
