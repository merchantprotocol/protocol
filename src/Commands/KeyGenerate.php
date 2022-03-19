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

Class KeyGenerate extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'key:generate';
    protected static $defaultDescription = 'Generate an openssl key';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('Generates a key and returns the public pem')
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
        $output->writeln('================== Generating Key ================');

        $email = 'worker@ec2.com';
        $HOME = Shell::run('echo $HOME');
        $keyfile = $HOME.'/.ssh/id_ed25519_ContinuousDeliverySystem';
        $keyfilepub = $keyfile.'.pub';

        // run the command as a passthru to the user
        $command = "ssh-keygen -t ed25519 -C '$email' -q -N \"\" -f $keyfile";
        Shell::passthru($command);

        $sshconfig = $HOME.'/.ssh/config';
        $configData = \file_get_contents($sshconfig);
        if (strpos($configData, 'Host *') === false)
        {
            // update the ssh config
            $data = <<<DATA

            Host *
                AddKeysToAgent yes
                IgnoreUnknown UseKeychain
                UseKeychain yes
                IdentityFile $keyfile

            DATA;

            touch($sshconfig);
            \file_put_contents($sshconfig, $data, FILE_APPEND);
            Shell::run("chmod 600 $sshconfig");
        }

        $output->writeln( PHP_EOL.PHP_EOL.file_get_contents($keyfilepub).PHP_EOL );
        return Command::SUCCESS;
    }

}