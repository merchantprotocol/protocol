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
use Gitcd\Helpers\Config;

Class GitClone extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'git:clone';
    protected static $defaultDescription = 'Clone from remote repo';

    protected function configure(): void
    {
        // ...
        $this
            ->setHidden(true)
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command was intended to clone down an application repository from a remote source
            to a local directory.

            After cloning the repo it will run composer:install from this project and init your repo
            submodules recursively.

            Finally this command will tell your repo to ignore file permissions, and to not ask for edits when
            running git commands.

            After you run this command you can expect to have your local repo setup and ready to be used.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('remote', InputArgument::OPTIONAL, 'The remote git url to clone from')
            ->addArgument('repo_dir', InputArgument::OPTIONAL, 'The local url to clone to', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path')
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
        $repo_dir = Dir::realpath($input->getArgument('repo_dir'), $input->getOption('dir'));

        $output->writeln('<comment>Cloning a git repo</comment>');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $remoteurl = $input->getArgument('remote') ?: Config::read('remote', $repo_dir);

        if (is_null($repo_dir)) {
            $name = explode('/', $remoteurl);
            $name = array_pop($name);
            $repo_dir = Dir::realpath(str_replace('.git','',$name));
        }

        $command = "git clone '$remoteurl' '$repo_dir'";
        $response = Shell::passthru($command);

        // run composer install
        $command = $this->getApplication()->find('composer:install');
        $returnCode = $command->run((new ArrayInput([
            '--dir' => $repo_dir,
            'repo_dir' => $repo_dir
        ])), $output);

        // update the submodules
        $command = "git -C '$repo_dir' submodule update --init --recursive";
        $response = Shell::passthru($command);

        $response = Shell::run("git -C '$repo_dir' config core.mergeoptions --no-edit");
        $response = Shell::run("git -C '$repo_dir' config core.fileMode false");

        return Command::SUCCESS;
    }

}