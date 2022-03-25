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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;

Class NodeUpdate extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'update';
    protected static $defaultDescription = 'Updating a node that has been shut down for some time.';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command was designed to be run on a cluster node that is NOT the source of truth.
            It will not ask questions, but assume all installation requirements.
            
            Let's say you built a node, took a snapshot and then three months of updates later the snapshot
            got provisioned as a node in the cluster. This would leave your snapshot instance out of date
            and cause issues with your application.

            This command will update the docker container and the repository, and itself! 

            When building your docker container for the first time you should run this command on reboot/boot.
            To do that with cron, run the following command to edit your crontab file:

            `crontab -e`

            Then include the following line at the bottom of your file. Be sure to leave a blank line at the bottom
            of your crontab file.

            @reboot /path/to/protocol node:update

            HELP)
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
     * @reboot /opt/protocol/protocol node:update
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Bringing Node Up To Date');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $localdir = Dir::realpath($input->getArgument('localdir'), Config::read('localdir'));
        $arrInput2 = new ArrayInput([
            'localdir' => $localdir
        ]);

        // pull down the docker container
        $command = $this->getApplication()->find('repo:update');
        $returnCode = $command->run($arrInput2, $output);

        $command = $this->getApplication()->find('docker:pull');
        $returnCode = $command->run((new ArrayInput([])), $output);

        // run docker compose
        $command = $this->getApplication()->find('docker:compose:rebuild');
        $returnCode = $command->run((new ArrayInput([])), $output);

        return Command::SUCCESS;
    }

}