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
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Log;
use Gitcd\Utils\JsonLock;
use Gitcd\Helpers\DeploymentState;

Class DeployReleaseSlave extends Command {

    protected static $defaultName = 'deploy:slave';
    protected static $defaultDescription = 'Start the release watcher daemon to poll for active release changes';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Starts the release watcher daemon in the background.
            The daemon polls the GitHub repository variable for release changes
            and automatically deploys when a new release is set.

            HELP)
        ;
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('no-daemon', null, InputOption::VALUE_NONE, 'Run in foreground (for debugging)')
            ->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Poll interval in seconds', 60)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (\Throwable $e) {
            Log::error('deploy:slave', "uncaught exception: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}");
            $output->writeln('<error>deploy:slave failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $rawDir = $input->getOption('dir');
        $repo_dir = Dir::realpath($rawDir);

        Log::context('deploy:slave', [
            'event'    => 'init',
            'raw_dir'  => $rawDir ?: '(null)',
            'repo_dir' => $repo_dir ?: '(null)',
        ]);

        Git::checkInitializedRepo($output, $repo_dir);
        Log::debug('deploy:slave', "repo check passed");

        $interval = (int) $input->getOption('interval');

        Log::context('deploy:slave', [
            'repo_dir' => $repo_dir,
            'interval' => $interval,
        ]);

        // Check if already running
        $pid = DeploymentState::watcherPid($repo_dir);
        Log::debug('deploy:slave', "existing watcher pid=" . ($pid ?: 'none'));

        if ($pid && Shell::isRunning($pid)) {
            Log::info('deploy:slave', "watcher already running (PID: {$pid})");
            $output->writeln("<comment>Release watcher is already running (PID: {$pid})</comment>");
            return Command::SUCCESS;
        }

        $watcherScript = SCRIPT_DIR . 'release-watcher.php';
        Log::debug('deploy:slave', "SCRIPT_DIR=" . SCRIPT_DIR . " watcher_script={$watcherScript} exists=" . (is_file($watcherScript) ? 'yes' : 'no'));

        if (!is_file($watcherScript)) {
            Log::error('deploy:slave', "watcher script not found at {$watcherScript}");
            $output->writeln('<error>Release watcher script not found at: ' . $watcherScript . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('no-daemon')) {
            Log::info('deploy:slave', "running in foreground mode");
            $output->writeln('<comment>Running release watcher in foreground (Ctrl+C to stop)</comment>');
            Shell::passthru("php " . escapeshellarg($watcherScript) . " --dir=" . escapeshellarg($repo_dir) . " --interval={$interval}");
            return Command::SUCCESS;
        }

        // Start as daemon — chdir to repo_dir first so the child process
        // inherits a valid cwd. Without this, the watcher's shell commands
        // may fail with "shell-init: getcwd" if the parent cwd is stale.
        $parentCwd = @getcwd();
        if ($parentCwd === false || !is_dir($parentCwd)) {
            chdir($repo_dir);
            Log::warn('deploy:slave', "parent cwd was invalid ({$parentCwd}), anchored to {$repo_dir}");
            $output->writeln("<comment>Parent cwd was invalid ({$parentCwd}), anchored to {$repo_dir}</comment>");
        } else {
            Log::debug('deploy:slave', "parent cwd={$parentCwd}");
        }

        $logFile = Log::getLogFile();
        $watcherLog = dirname($logFile) . '/watcher.log';

        // Spawn daemon using proc_open() with non-blocking pipe reads.
        // PHP's exec() blocks forever because the nohup'd child inherits
        // the stdout pipe FD, keeping it open until the daemon exits.
        // proc_open() lets us read the PID with a timeout and move on.
        $cmd = "cd " . escapeshellarg(rtrim($repo_dir, '/'))
            . " && nohup php " . escapeshellarg($watcherScript)
            . " --dir=" . escapeshellarg($repo_dir)
            . " --interval={$interval}"
            . " >> " . escapeshellarg($watcherLog) . " 2>&1 </dev/null & echo \$!";

        Log::context('deploy:slave', [
            'event'          => 'spawning_daemon',
            'cmd'            => $cmd,
            'watcher_log'    => $watcherLog,
            'watcher_script' => $watcherScript,
            'script_exists'  => is_file($watcherScript) ? 'yes' : 'no',
            'cwd'            => getcwd() ?: 'FALSE',
        ]);

        $newPid = self::spawnDaemon($cmd);

        Log::context('deploy:slave', [
            'event'      => 'spawn_result',
            'raw_pid'    => $newPid ?: '(empty)',
            'is_numeric' => is_numeric($newPid) ? 'yes' : 'no',
        ]);

        if ($newPid && is_numeric($newPid)) {
            JsonLock::write('release.slave.pid', (int) $newPid, $repo_dir);
            JsonLock::save($repo_dir);
            DeploymentState::setWatcherPid($repo_dir, (int) $newPid);

            // Verify the process is actually alive after a brief moment
            usleep(500000); // 0.5s
            $alive = Shell::isRunning((int) $newPid);

            Log::context('deploy:slave', [
                'event'       => 'daemon_started',
                'pid'         => $newPid,
                'alive_check' => $alive ? 'yes' : 'NO',
                'watcher_log' => $watcherLog,
            ]);

            if (!$alive) {
                Log::warn('deploy:slave', "daemon PID {$newPid} died immediately — check {$watcherLog} for errors");
                $output->writeln("<comment>Warning: watcher process {$newPid} exited immediately. Check log: {$watcherLog}</comment>");
            }

            $output->writeln("<info>Release watcher started (PID: {$newPid})</info>");
            $output->writeln("Log: {$watcherLog}");
        } else {
            Log::error('deploy:slave', "failed to start daemon, shell returned: " . ($newPid ?: '(empty)'));
            $output->writeln('<error>Failed to start release watcher daemon</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Spawn a background command via proc_open() and return its PID.
     *
     * PHP's exec() blocks forever when a backgrounded child inherits
     * the stdout pipe. proc_open() lets us read the PID from "echo $!"
     * with a timeout and move on, even if the pipe stays open.
     */
    private static function spawnDaemon(string $cmd): string
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            Log::error('deploy:slave', "proc_open failed");
            return '';
        }

        // Non-blocking read with 5s timeout to get PID from "echo $!"
        stream_set_blocking($pipes[1], false);
        $pidOutput = '';
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $chunk = fread($pipes[1], 4096);
            if ($chunk !== false && $chunk !== '') {
                $pidOutput .= $chunk;
            }
            if (feof($pipes[1])) {
                break;
            }
            usleep(50000); // 50ms
        }

        fclose($pipes[1]);

        // proc_close() calls waitpid() — the shell should have exited
        // after "echo $!" so this returns quickly.
        proc_close($process);

        return trim($pidOutput);
    }
}
