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

Class Watch extends Command {

    protected static $defaultName = 'git:watch';
    protected static $defaultDescription = 'Watches a repository for changes and updates the repo when changes are made to the remote';

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
            ->addArgument('git-dir', InputArgument::REQUIRED, 'The local git directory to manage')
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'How many seconds to sleep between remote checks')
            ->addOption('daemon', 'd', InputOption::VALUE_OPTIONAL, 'Run as a background service', false)
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
        // the .git directory
        $git_dir = rtrim(realpath($input->getArgument('git-dir')), '/').'/';
        if (!strpos($git_dir, '/.git')) {
            $git_dir = rtrim($git_dir, '/').'/.git/';
        }
        // the actual code repo
        $repo_dir = rtrim($git_dir, '.git/');
        $branch = Shell::run("git branch | sed -n -e 's/^\* \(.*\)/\\1/p'");
        // get the remote name
        $remotes = Shell::run("git remote");
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
            $increment = 60;
        }
        $daemon = $input->getOption('daemon');
        if (is_null($daemon)) {
            $daemon = true;
        }

        $output->writeln(PHP_EOL.'================== Watching ================');
        $output->writeln(" - This command will run in the background every $increment seconds until you kill it.");
        $output->writeln(" - If any changes are made to $remoteurl we'll update $repo_dir".PHP_EOL);

        global $script_dir;


        // execute command
        $command = "{$script_dir}git-repo-watcher -d $repo_dir -h {$script_dir}git-repo-watcher-hooks -i $increment";
        if ($daemon) {
            Shell::background($command);
            return Command::SUCCESS;
        }

        $descriptorSpec = array(
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        );
        $pipes = array();
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }

        return Command::SUCCESS;
    }

}