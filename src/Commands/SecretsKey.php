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
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\GitHub;

Class SecretsKey extends Command {

    protected static $defaultName = 'secrets:key';
    protected static $defaultDescription = 'Display the encryption key and options to transfer it';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Display the encryption key stored on this node.

            Shows the key and provides ready-to-use commands:
            - A protocol command to run on the target node
            - An SCP command to copy the key file directly
            - An option to push it as a GitHub secret

            HELP)
        ;
        $this
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output only the raw key (for scripting)')
            ->addOption('push', null, InputOption::VALUE_NONE, 'Push the key to GitHub as a secret')
            ->addOption('scp', null, InputOption::VALUE_OPTIONAL, 'SCP the key to a remote host (user@host)', false)
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
            $output->writeln('<error>No encryption key found on this node.</error>');
            $output->writeln('Run <comment>protocol secrets:setup</comment> to generate one.');
            return Command::FAILURE;
        }

        $hexKey = trim(file_get_contents(Secrets::keyPath()));

        // --raw: just output the key for piping
        if ($input->getOption('raw')) {
            $output->write($hexKey);
            return Command::SUCCESS;
        }

        // --push: push to GitHub secret
        if ($input->getOption('push')) {
            $repo_dir = Dir::realpath($input->getOption('dir'));
            $slug = GitHub::getRepoSlug($repo_dir);
            if (!$slug) {
                $output->writeln('<error>No GitHub remote found.</error>');
                return Command::FAILURE;
            }
            if (!GitHub::isAvailable()) {
                $output->writeln('<error>gh CLI not available or not authenticated.</error>');
                return Command::FAILURE;
            }
            if (GitHub::setSecret('PROTOCOL_ENCRYPTION_KEY', $hexKey, $repo_dir)) {
                $output->writeln("<info>Pushed PROTOCOL_ENCRYPTION_KEY to {$slug}</info>");
            } else {
                $output->writeln('<error>Failed to push secret.</error>');
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        // --scp: copy key file to remote host
        $scpHost = $input->getOption('scp');
        if ($scpHost !== false) {
            if (!$scpHost) {
                $output->writeln('<error>Provide a host: --scp=user@host</error>');
                return Command::FAILURE;
            }
            $remotePath = Secrets::keyPath();
            $output->writeln("Copying key to <comment>{$scpHost}</comment>...");
            Shell::passthru(
                "ssh " . escapeshellarg($scpHost) . " 'mkdir -p " . escapeshellarg(dirname($remotePath)) . "' && " .
                "scp " . escapeshellarg(Secrets::keyPath()) . " " . escapeshellarg($scpHost . ':' . $remotePath)
            );
            $output->writeln('<info>Done.</info>');
            return Command::SUCCESS;
        }

        // Default: display key and transfer options
        $output->writeln('');
        $output->writeln("<fg=white;options=bold>Encryption Key</>");
        $output->writeln("<fg=white>{$hexKey}</>");
        $output->writeln('');
        $output->writeln('<fg=gray>── Transfer options ──────────────────────────────────────</>');
        $output->writeln('');
        $output->writeln('  <fg=yellow>1.</> Run on target node:');
        $output->writeln("     <fg=cyan>protocol secrets:setup \"{$hexKey}\"</>");
        $output->writeln('');
        $output->writeln('  <fg=yellow>2.</> SCP to remote host:');
        $output->writeln("     <fg=cyan>protocol secrets:key --scp=user@host</>");
        $output->writeln('');
        $output->writeln('  <fg=yellow>3.</> Push as GitHub secret:');
        $output->writeln("     <fg=cyan>protocol secrets:key --push</>");
        $output->writeln('');
        $output->writeln('  <fg=yellow>4.</> Raw key (for scripting):');
        $output->writeln("     <fg=cyan>protocol secrets:key --raw</>");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
