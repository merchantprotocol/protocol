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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;

Class ConfigCopy extends Command {

    protected static $defaultName = 'config:cp';
    protected static $defaultDescription = 'Copy a file into the configurations repo.';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Sending a relative file path to this command will copy the file from the application repo into the configurations repo. Additionally the file will be added to the application repos .gitignore file and the config repo will be pushed to it's remote.

            HELP)
        ;
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'The path to the file you want to move')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path', false);

        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        $configrepo = Config::requireRepo($repo_dir, $output);
        if (!$configrepo) {
            return Command::SUCCESS;
        }

        $currentpath = WORKING_DIR . $path;
        if (!file_exists($currentpath)) {
            $output->writeln("<error>File does not exist $currentpath</error>");
            return Command::SUCCESS;
        }

        // Validate before copy (hook for subclass)
        if (!$this->validateSource($currentpath, $output)) {
            return Command::SUCCESS;
        }

        $newpath = str_replace($repo_dir, $configrepo, $currentpath);
        $destination_dir = dirname($newpath);
        $environment = Config::read('env', false);

        Git::switchBranch($environment, $configrepo);
        if (!is_dir($destination_dir)) {
            Shell::passthru("mkdir -p " . escapeshellarg($destination_dir));
        }

        Shell::passthru("cp -R " . escapeshellarg($currentpath) . " " . escapeshellarg($newpath));
        if (!file_exists($newpath)) {
            $output->writeln("<error>Unable to determine if file was copied. Cancelling ($newpath)</error>");
            return Command::SUCCESS;
        }

        Git::addIgnore($path, $repo_dir);

        // Post-copy hook (move behavior added by subclass)
        $this->afterCopy($currentpath, $newpath, $repo_dir, $output);

        $output->writeln("<info>File has been transferred to $newpath.</info>");
        return Command::SUCCESS;
    }

    /**
     * Validate the source file before copying. Override in subclass.
     */
    protected function validateSource(string $currentpath, OutputInterface $output): bool
    {
        return true;
    }

    /**
     * Post-copy hook. Override in subclass to add move/delete behavior.
     */
    protected function afterCopy(string $currentpath, string $newpath, string $repo_dir, OutputInterface $output): void
    {
        // Copy-only: nothing to do after
    }

}
