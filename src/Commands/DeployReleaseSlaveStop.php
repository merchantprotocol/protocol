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
use Gitcd\Helpers\DeploymentState;

Class DeployReleaseSlaveStop extends Command {

    protected static $defaultName = 'deploy:slave:stop';
    protected static $defaultDescription = 'Stop the release watcher daemon';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Stops the release watcher daemon.

            HELP)
        ;
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
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

        $pid = DeploymentState::watcherPid($repo_dir);

        if ($pid && Shell::isRunning($pid)) {
            Shell::run("kill {$pid} 2>/dev/null");
            $output->writeln("<info>Stopped release watcher (PID: {$pid})</info>");
        } else {
            $output->writeln('<comment>Release watcher is not running</comment>');
        }

        // Clean up dangling processes scoped to this project
        $processes = Shell::hasProcess("release-watcher.php --dir=" . escapeshellarg($repo_dir));
        if (empty($processes)) {
            $processes = Shell::hasProcess("release-watcher.php --dir=$repo_dir");
        }
        if (!empty($processes)) {
            foreach ($processes as $proc) {
                $danglingPid = intval($proc['PID'] ?? 0);
                if ($danglingPid > 0) {
                    Shell::run("kill {$danglingPid} 2>/dev/null");
                    $output->writeln("<comment>Killed dangling watcher (PID: {$danglingPid})</comment>");
                }
            }
        }

        DeploymentState::setWatcherPid($repo_dir, null);

        return Command::SUCCESS;
    }
}
