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
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Utils\JsonLock;

abstract class BaseSlaveCommand extends Command {

    use LockableTrait {
        lock as protected;
    }

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command interfaces with a bash script that was created to constantly monitor the git repo
            without leaking memory. When this command is run it will constantly monitor the repository. Any
            updates to the remote repository will be reflected on this node within 10 seconds.

            Our script will make sure that your repository has not diverged from it's source before running
            the update command.

            Additionally, it will keep your repo synced with the same remote branch as you've specified locally.

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'How many seconds to sleep between remote checks')
            ->addOption('no-daemon', 'no-d', InputOption::VALUE_OPTIONAL, 'Do not run as a background service', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
     * Parse the shared increment and daemon options from input.
     *
     * @param InputInterface $input
     * @return array{int, bool} [$increment, $daemon]
     */
    protected function parseSlaveOptions(InputInterface $input): array
    {
        $increment = $input->getOption('increment');
        if (!$increment) {
            $increment = 10;
        }
        $nodaemon = $input->getOption('no-daemon');
        if (is_null($nodaemon)) {
            $nodaemon = true;
        }
        $daemon = !$nodaemon;

        return [$increment, $daemon];
    }

    /**
     * Run the watcher command either as a daemon or in the foreground.
     *
     * @param string          $command    The shell command to execute
     * @param bool            $daemon     Whether to run as a background daemon
     * @param int             $increment  Sleep interval in seconds
     * @param array           $lockData   Key-value pairs to write to JsonLock before launching daemon
     * @param string          $pidKey     The JsonLock key to store the daemon PID
     * @param string          $repo_dir   The repo directory for JsonLock operations
     * @param OutputInterface $output
     * @return int Command exit code
     */
    protected function runSlaveCommand(
        string $command,
        bool $daemon,
        int $increment,
        array $lockData,
        string $pidKey,
        string $repo_dir,
        OutputInterface $output
    ): int {
        if ($daemon) {
            // Write all lock data
            foreach ($lockData as $key => $value) {
                JsonLock::write($key, $value, $repo_dir);
            }

            $pid = Shell::background($command);
            JsonLock::write($pidKey, $pid, $repo_dir);
            sleep(1);
            JsonLock::save($repo_dir);

            return Command::SUCCESS;
        }

        // run the command as a passthru to the user
        Shell::passthru($command);
        return Command::SUCCESS;
    }
}
