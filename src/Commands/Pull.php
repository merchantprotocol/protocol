<?php

namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;

Class Pull extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'git:pull';
    protected static $defaultDescription = 'Pull from github and update the local repo';

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

        $output->writeln('================== Executing Git Pull --force ================');

        $response = Shell::run("git config --global core.mergeoptions --no-edit");
        $response = Shell::run("git config --global core.fileMode false");
        $response = Shell::run("git config core.fileMode false");

        // First, run a fetch to update all origin/<branch> refs to latest:
        $response = Shell::run("git -C $repo_dir fetch --all", $return_var);
        if ($response) $output->writeln($response);

        // if the fetch failed, then stop
        if ($return_var) {
            $output->writeln('Pull failed, canceling operation...');
            return Command::FAILURE;
        }

        // resets the master branch to what you just fetched. 
        // The --hard option changes all the files in your working tree to match the files in origin/master
        $response = Shell::run("git -C $repo_dir reset --hard $remote/$branch");
        if ($response) $output->writeln($response);
        $response = Shell::run("git -C $repo_dir reset --hard HEAD");
        if ($response) $output->writeln($response);

        return Command::SUCCESS;
    }

}