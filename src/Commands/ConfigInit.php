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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;

Class ConfigInit extends Command {

    protected static $defaultName = 'config:init';
    protected static $defaultDescription = 'Initialize the configuration repository';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Initializes the configuration repository for the current application repo.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
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
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        $output->writeln('<comment>Initializing configuration repo</comment>');
        $helper = $this->getHelper('question');

        if (!file_exists("{$repo_dir}/protocol.json")) {
            $output->writeln(' - first initialize this repository to work with protocol by running `protocol init`');
            return Command::SUCCESS;
        }

        // get the correct environment
        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment) {
            $question = new Question('What is the current env we need to configure protocol for globally? This must be set:', 'localhost');
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        }

        // create the folder if it doesn't exist
        $configrepo = Config::repo($repo_dir);
        $basedir = dirname($repo_dir).DIRECTORY_SEPARATOR;
        $foldername = basename($repo_dir).'-config';

        if (!is_dir($configrepo)) {
            Shell::run("mkdir -p '$configrepo'");
        }

        // get the remote url
        $preExistingRemoteUrl = Json::read('configuration.remote', false, $repo_dir);

        // clone down the repo if it has a remote
        if ($preExistingRemoteUrl) {
            if (!is_dir($basedir.$foldername.DIRECTORY_SEPARATOR.'.git')) {
                $arrInput = (new ArrayInput([
                    'remote' => $preExistingRemoteUrl,
                    'repo_dir' => $basedir.$foldername,
                    '--dir' => $repo_dir
                ]));

                // run update
                $command = $this->getApplication()->find('git:clone');
                $returnCode = $command->run($arrInput, $output);
            }

            Git::fetch( $configrepo );
        }

        // init repo
        if (!is_dir($configrepo.'.git')) {
            if (!Git::initialize( $configrepo )) {
                $output->writeln("<error>Unable to create a git repo in ($configrepo)</error>");
            }
            Shell::run("git -C '$configrepo' branch -m $environment");

            $output->writeln("<info>The config repo is setup at $configrepo</info>");
            Json::write('configuration.local', '..'.DIRECTORY_SEPARATOR.$foldername, $repo_dir);
        }

        // If there is no remote url, then get it and set it
        $configRemoteUrl = $preExistingRemoteUrl ?: Git::RemoteUrl( $configrepo );
        if (!$configRemoteUrl) {
            $question = new Question('What is the remote git url for your config repo?', false);
            $configRemoteUrl = $helper->ask($input, $output, $question);

            Shell::passthru("git -C '$configrepo' remote add origin '$configRemoteUrl'");
            Json::write('configuration.remote', $configRemoteUrl, $repo_dir);
        }

        // create new branch
        if ($environment !== Git::branch( $configrepo )) {
            Shell::run("git -C '$configrepo' checkout -b $environment");

            $output->writeln("<info>Your new environment branch was created at $configrepo</info>");
        }

        // copy the templated files 
        if (!$preExistingRemoteUrl) {
            $templatedir = TEMPLATES_DIR.'configrepo'.DIRECTORY_SEPARATOR;
            if (!file_exists($configrepo.'README.md')) {
                Shell::run("cp -R '$templatedir' '$configrepo'");
                Shell::run("git -C '$configrepo' add -A");
                Shell::run("git -C '$configrepo' commit -m 'initial commit'");
            }

            // connect to the current repo
            Json::write('configuration.environments', Git::branches( $configrepo ), $repo_dir);
        }

        // is this config repo connected?
        if (!$preExistingRemoteUrl) {
            $question = new ConfirmationQuestion('Do you want to push your config repo? [y/n]', false);
            if ($helper->ask($input, $output, $question)) {
                Shell::passthru("git -C '$configrepo' push --all origin");
                $output->writeln(""); // empty line after push
            }
        }

        if (!$preExistingRemoteUrl) {
            $output->writeln("<info>Done. Your protocol.json file was updated</info>");
        } else {
            $output->writeln("<info>Done.</info>");
        }
        Json::save($repo_dir);

        return Command::SUCCESS;
    }

}