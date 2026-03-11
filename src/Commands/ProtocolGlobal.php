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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;

Class ProtocolGlobal extends Command {

    use LockableTrait;

    protected static $defaultName = 'self:global';
    protected static $defaultDescription = 'Set protocol as a global option';

    protected $commandName = "protocol";

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Sets self as a global option for all users.

            If a conflicting command already exists, use --force to remove
            the existing symlink/binary and replace it with this one.

            HELP)
        ;
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force install by removing existing command first')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $force = $input->getOption('force');
        $datameltpath = WEBROOT_DIR.$this->commandName;

        // Is there a conflicting command already in the namespace
        $which = trim(Shell::run("which {$this->commandName} 2>/dev/null") ?: '');
        if ($which) {
            if (!$force) {
                $output->writeln("<error>Conflicting '{$this->commandName}' command already exists at: {$which}</error>");
                $output->writeln("<comment>To replace it, run: protocol self:global --force</comment>");
                return Command::FAILURE;
            }
            $output->writeln("<comment>Removing existing command at: {$which}</comment>");
            Shell::run("rm -f " . escapeshellarg($which));
            if (file_exists($which) || is_link($which)) {
                $output->writeln("<error>Could not remove {$which} — permission denied.</error>");
                $output->writeln("<comment>Run with sudo: sudo " . escapeshellarg($datameltpath) . " self:global --force</comment>");
                return Command::FAILURE;
            }
        }

        // Finding the first available preferred path
        $preferred = ['/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin'];
        $path = Shell::run("echo \$PATH");
        $pathes = explode(':', $path);
        $datameltsymlink = null;

        foreach ($pathes as $bin) {
            if (!in_array($bin, $preferred)) continue;

            $testpath = $bin.DIRECTORY_SEPARATOR.$this->commandName;
            if (file_exists($testpath) || is_link($testpath)) {
                if (!$force) {
                    $output->writeln("<error>Protocol command already exists at: {$testpath}</error>");
                    $output->writeln("<comment>To replace it, run: protocol self:global --force</comment>");
                    return Command::FAILURE;
                }
                $output->writeln("<comment>Removing existing file at: {$testpath}</comment>");
                Shell::run("rm -f " . escapeshellarg($testpath));
                if (file_exists($testpath) || is_link($testpath)) {
                    $output->writeln("<error>Could not remove {$testpath} — permission denied.</error>");
                    $output->writeln("<comment>Run with sudo: sudo " . escapeshellarg($datameltpath) . " self:global --force</comment>");
                    return Command::FAILURE;
                }
            }

            $datameltsymlink = $testpath;
            break;
        }

        if (!$datameltsymlink) {
            $output->writeln('<error>No suitable bin directory found in PATH</error>');
            return Command::FAILURE;
        }

        $command = "ln -s " . escapeshellarg($datameltpath) . " " . escapeshellarg($datameltsymlink);
        $installed = Shell::run($command, $notinstalled);
        if ($notinstalled) {
            $output->writeln("<error>$installed</error>");
            $output->writeln("<comment>You may need to run with sudo: sudo protocol self:global" . ($force ? " --force" : "") . "</comment>");
            return Command::FAILURE;
        }

        $which = Shell::run("which {$this->commandName} 2>/dev/null");
        if ($which) {
            $output->writeln("<info>Protocol installed globally at: {$datameltsymlink}</info>");
            return Command::SUCCESS;
        }

        $output->writeln('<error>Installation could not be verified</error>');
        return Command::FAILURE;
    }

}
