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

Class SecretsEncrypt extends Command {

    protected static $defaultName = 'secrets:encrypt';
    protected static $defaultDescription = 'Encrypt .env file to .env.enc in the config repo';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Encrypts a .env file using AES-256-GCM.

            Defaults to the .env file in the configuration repository.
            Output goes to .env.enc alongside the input file.

            HELP)
        ;
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to .env file to encrypt')
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
        $envFile = $input->getArgument('file');

        if (!$envFile) {
            $configRepo = Config::repo($repo_dir);
            if ($configRepo && is_dir($configRepo)) {
                $envFile = $configRepo . '.env';
            } else {
                $output->writeln('<error>No config repo found. Specify a file path: protocol secrets:encrypt /path/to/.env</error>');
                return Command::FAILURE;
            }
        }

        if (!is_file($envFile)) {
            $output->writeln("<error>File not found: {$envFile}</error>");
            return Command::FAILURE;
        }

        $encFile = preg_replace('/\.env$/', '.env.enc', $envFile);
        if ($encFile === $envFile) {
            $encFile = $envFile . '.enc';
        }

        if (Secrets::encryptFile($envFile, $encFile)) {
            $output->writeln("<info>Encrypted: {$envFile} -> {$encFile}</info>");
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            $output->writeln('  1. Verify: protocol secrets:decrypt ' . escapeshellarg($encFile));
            $output->writeln('  2. Remove plaintext: rm ' . escapeshellarg($envFile));
            $output->writeln('  3. Commit: protocol config:save');
        } else {
            $output->writeln('<error>Encryption failed.</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
