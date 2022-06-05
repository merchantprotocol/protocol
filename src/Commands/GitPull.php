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
use Gitcd\Utils\Json;

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
        Git::checkInitializedRepo( $output, $repo_dir );

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $output->writeln('<comment>Pulling a git repo</comment>');

        // the .git directory
        $branch = Git::branch( $repo_dir );
        $remote = Git::remoteName( $repo_dir );

        // First, run a fetch to update all origin/<branch> refs to latest:
        $response = Shell::run("git -C '$repo_dir' fetch --all", $return_var);
        if ($response) $output->writeln($response);

        // if the fetch failed, then stop
        if ($return_var) {
            $output->writeln('Pull failed, canceling operation...');
            return Command::FAILURE;
        }

        // resets the master branch to what you just fetched. 
        // The --hard option changes all the files in your working tree to match the files in origin/master
        $response = Shell::run("git -C '$repo_dir' reset --hard $remote/$branch");
        if ($response) $output->writeln($response);
        $response = Shell::run("git -C '$repo_dir' reset --hard HEAD");
        if ($response) $output->writeln($response);


        // run composer install
        $command = $this->getApplication()->find('composer:install');
        $returnCode = $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);

        // Update the submodules
        $command = "git -C '$repo_dir' submodule update --init --recursive";
        $response = Shell::passthru($command);

        return Command::SUCCESS;
    }

}