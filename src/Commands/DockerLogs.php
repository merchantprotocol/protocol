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
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Utils\Json;

Class DockerLogs extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'docker:logs';
    protected static $defaultDescription = 'Show the docker container logs';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Tails the docker container logs

            HELP)
        ;
        $this
            // configure an argument
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
        $name = Json::read('docker.container_name', false);
        if (!$name) {
            $names = Docker::getContainerNamesFromDockerComposeFile();
            if (count($names)==1) {
                $name = array_pop($names);
                Json::write('docker.container_name', $name);
                Json::save();
            }
        }

        $command = "docker logs --follow $name";
        $response = Shell::passthru($command);

        return Command::SUCCESS;
    }

}