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
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Crontab;
use Gitcd\Utils\Json;

Class ProtocolStart extends Command {

    use LockableTrait;

    protected static $defaultName = 'start';
    protected static $defaultDescription = 'Starts a node so that the repo and docker image stay up to date and are running';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Start up a new node. The repository will be updated and become a slave, updating whenever the remote repo updates. The latest docker image will be pulled down and started up.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            // ...
        ;
    }

    /**
     * When the node is relaunched after sleeping through assumed changes
     * Install this command in the crontab as:
     * @reboot /opt/protocol/pipeline node:update
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Starting up the protocol node</comment>');
        $helper = $this->getHelper('question');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        // get the correct environment
        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment) {
            $question = new Question('What is the current env we need to configure protocol for globally? This must be set:', 'localhost');
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        }

        $devEnvs = ['localhost', 'local', 'dev', 'development'];
        $localdir = Git::getGitLocalFolder();
        $arrInput = (new ArrayInput([]));
        $arrInput1 = (new ArrayInput(['environment' => $environment]));

        // if not a local dev env
        if (!in_array($environment, $devEnvs)) {
            // run update
            $command = $this->getApplication()->find('git:pull');
            $returnCode = $command->run($arrInput, $output);

            // run repo slave
            $command = $this->getApplication()->find('git:slave');
            $returnCode = $command->run($arrInput, $output);
        }

        if (Json::read('configuration.remote', false)) {
            $command = $this->getApplication()->find('config:init');
            $returnCode = $command->run($arrInput1, $output);

            if (!in_array($environment, $devEnvs)) {
                $command = $this->getApplication()->find('config:slave');
                $returnCode = $command->run($arrInput, $output);
            }

            $command = $this->getApplication()->find('config:link');
            $returnCode = $command->run($arrInput, $output);
        }

        // add crontab restart command
        Crontab::addCrontabRestart( $localdir );

        // Update docker image
        $command = $this->getApplication()->find('docker:pull');
        $returnCode = $command->run($arrInput, $output);

        // run docker compose
        $command = $this->getApplication()->find('docker:compose:rebuild');
        $returnCode = $command->run($arrInput, $output);

        // end with status
        $command = $this->getApplication()->find('status');
        $returnCode = $command->run($arrInput, $output);

        return Command::SUCCESS;
    }

}
