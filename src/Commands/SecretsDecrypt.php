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
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;

Class SecretsDecrypt extends Command {

    protected static $defaultName = 'secrets:decrypt';
    protected static $defaultDescription = 'Decrypt and display .env.enc contents (for debugging)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Decrypts and displays the contents of an encrypted .env.enc file.
            For debugging and verification only — does not write to disk.

            HELP)
        ;
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to .env.enc file to decrypt')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!Secrets::hasKey()) {
            $output->writeln('<error>No encryption key found. Run: protocol secrets:setup</error>');
            return Command::FAILURE;
        }

        $repo_dir = Dir::realpath($input->getOption('dir'));
        $encFile = $input->getArgument('file');

        if (!$encFile) {
            $configRepo = Config::repo($repo_dir);
            if ($configRepo && is_dir($configRepo)) {
                $encFile = $configRepo . '.env.enc';
            } else {
                $output->writeln('<error>No config repo found. Specify a file path.</error>');
                return Command::FAILURE;
            }
        }

        if (!is_file($encFile)) {
            $output->writeln("<error>File not found: {$encFile}</error>");
            return Command::FAILURE;
        }

        $plaintext = Secrets::decryptFile($encFile);
        if ($plaintext === null) {
            $output->writeln('<error>Decryption failed. Wrong key or corrupted file.</error>');
            return Command::FAILURE;
        }

        $output->writeln("<comment>Decrypted contents of: {$encFile}</comment>");
        $output->writeln('');
        $output->write($plaintext);

        return Command::SUCCESS;
    }
}
