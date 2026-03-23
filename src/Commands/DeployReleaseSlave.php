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
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        $interval = (int) $input->getOption('interval');

        // Check if already running
        $pid = DeploymentState::watcherPid($repo_dir);
        if ($pid && Shell::isRunning($pid)) {
            $output->writeln("<comment>Release watcher is already running (PID: {$pid})</comment>");
            return Command::SUCCESS;
        }

        $watcherScript = SCRIPT_DIR . 'release-watcher.php';
        if (!is_file($watcherScript)) {
            $output->writeln('<error>Release watcher script not found at: ' . $watcherScript . '</error>');
            return Command::FAILURE;
        }

        if ($input->getOption('no-daemon')) {
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
            $output->writeln("<comment>Parent cwd was invalid ({$parentCwd}), anchored to {$repo_dir}</comment>");
        }

        $logFile = Log::getLogFile();
        $cmd = "cd " . escapeshellarg(rtrim($repo_dir, '/')) . " && nohup php " . escapeshellarg($watcherScript)
            . " --dir=" . escapeshellarg($repo_dir)
            . " --interval={$interval}"
            . " >> " . escapeshellarg($logFile) . " 2>&1 & echo $!";

        $newPid = trim(Shell::run($cmd));

        if ($newPid && is_numeric($newPid)) {
            JsonLock::write('release.slave.pid', (int) $newPid, $repo_dir);
            JsonLock::save($repo_dir);
            DeploymentState::setWatcherPid($repo_dir, (int) $newPid);
            $output->writeln("<info>Release watcher started (PID: {$newPid})</info>");
            $output->writeln("Log: {$logFile}");
        } else {
            $output->writeln('<error>Failed to start release watcher daemon</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
