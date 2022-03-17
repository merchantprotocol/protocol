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
 * @package    merchantprotocol/github-continuous-delivery
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;

Class RepoSlave extends Command {

    protected static $defaultName = 'repo:slave';
    protected static $defaultDescription = 'Continuously deployment keeps the local repo updated with the remote changes';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command was designed to be run on a cluster node that is NOT the'
            .' source of truth. Using this command will overwrite any local files or commits that'
            .' are not in the remote source of truth.')
        ;
        $this
            // configure an argument
            ->addArgument('localdir', InputArgument::REQUIRED, 'The local git directory to manage')
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'How many seconds to sleep between remote checks')
            ->addOption('no-daemon', 'no-d', InputOption::VALUE_OPTIONAL, 'Do not run as a background service', false)
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
        // the .git directory
        $repo_dir = rtrim(realpath($input->getArgument('localdir')), '/') ?: Config::read('localdir');

        // get the branch
        $branch = Shell::run("git -C $repo_dir branch | sed -n -e 's/^\* \(.*\)/\\1/p'");

        // get the remote name
        $remotes = Shell::run("git -C $repo_dir remote");
        $remotearray = explode(PHP_EOL, $remotes);
        $remote = array_shift($remotearray);

        // remote url
        $remoteurl = Shell::run("git -C $repo_dir config --get remote.origin.url");
        if (!$remoteurl) {
            $output->writeln("Your local repo is not connected to a remote source of truth. cancelling...");
            return Command::FAILURE;
        }

        $increment = $input->getOption('increment');
        if (!$increment) {
            $increment = 10;
        }
        $nodaemon = $input->getOption('no-daemon');
        if (is_null($nodaemon)) {
            $nodaemon = true;
        }
        $daemon = !$nodaemon;

        $output->writeln(PHP_EOL.'================== Watching ================');
        if ($daemon) {
            $output->writeln(" - This command will run in the background every $increment seconds until you kill it.");
        } else {
            $output->writeln(" - This command will run in the foreground every $increment seconds until you kill it.");
        }
        $output->writeln(" - If any changes are made to $remoteurl we'll update $repo_dir".PHP_EOL);

        global $script_dir;

        // execute command
        $command = "{$script_dir}git-repo-watcher -d $repo_dir -o $remote -b $branch -h {$script_dir}git-repo-watcher-hooks -i $increment";
        if ($daemon) {
            // Run the command in the background as a daemon
            Shell::background($command);
            return Command::SUCCESS;
        }

        // run the command as a passthru to the user
        Shell::passthru($command);
        return Command::SUCCESS;
    }

}