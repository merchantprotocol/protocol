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
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ConfigMove extends Command {

    protected static $defaultName = 'config:mv';
    protected static $defaultDescription = 'Move a file into the config repo, delete it from the app repo and create a symlink back.';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Sending a relative file path to this command will move the file from the application repo into the configurations repo. Additionally the file will be added to the application repos .gitignore file and the config repo will be pushed to it's remote.

            A symlink will be added back into the application repo.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file you want to move')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path', false);
        $output->writeln("<info>Moving file ($path)</info>");

        // make sure we're in the application repo
        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        $configrepo = Config::repo($repo_dir);
        if (!$configrepo) {
            $output->writeln("<error>Please run `protocol config:init` before using this command.</error>");
            return Command::SUCCESS;
        }

        $environment = Config::read('env', false);

        $currentpath = WORKING_DIR.$path;
        if (is_link( $currentpath )) {
            $output->writeln("<error>Cannot move a symlink.</error>");
            return Command::SUCCESS;
        }

        $newpath = str_replace($repo_dir, $configrepo, $currentpath);
        $destination_dir = dirname($newpath);

        if (!file_exists($currentpath)) {
            $output->writeln("<error>File does not exist $currentpath</error>");
            return Command::SUCCESS;
        }

        Git::switchBranch( $environment, $configrepo );
        if (!is_dir($destination_dir)) {
            Shell::passthru("mkdir -p '$destination_dir'");
        }

        Shell::passthru("cp -R '$currentpath' '$newpath'");
        if (!file_exists($newpath)) {
            $output->writeln("<error>Unable to determine if file was copied. Cancelling ($newpath)</error>");
            return Command::SUCCESS;
        }

        // add file to gitignore
        Git::addIgnore( $path, $repo_dir );

        // make sure we're in a location within the repo
        if (strpos($currentpath, WORKING_DIR) !== false && is_file($currentpath)) {
            Shell::passthru("rm -f '$currentpath'");
            Shell::run("git -C '$repo_dir' rm --cached '$currentpath'");

            // refresh symlinks
            $command = $this->getApplication()->find('config:refresh');
            $returnCode = $command->run((new ArrayInput([])), $output);
        }

        $output->writeln("<info>File has been moved to $newpath.</info>");
        return Command::SUCCESS;
    }

}