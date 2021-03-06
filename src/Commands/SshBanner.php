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

Class SshBanner extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'ssh:banner';
    protected static $defaultDescription = 'Add the Ssh Banner';

    protected function configure(): void
    {
        // ...
        $this
            ->setHidden(true)
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('banner', InputArgument::OPTIONAL, 'Your custom banner file to add', false)
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
        $output->writeln('<comment>Updating the ssh banner</comment>');

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        // Make sure the environment variables are set
        $command = $this->getApplication()->find('env:default');
        $returnCode = $command->run((new ArrayInput([])), $output);

        // Update the sshd_config file to not display the default banner
        $response = Shell::run("sudo cp /etc/ssh/sshd_config /etc/ssh/sshd_config.bak");
        $sshconfig = <<<SETTINGS

        PrintMotd no

        SETTINGS;
        $response = Shell::run("sudo echo '$sshconfig' >> /etc/ssh/sshd_config");

        // write the etc/profile to include the new banner

        $banner_file = Dir::realpath($input->getArgument('banner'), false) ?: Config::read('banner_file');
        if (strpos($banner_file, '/') !== 0) {
            $banner_file = WEBROOT_DIR.$banner_file;
        }
        $response = Shell::run("sudo cp /etc/profile /etc/profile.bak");
        $etcprofile = <<<SETTINGS

        $banner_file

        SETTINGS;
        // write the new file
        $response = Shell::run("sudo echo '$etcprofile' >> /etc/profile");


        // empty the default file and lock it so the system cannot override it
        $response = Shell::run("sudo chattr -i /etc/motd");
        $response = Shell::run("sudo cat '' > /etc/motd");
        $response = Shell::run("sudo chattr +i /etc/motd");


        return Command::SUCCESS;
    }

}
