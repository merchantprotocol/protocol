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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;

Class RepoInstall extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'repo:install';
    protected static $defaultDescription = 'Handles the entire installation of a repository';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command was designed to be run on a cluster node that is NOT the'
            .' source of truth. It will not ask questions, but assume all installation requirements.')
        ;
        $this
            // configure an argument
            ->addArgument('remote', InputArgument::OPTIONAL, 'The remote git url to clone from')
            ->addArgument('localdir', InputArgument::OPTIONAL, 'The local url to clone to', false)
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
        $remoteurl = $input->getArgument('remote') ?: Config::read('remote');
        $localdir = Dir::realpath($input->getArgument('localdir'), '/opt/public_html');
var_dump(Config::read('remote'));die;
        $output->writeln('================== Installing Repository ================');

        $arguments = [
            'remote'   => $remoteurl,
            'localdir' => $localdir
        ];
        $arrInput = new ArrayInput($arguments);
        $arguments = [
            'localdir' => $localdir
        ];
        $arrInput2 = new ArrayInput($arguments);

        // pull down the repo
        $command = $this->getApplication()->find('git:clone');
        $returnCode = $command->run($arrInput, $output);

        // run composer install
        $command = $this->getApplication()->find('composer:install');
        $returnCode = $command->run($arrInput2, $output);

        // run docker compose
        $command = $this->getApplication()->find('docker:compose');
        $returnCode = $command->run($arrInput2, $output);

        // run repo slave
        $command = $this->getApplication()->find('repo:slave');
        $returnCode = $command->run($arrInput2, $output);

        return Command::SUCCESS;
    }

}