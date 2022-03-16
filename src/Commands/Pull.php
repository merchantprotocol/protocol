<?php

namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

Class Pull extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'git:pull';
    protected static $defaultDescription = 'Pull from github and update the local repo';

    protected function configure(): void
    {
        // ...
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
        $io = new SymfonyStyle($input, $output);

        // First, run a fetch to update all origin/<branch> refs to latest:
        exec('git fetch --all', $output);
        // Backup your current branch:
        exec('git branch backup-master', $output);
        // resets the master branch to what you just fetched. 
        // The --hard option changes all the files in your working tree to match the files in origin/master
        exec('git reset --hard origin/master', $output);

        $io->success(implode(PHP_EOL, $output));

        return Command::SUCCESS;
    }

}