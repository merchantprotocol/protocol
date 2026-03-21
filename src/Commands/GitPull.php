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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

Class GitPull extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'git:pull';
    protected static $defaultDescription = 'Pull from github and update the local repo';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command was designed to be run on a cluster node that is NOT the source of truth. 
            Using this command will overwrite any local files or commits that are not in the remote source
            of truth. Any files on the local node that are not tracked in the repository will be ignored.

            This is much like running a `git pull --force` command, but that doesn't exist. So we built it.
        
            After updating the tracked files, this command will run `composer install` and update your 
            submodules using `git submodule update --init --recursive`.

            This command is used to update your repository when in slave mode. It was specifically designed for
            slave mode.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('local', InputArgument::OPTIONAL, 'The path to your local git repo')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force run, ignoring any existing lock')
            // ...
        ;
    }

    /**
     * We're not looking to remove all changed and untracked files. We only want to overwrite local
     * files that exist in the remote branch. Only the remotely tracked files will be overwritten,
     * and every local file that has been here was left untouched.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getArgument('local'), $input->getOption('dir'));

        $logFile = is_writable('/var/log/protocol/') ? '/var/log/protocol/protocol-start.log' : null;
        $logMsg = function(string $msg) use ($logFile) {
            if ($logFile) {
                @file_put_contents($logFile, "[" . date('H:i:s') . "] [git:pull] {$msg}\n", FILE_APPEND | LOCK_EX);
            }
        };

        $logMsg("enter execute() repo_dir={$repo_dir}");
        Git::checkInitializedRepo( $output, $repo_dir );

        // command should only have one running instance
        $logMsg("acquiring lock...");
        if (!$this->lock()) {
            if ($input->getOption('force')) {
                $this->release();
                $this->lock();
                $logMsg("lock forced");
            } else {
                $logMsg("lock FAILED — another instance running");
                $output->writeln('The command is already running in another process. Use --force (-f) to override.');
                return Command::SUCCESS;
            }
        }
        $logMsg("lock acquired");

        $output->writeln('<comment>Pulling a git repo</comment>');

        // Capture current commit before pull for audit log
        $beforeCommit = trim(Shell::run("git -C " . escapeshellarg($repo_dir) . " rev-parse --short HEAD 2>/dev/null") ?: 'unknown');

        // the .git directory
        $branch = Git::branch( $repo_dir );
        $remote = Git::remoteName( $repo_dir );
        $logMsg("branch={$branch} remote={$remote}");

        // Ensure HOME is set so git finds ~/.gitconfig (credential helper)
        $home = getenv('HOME') ?: (posix_getpwuid(posix_geteuid())['dir'] ?? '');
        $envPrefix = "GIT_TERMINAL_PROMPT=0" . ($home ? " HOME=" . escapeshellarg($home) : "");

        $fetchCmd = "{$envPrefix} timeout 30 git -C " . escapeshellarg($repo_dir) . " fetch $remote";
        $logMsg("fetch cmd: {$fetchCmd}");

        // First, run a fetch to update all origin/<branch> refs to latest:
        $response = Shell::run($fetchCmd, $return_var);
        $logMsg("fetch done: exit={$return_var} response=" . substr($response ?: '', 0, 200));
        if ($response) $output->writeln($response);

        // if the fetch failed, then stop
        if ($return_var) {
            $logMsg("fetch FAILED, aborting");
            $output->writeln('Pull failed, canceling operation...');
            return Command::FAILURE;
        }

        // resets the master branch to what you just fetched.
        // The --hard option changes all the files in your working tree to match the files in origin/master
        $logMsg("running git reset --hard {$remote}/{$branch}");
        $response = Shell::run("git -C " . escapeshellarg($repo_dir) . " reset --hard $remote/$branch");
        $logMsg("reset done");
        if ($response) $output->writeln($response);
        $response = Shell::run("git -C " . escapeshellarg($repo_dir) . " reset --hard HEAD");
        if ($response) $output->writeln($response);

        // run composer install
        $logMsg("running composer:install");
        $command = $this->getApplication()->find('composer:install');
        $returnCode = $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);
        $logMsg("composer:install done exit={$returnCode}");

        // Update the submodules
        $logMsg("running submodule update");
        $submoduleCmd = "{$envPrefix} timeout 60 git -C " . escapeshellarg($repo_dir) . " submodule update --init --recursive";
        if ($output->isVerbose()) {
            $output->writeln("  > {$submoduleCmd}");
            passthru($submoduleCmd . " 2>&1", $subReturn);
        } else {
            $response = Shell::run($submoduleCmd, $subReturn);
            if ($response) $output->writeln($response);
        }
        $logMsg("submodule update done exit={$subReturn}");

        // Audit log for branch-strategy deployments
        $afterCommit = trim(Shell::run("git -C " . escapeshellarg($repo_dir) . " rev-parse --short HEAD 2>/dev/null") ?: 'unknown');
        if ($afterCommit !== $beforeCommit) {
            AuditLog::logDeploy($repo_dir, $beforeCommit, $afterCommit, 'success', 'branch');
            $logMsg("audit logged: {$beforeCommit} -> {$afterCommit}");
        }

        // ── Auto-switch: check if a release is now available ──
        // When running as a fallback branch watcher (awaiting_release=true),
        // poll PROTOCOL_ACTIVE_RELEASE. If a release is detected, update
        // node config to release strategy and exit with code 42 to signal
        // the bash watcher to restart protocol.
        $projectName = NodeConfig::findByRepoDir($repo_dir);
        if (!$projectName) {
            // Also check if repo_dir is inside a releases directory
            $match = NodeConfig::findByActiveDir($repo_dir);
            if ($match) {
                $projectName = $match[0];
            }
        }

        if ($projectName) {
            $nodeData = NodeConfig::load($projectName);
            $awaitingRelease = $nodeData['deployment']['awaiting_release'] ?? false;

            if ($awaitingRelease) {
                $pointerName = $nodeData['deployment']['pointer_name'] ?? 'PROTOCOL_ACTIVE_RELEASE';
                $logMsg("awaiting_release=true, checking {$pointerName}");

                $activeRelease = GitHub::getVariable($pointerName, $repo_dir);

                if ($activeRelease) {
                    $logMsg("Release detected: {$activeRelease} — switching to release strategy");
                    $output->writeln("<info>Release detected: {$activeRelease} — switching from branch to release strategy</info>");

                    // Update unified deployment state
                    DeploymentState::setStrategy($repo_dir, 'release');

                    // Update node config — keep deployment.branch for stop
                    $nodeData['deployment']['strategy'] = 'release';
                    unset($nodeData['deployment']['awaiting_release']);
                    NodeConfig::save($projectName, $nodeData);

                    AuditLog::logConfig($repo_dir, 'strategy_switch', "branch -> release (detected {$activeRelease})");

                    // Exit code 42 tells git-repo-watcher to stop and restart protocol
                    return 42;
                } else {
                    $logMsg("No release found yet, continuing branch polling");
                }
            }
        }

        return Command::SUCCESS;
    }

}