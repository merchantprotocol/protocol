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

Class NodeUpdate extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'node:update';
    protected static $defaultDescription = 'Updates the docker container, the repo and itself';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command was designed to be run on a cluster node that is NOT the'
            .' source of truth. It will not ask questions, but assume all installation requirements.'
            .' Let\'s say you built a node, took a snapshot and then three months of updates later the snapshot '
            .' got provisioned as a node in the cluster. This command will update the docker container and the '
            .' repository, and itself! You should run this command in a crontab @reboot')
        ;
        $this
            // configure an argument
            ->addArgument('localdir', InputArgument::OPTIONAL, 'The local url to clone to', false)
            // ...
        ;
    }

    /**
     * When the node is relaunched after sleeping through assumed changes
     * Install this command in the crontab as:
     * @reboot /opt/github-continuous-deployment/bin/pipeline node:update
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('================== Updating Node ================');

        $localdir = Dir::realpath($input->getArgument('localdir'), Config::read('localdir'));
        $arrInput2 = new ArrayInput([
            'localdir' => $localdir
        ]);

        // pull down the docker container
        $command = $this->getApplication()->find('repo:update');
        $returnCode = $command->run($arrInput2, $output);

        // run docker compose
        $command = $this->getApplication()->find('docker:compose:rebuild');
        $returnCode = $command->run((new ArrayInput([])), $output);

        return Command::SUCCESS;
    }

}