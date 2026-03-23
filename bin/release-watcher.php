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

use Gitcd\Helpers\Log;
use Gitcd\Helpers\ReleaseWatcher;

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

// Log the raw bootstrap state before handing off to the class
Log::context('watcher', [
    'event'         => 'bootstrap',
    'pid'           => getmypid(),
    'inherited_cwd' => $inheritedCwd ?: 'FALSE',
    'dir_arg'       => $rawDir ?: '(not set)',
    'repo_dir'      => $repo_dir,
    'repo_exists'   => is_dir($repo_dir) ? 'yes' : 'NO',
    'interval'      => $interval,
]);

$watcher = new ReleaseWatcher($repo_dir, $interval);
$watcher->run();
