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

Class DockerComposeRebuild extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'docker:compose:rebuild';
    protected static $defaultDescription = 'Pulls down a new copy and rebuilds the image';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command was designed to be run frequently in a non-interactive state. '
                    . 'Ideal for keeping a child node up to date.')
        ;
        $this
            // configure an argument
            ->addArgument('localdir', InputArgument::OPTIONAL, 'The local dir to run docker compose in', false)
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
        $localdir = Dir::realpath($input->getArgument('localdir'), Config::read('localdir'));
        $output->writeln('================== Docker Compose ================');

        if (!file_exists("{$localdir}/docker-compose.yml")) {
            $output->writeln(' - Skipping docker compose, there is no docker-compose.yml in the project');
            return Command::FAILURE;
        }

        $image = Config::read('docker.image', false);
        if (!$image) {
            $output->writeln(' - FAILED Exiting... - image param needs to be set in the config.php');
            return Command::FAILURE;
        }

        $username = Config::read('docker.username', false);
        if (!$username) {
            $output->writeln(' - FAILED Exiting... - username param needs to be set in the config.php');
            return Command::FAILURE;
        }

        $password = Config::read('docker.password', false);
        if (!$password) {
            $output->writeln(' - FAILED Exiting... - password param needs to be set in the config.php');
            return Command::FAILURE;
        }

        $command = $this->getApplication()->find('docker:pull');
        $returnCode = $command->run((new ArrayInput([])), $output);

        $command = "cd $localdir && docker-compose up --build -d";
        $response = Shell::passthru($command);

        return Command::SUCCESS;
    }

}