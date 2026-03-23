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
 * @category   merchantprotocol
 * @package    merchantprotocol/protocol
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

/**
 * Release Watcher Daemon
 *
 * Polls the GitHub repository variable for release changes and automatically
 * deploys when a new release is detected.
 *
 * Controller/Worker pattern:
 *   - run() is the main loop (router)
 *   - pollCycle() is the controller per cycle
 *   - Strategy handlers are workers that do work, never make decisions
 */
class ReleaseWatcher
{
    private string $repoDir;
    private int $interval;
    private string $pointerName;
    private int $pollCount = 0;

    public function __construct(string $repoDir, int $interval = 60)
    {
        $this->repoDir = $repoDir;
        $this->interval = $interval;
        $this->pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repoDir);
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROUTER — main loop, delegates to controller
    // ═══════════════════════════════════════════════════════════════

    public function run(): void
    {
        $this->logStartup();
        $this->checkIncompleteDeployOnStartup();

        while (true) {
            $this->pollCount++;

            try {
                $this->anchorWorkingDirectory();
                Json::clearInstances();
                $this->refreshCredentials();

                $this->pollCycle();
            } catch (\Exception $e) {
                Log::error('watcher', $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            }

            sleep($this->interval);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  CONTROLLER — decides what to do each cycle, calls workers
    // ═══════════════════════════════════════════════════════════════

    private function pollCycle(): void
    {
        $activeRelease = GitHub::getVariable($this->pointerName, $this->repoDir);
        $currentRelease = $this->getCurrentRelease();

        $this->logPollState($activeRelease, $currentRelease);

        if (!$activeRelease) {
            if ($this->pollCount === 1) {
                Log::warn('watcher', "could not read {$this->pointerName} from GitHub — check API access / variable exists");
            }
            return;
        }

        if ($activeRelease === $currentRelease) {
            if ($this->pollCount === 1) {
                Log::info('watcher', "already at {$activeRelease}, watching for changes");
            }
            return;
        }

        // ── New release detected ─────────────────────────────
        Log::context('watcher', [
            'event'    => 'new_release',
            'active'   => $activeRelease,
            'previous' => $currentRelease ?: 'none',
        ]);

        DeploymentState::setTarget($this->repoDir, $activeRelease);
        Log::info('watcher', "Setting release.target={$activeRelease}");

        if (!$this->fetchAndVerifyTag($activeRelease)) {
            return;
        }

        // Dispatch to strategy handler
        $strategy = BlueGreen::getStrategy($this->repoDir);

        if ($strategy === 'release') {
            $this->handleReleaseStrategy($activeRelease, $currentRelease);
        } elseif ($strategy === 'bluegreen') {
            $this->handleBlueGreenStrategy($activeRelease, $currentRelease);
        } else {
            $this->handleBranchStrategy($activeRelease, $currentRelease);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  STRATEGY WORKERS — each handles one deployment strategy
    // ═══════════════════════════════════════════════════════════════

    private function handleReleaseStrategy(string $activeRelease, ?string $currentRelease): void
    {
        $gitRemote = BlueGreen::getGitRemote($this->repoDir);
        $releaseDir = BlueGreen::getReleaseDir($this->repoDir, $activeRelease);

        Log::context('watcher', [
            'action'      => 'release_deploy',
            'version'     => $activeRelease,
            'remote'      => $gitRemote,
            'release_dir' => $releaseDir,
        ]);

        if (!$this->initAndCheckoutRelease($releaseDir, $activeRelease, $gitRemote)) {
            return;
        }

        BlueGreen::writeReleaseEnv(
            $releaseDir,
            BlueGreen::PRODUCTION_HTTP,
            BlueGreen::PRODUCTION_HTTPS,
            $activeRelease
        );

        Log::info('watcher', "Setting {$activeRelease} status=pending");
        BlueGreen::setReleaseState($this->repoDir, $activeRelease, BlueGreen::PRODUCTION_HTTP, 'pending');

        // NOTE: We do NOT set release.active here. ProtocolStart will set it
        // after containers start successfully. If start fails, target != active
        // and the next watcher startup will retry.

        AuditLog::logDeploy($this->repoDir, $currentRelease ?: 'none', $activeRelease, 'initiated', 'release-watcher');
        Log::info('watcher', "release {$activeRelease} prepared, running stop+start");

        $stopDir = $this->resolveStopDir($currentRelease);
        $this->protocolStopStart($stopDir, $releaseDir);
    }

    private function handleBlueGreenStrategy(string $activeRelease, ?string $currentRelease): void
    {
        $gitRemote = BlueGreen::getGitRemote($this->repoDir);
        $releaseDir = BlueGreen::getReleaseDir($this->repoDir, $activeRelease);

        Log::context('watcher', [
            'action'      => 'bluegreen_deploy',
            'version'     => $activeRelease,
            'remote'      => $gitRemote,
            'release_dir' => $releaseDir,
        ]);

        if (!$this->initAndCheckoutRelease($releaseDir, $activeRelease, $gitRemote)) {
            return;
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

        if (!$this->buildAndHealthCheck($releaseDir, $activeRelease, $shadowHttp)) {
            return;
        }

        // Shadow is healthy — mark ready
        Log::info('watcher', "Shadow {$activeRelease} healthy, marking ready");
        BlueGreen::setReleaseState($this->repoDir, $activeRelease, $shadowHttp, 'ready');
        BlueGreen::setShadowVersion($this->repoDir, $activeRelease);
        AuditLog::logShadow($this->repoDir, 'build', $activeRelease, $activeRelease);
        Log::info('watcher', "shadow {$activeRelease} ready");

        $this->autoPromoteIfEnabled($releaseDir, $activeRelease, $currentRelease);
    }

    private function handleBranchStrategy(string $activeRelease, ?string $currentRelease): void
    {
        Log::context('watcher', [
            'action'   => 'branch_deploy',
            'version'  => $activeRelease,
            'repo_dir' => $this->repoDir,
        ]);

        Shell::run("git -C " . escapeshellarg($this->repoDir) . " checkout " . escapeshellarg($activeRelease) . " 2>&1");
        Log::info('watcher', "checked out {$activeRelease}");

        $this->updateNodeConfigForBranch($activeRelease, $currentRelease);

        AuditLog::logDeploy($this->repoDir, $currentRelease ?: 'none', $activeRelease, 'success', 'watcher');
        Log::info('watcher', "tag {$activeRelease} checked out, running stop+start");

        $this->protocolStopStart($this->repoDir, $this->repoDir);
    }

    // ═══════════════════════════════════════════════════════════════
    //  SHARED WORKERS — reusable across strategies
    // ═══════════════════════════════════════════════════════════════

    private function fetchAndVerifyTag(string $version): bool
    {
        $gitRemote = BlueGreen::getGitRemote($this->repoDir) ?: Git::RemoteUrl($this->repoDir);
        $fetchUrl = GitHubApp::resolveUrl($gitRemote);
        $fetchResult = Shell::run(
            "GIT_TERMINAL_PROMPT=0 timeout 30 git -C " . escapeshellarg($this->repoDir) . " fetch " . escapeshellarg($fetchUrl) . " --tags 2>&1",
            $fetchReturn
        );
        if ($fetchReturn !== 0) {
            Log::warn('watcher', "tag fetch failed (exit={$fetchReturn}): " . trim($fetchResult));
        }

        if (!GitHub::tagExists($version, $this->repoDir)) {
            Log::warn('watcher', "tag {$version} not found locally after fetch, skipping");
            return false;
        }
        Log::info('watcher', "tag {$version} verified");
        return true;
    }

    private function initAndCheckoutRelease(string $releaseDir, string $version, ?string $gitRemote): bool
    {
        if (!BlueGreen::initReleaseDir($this->repoDir, $version, $gitRemote)) {
            Log::error('watcher', "git clone failed for {$version} into {$releaseDir}");
            return false;
        }

        if (!BlueGreen::checkoutVersion($releaseDir, $version)) {
            Log::error('watcher', "failed to checkout tag {$version} in {$releaseDir}");
            return false;
        }

        if (!BlueGreen::patchComposeFile($releaseDir)) {
            Log::error('watcher', "docker-compose.yml not found in {$releaseDir}");
            return false;
        }

        return true;
    }

    private function buildAndHealthCheck(string $releaseDir, string $version, int $shadowHttp): bool
    {
        $buildOutput = null;
        if (!BlueGreen::buildContainers($releaseDir, $buildOutput)) {
            Log::error('watcher', "docker build failed for {$version}: " . trim($buildOutput ?? '(no output)'));
            Log::error('watcher', "Marking {$version} as failed (build)");
            BlueGreen::setReleaseState($this->repoDir, $version, $shadowHttp, 'failed');
            AuditLog::logShadow($this->repoDir, 'build', $version, $version, 'failure');
            return false;
        }
        Log::info('watcher', "shadow containers built on port {$shadowHttp}");

        $healthChecks = Json::read('bluegreen.health_checks', [], $this->repoDir);
        Log::info('watcher', "running " . count($healthChecks) . " health check(s)");
        $healthy = BlueGreen::runHealthChecks($this->repoDir, $shadowHttp, $healthChecks, $version);

        if (!$healthy) {
            Log::error('watcher', "Marking {$version} as failed (health check)");
            BlueGreen::setReleaseState($this->repoDir, $version, $shadowHttp, 'failed');
            AuditLog::logShadow($this->repoDir, 'build', $version, $version, 'failure');
            Log::error('watcher', "health check failed for {$version}, will retry next cycle");
            return false;
        }

        return true;
    }

    private function autoPromoteIfEnabled(string $releaseDir, string $activeRelease, ?string $currentRelease): void
    {
        $autoPromote = Json::read('bluegreen.auto_promote', false, $this->repoDir);
        if (!$autoPromote) {
            Log::info('watcher', "shadow ready, run 'protocol shadow:start' to promote");
            return;
        }

        $promoted = BlueGreen::promote($this->repoDir, $activeRelease);
        if (!$promoted) {
            Log::error('watcher', "auto-promote failed for {$activeRelease}, will retry next cycle");
            return;
        }

        AuditLog::logShadow($this->repoDir, 'promote', $activeRelease, $activeRelease);
        AuditLog::logDeploy($this->repoDir, $currentRelease ?: 'none', $activeRelease, 'success', 'shadow-auto-promote');

        Log::context('watcher', [
            'event'    => 'auto_promoted',
            'version'  => $activeRelease,
            'previous' => $currentRelease ?: 'none',
        ]);

        // NOTE: We do NOT set release.active here. ProtocolStart will
        // set it after containers are confirmed running.

        $stopDir = $this->resolveStopDir($currentRelease);
        $this->protocolStopStart($stopDir, $releaseDir);
    }

    private function resolveStopDir(?string $currentRelease): string
    {
        if ($currentRelease) {
            $oldDir = BlueGreen::getReleaseDir($this->repoDir, $currentRelease);
            if (is_dir($oldDir)) {
                return $oldDir;
            }
        }
        return $this->repoDir;
    }

    private function updateNodeConfigForBranch(string $activeRelease, ?string $currentRelease): void
    {
        $projectName = DeploymentState::resolveProjectName($this->repoDir);
        if (!$projectName) {
            return;
        }

        Log::info('watcher', "Branch deploy: setting release.active={$activeRelease}");
        NodeConfig::modify($projectName, function (array $nd) use ($activeRelease, $currentRelease) {
            $nd['release']['active'] = $activeRelease;
            if ($currentRelease) {
                $nd['release']['previous'] = $currentRelease;
            }
            return $nd;
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  INFRASTRUCTURE WORKERS — setup, logging, lifecycle
    // ═══════════════════════════════════════════════════════════════

    private function logStartup(): void
    {
        $strategy = BlueGreen::getStrategy($this->repoDir);
        $projectName = DeploymentState::resolveProjectName($this->repoDir);
        $currentRelease = $projectName ? NodeConfig::read($projectName, 'release.active') : null;

        Log::context('watcher', [
            'event'     => 'started',
            'pid'       => getmypid(),
            'repo_dir'  => $this->repoDir,
            'interval'  => $this->interval,
            'pointer'   => $this->pointerName,
            'strategy'  => $strategy,
            'current'   => $currentRelease ?: 'none',
            'releases'  => BlueGreen::isEnabled($this->repoDir) ? BlueGreen::getReleasesDir($this->repoDir) : 'n/a',
        ]);
    }

    private function checkIncompleteDeployOnStartup(): void
    {
        $projectName = DeploymentState::resolveProjectName($this->repoDir);
        if (!$projectName) {
            return;
        }

        $target = NodeConfig::read($projectName, 'release.target');
        $current = NodeConfig::read($projectName, 'release.active');
        if ($target && $target !== $current) {
            Log::info('watcher', "detected incomplete deploy: target={$target}, active={$current} — will retry");
        }
    }

    private function anchorWorkingDirectory(): void
    {
        $cycleCwd = @getcwd();
        if ($cycleCwd === false) {
            Log::warn('watcher', "cwd invalid at cycle #{$this->pollCount}, re-anchoring to {$this->repoDir}");
        }
        chdir($this->repoDir);
    }

    private function refreshCredentials(): void
    {
        if (!GitHubApp::isConfigured()) {
            return;
        }

        $creds = GitHubApp::loadCredentials();
        $appOwner = $creds['owner'] ?? null;
        if ($appOwner) {
            $refreshed = GitHubApp::refreshGitCredentials($appOwner);
            if (!$refreshed) {
                Log::warn('watcher', "failed to refresh GitHub App credentials");
            }
        }
    }

    private function getCurrentRelease(): ?string
    {
        $projectName = DeploymentState::resolveProjectName($this->repoDir);
        return $projectName ? NodeConfig::read($projectName, 'release.active') : null;
    }

    private function logPollState(?string $activeRelease, ?string $currentRelease): void
    {
        if ($this->pollCount % 10 === 1) {
            Log::context('watcher', [
                'poll'    => $this->pollCount,
                'pointer' => $this->pointerName,
                'active'  => $activeRelease ?: 'null',
                'current' => $currentRelease ?: 'none',
            ], Log::DEBUG);
        }
    }

    /**
     * Run `protocol stop` on the old directory, then `protocol start` on the new one.
     * Spawns as a background process and exits the watcher.
     */
    private function protocolStopStart(string $stopDir, string $startDir): void
    {
        $protocolBin = dirname(__DIR__, 2) . '/protocol';
        if (!is_file($protocolBin)) {
            Log::warn('watcher', "protocol binary not found at {$protocolBin} — cannot stop/start");
            return;
        }

        $logFile = Log::getLogFile();

        $phpBin = escapeshellarg($protocolBin);
        $stopArg = escapeshellarg($stopDir);
        $startArg = escapeshellarg($startDir);

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
}
