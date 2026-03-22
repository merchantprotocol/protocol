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
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Utils\JsonLock;

Class GitSlave extends BaseSlaveCommand {

    protected static $defaultName = 'git:slave';
    protected static $defaultDescription = 'Continuous deployment keeps the local repo updated with the remote changes';

    /**
     *
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }
        $output->writeln('<comment>Continuously monitoring git repo for remote changes</comment>');

        // Check to see if the PID is still running, fail if it is
        $existingPid = JsonLock::read('slave.pid', null, $repo_dir);
        if ($existingPid && Shell::isRunning($existingPid)) {
            $output->writeln("Slave mode is already running (PID: {$existingPid})");
            return Command::SUCCESS;
        }
        // Clear only the stale PID, not the entire lock file
        JsonLock::write('slave.pid', null, $repo_dir);
        JsonLock::save($repo_dir);
        $remoteName = Git::remoteName( $repo_dir );
        $remoteurl = Git::RemoteUrl( $repo_dir );

        if (!$remoteurl) {
            $output->writeln("Your local repo is not connected to a remote source of truth. cancelling...");
            return Command::FAILURE;
        }

        [$increment, $daemon] = $this->parseSlaveOptions($input);
        $branch = Git::branch( $repo_dir );

        // trigger the daemon or run it yourself
        if ($daemon) {
            $output->writeln(" - This command will run in the background every $increment seconds until you kill it.");
        } else {
            $output->writeln(" - This command will run in the foreground every $increment seconds until you kill it.");
        }
        $output->writeln(" - If any changes are made to $remoteurl we'll update $repo_dir".PHP_EOL);

        // execute command
        $command = SCRIPT_DIR."git-repo-watcher -d '$repo_dir' -o $remoteName -b $branch -h ".SCRIPT_DIR."git-repo-watcher-hooks -i $increment";

        return $this->runSlaveCommand(
            $command,
            $daemon,
            $increment,
            [
                'git.branch'     => $branch,
                'git.remote'     => $remoteurl,
                'git.remotename' => $remoteName,
                'git.local'      => $repo_dir,
                'slave.increment' => $increment,
            ],
            'slave.pid',
            $repo_dir,
            $output
        );
    }

}
