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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class GitSlaveStop extends Command {

    use LockableTrait;

    protected static $defaultName = 'git:slave:stop';
    protected static $defaultDescription = 'Stops the slave mode when its running';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

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

        // Check to see if the PID is still running, fail if it is
        $pids = [];
        $pid = JsonLock::read('slave.pid', null, $repo_dir);
        if ($pid) {
            $pids = [$pid];
        }

        $processes = Shell::hasProcess("git-repo-watcher -d '$repo_dir'");
        $processes2 = Shell::hasProcess("git-repo-watcher -d $repo_dir");
        $processes = $processes+ $processes2;
        if (!empty($processes)) {
            $pids = $pids + array_column($processes, "PID");
        }

        if (empty($pids)) {
            $output->writeln("Slave mode is not running");
            return Command::SUCCESS;
        }

        $count = 0;
        foreach ($pids as $pid)
        {
            $running = Shell::isRunning( $pid );
            if (!$running) continue;

            $command = "kill $pid";
            Shell::passthru($command);
            $count++;
        }
        JsonLock::write('slave.pid', null, $repo_dir);
        JsonLock::save($repo_dir);

        $output->writeln("$count slave commands stopped out of ". count($pids));

        return Command::SUCCESS;
    }

}