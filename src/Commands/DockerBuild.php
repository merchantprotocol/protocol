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

Class DockerBuild extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'docker:build';
    protected static $defaultDescription = 'Builds the docker image from source';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command builds the Dockerfile into an image and tags it. Provide the `location` and the `image` name. 
            If you're running a different operating system than your worker nodes in production, then you cannot rely 
            on building your docker image from source on your local computer.

            You must build your docker image from source on a worker node and then upload that built image to your
            repository. That's why this command exists, to make building the image quick and easy.

            Image Naming
            ------------
            It's best to use the `image` name as it exists on the remote repository. Which typically requires
            that you name it as your remote repository. This helps when building and pulling the image. Additionally,
            having this `image` name in your repository's docker-compose.yml file will keep an updated copy of the
            image running on this node.

            Location
            --------
            IF, a big IF here, you've got your source Docker repo locally setup then you can specify the source location
            here and it will be used for building. You can also specify any of these arguments in the config.php file.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('location', InputArgument::OPTIONAL, 'The desired remote docker image tag', false)
            ->addArgument('image', InputArgument::OPTIONAL, 'The tag for the image', false)
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
        $io = new SymfonyStyle($input, $output);
        $io->title('Building Docker Image');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $location = $input->getArgument('location') ?: Config::read('docker.location');
        $image    = $input->getArgument('image') ?: Config::read('docker.image');

        $command = $this->getApplication()->find('env:default');
        $returnCode = $command->run((new ArrayInput([])), $output);

        $locationCmd = " -f {$location}Dockerfile $location";
        $command = "docker build -t $image $locationCmd";
        $response = Shell::passthru($command);

        return Command::SUCCESS;
    }

}