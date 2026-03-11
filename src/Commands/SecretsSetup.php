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
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Secrets;

Class SecretsSetup extends Command {

    protected static $defaultName = 'secrets:setup';
    protected static $defaultDescription = 'Generate or store the encryption key on this node';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Generate a new encryption key or store an existing one.

            Without an argument, generates a new random key and displays it
            so you can copy it to other nodes.

            With an argument, stores the provided key on this node.
            Example: protocol secrets:setup "your-hex-key-here"

            The key is stored at ~/.protocol/key with 0600 permissions.

            HELP)
        ;
        $this
            ->addArgument('key', InputArgument::OPTIONAL, 'Hex-encoded key to store (from another node)')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providedKey = $input->getArgument('key');

        if (Secrets::hasKey() && !$providedKey) {
            $output->writeln('<comment>Encryption key already exists at: ' . Secrets::keyPath() . '</comment>');
            $output->writeln('To replace it, delete the existing key first.');
            return Command::SUCCESS;
        }

        if ($providedKey) {
            // Validate key format
            $binary = hex2bin($providedKey);
            if ($binary === false || strlen($binary) !== Secrets::KEY_LENGTH) {
                $output->writeln('<error>Invalid key. Must be a ' . (Secrets::KEY_LENGTH * 2) . '-character hex string.</error>');
                return Command::FAILURE;
            }

            if (Secrets::storeKey($providedKey)) {
                $output->writeln('<info>Encryption key stored at: ' . Secrets::keyPath() . '</info>');
            } else {
                $output->writeln('<error>Failed to store encryption key.</error>');
                return Command::FAILURE;
            }
        } else {
            // Generate new key
            $hexKey = Secrets::generateKey();
            if (Secrets::storeKey($hexKey)) {
                $output->writeln('<info>New encryption key generated and stored.</info>');
                $output->writeln('');
                $output->writeln('Key: <comment>' . $hexKey . '</comment>');
                $output->writeln('');
                $output->writeln('<comment>Copy this key to other nodes:</comment>');
                $output->writeln("  protocol secrets:setup \"{$hexKey}\"");
                $output->writeln('');
                $output->writeln('Stored at: ' . Secrets::keyPath());
            } else {
                $output->writeln('<error>Failed to generate encryption key.</error>');
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
