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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;

Class DockerPull extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'docker:pull';
    protected static $defaultDescription = 'Docker pull and update an image';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command was designed to be run on a cluster node that is NOT the'
            .' source of truth.')
        ;
        $this
            // configure an argument
            ->addArgument('image', InputArgument::OPTIONAL, 'The desired remote docker image tag')
            ->addArgument('username', InputArgument::OPTIONAL, 'Your docker username')
            ->addArgument('password', InputArgument::OPTIONAL, 'Your docker password')
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
        $image    = $input->getArgument('image') ?: Config::read('docker.image');
        $username = $input->getArgument('username') ?: Config::read('docker.username');
        $password = $input->getArgument('password') ?: Config::read('docker.password');

        $output->writeln('================== Pulling Image ================');

        $command = "echo '$password' | docker login --username $username --password-stdin";
        $response = Shell::passthru($command);

        $command = "docker pull $image";
        $response = Shell::passthru($command);

        return Command::SUCCESS;
    }

}