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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

Class ProtocolGlobal extends Command {

    use LockableTrait;

    /**
     * command slug
     *
     * @var string
     */
    protected static $defaultName = 'self:global';

    /**
     * Short description
     *
     * @var string
     */
    protected static $defaultDescription = 'Set protocol as a global option';

    /**
     * The name of this command
     *
     * @var string
     */
    protected $commandName = "protocol";

    /**
     * configure
     *
     * @return void
     */
    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Sets self as a global option for all users.

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
        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $datameltpath = WEBROOT_DIR.$this->commandName;

        // Is there a conflicing datamelt command already in the namespace
        $which = Shell::run("which {$this->commandName}", $notfound);
        if (!$notfound || strpos($notfound, 'no '.$this->commandName.' in')!==false) {
            $output->writeln('<error>Conflicting '.$this->commandName.' command already exists globally</error>');
            return Command::SUCCESS;
        }

        // Finding the first available preferred path
        $preferred = ['/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin'];
        $path = Shell::run("echo \$PATH");
        $pathes = explode(':', $path);

        foreach ($pathes as $bin) {
            if (!in_array($bin, $preferred)) continue;

            $testpath = $bin.DIRECTORY_SEPARATOR.$this->commandName;
            // Does the file already exist
            if (file_exists($testpath) || is_link($testpath)) {
                $output->writeln('<error>Protocol command already exists globally</error>');
                return Command::SUCCESS;
            }

            $datameltsymlink = $testpath;
            break;
        }

        // can create symlink
        $bin = dirname($datameltsymlink);
        
        $command = "ln -s $datameltpath $datameltsymlink";
        $installed = Shell::run($command, $notinstalled);
        if ($notinstalled) {
            $output->writeln("<error>$installed</error>");
            return Command::SUCCESS;
        }

        // // Is there a conflicing datamelt command already in the namespace
        $which = Shell::run("which {$this->commandName}", $notfound);
        if (!$notfound) {
            $output->writeln('<info>Protocol was installed globally</info>');
            return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

}
