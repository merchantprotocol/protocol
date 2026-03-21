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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Str;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ConfigSwitch extends Command {

    protected static $defaultName = 'config:switch';
    protected static $defaultDescription = 'Switch to a different environment';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Switch the current application to a different config environment.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
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
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        $helper = $this->getHelper('question');

        // make sure we're in the application repo
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        // make sure we have a config repo to start with
        $configrepo = Config::requireRepo($repo_dir, $output);
        if (!$configrepo) {
            return Command::SUCCESS;
        }

        // Usaved changes
        if (Git::hasUntrackedFiles( $configrepo )) {
            $question = new ConfirmationQuestion("Do you want to save the unsaved changes in your config repo? [Y/n]");
            if ($helper->ask($input, $output, $question)) {
                Git::commit( 'Saving untracked changes', $configrepo );
                Git::push( $configrepo );
            }
        }

        // Always fetch to ensure we have remote branches
        Git::fetch( $configrepo );

        $currentBranch = Git::branch( $configrepo );
        $branches = Git::branches( $configrepo );

        $newName = $input->getArgument('environment');
        if (!$newName) {
            $branchStr = implode(', ', $branches);
            $output->writeln("<info>You have the following environments: $branchStr</info>");
            $question = new Question("You are on env ($currentBranch), switch to what environment?: ");
            $newName = $helper->ask($input, $output, $question);
        }

        if (!$newName) {
            $output->writeln("<error>No environment specified.</error>");
            return Command::FAILURE;
        }

        // Check if branch exists locally or as a remote
        $escapedRepo = escapeshellarg($configrepo);
        $localExists = trim(Shell::run("git -C {$escapedRepo} branch --list " . escapeshellarg($newName) . " 2>/dev/null") ?: '');
        $remoteExists = trim(Shell::run("git -C {$escapedRepo} branch -r --list " . escapeshellarg("*/{$newName}") . " 2>/dev/null") ?: '');

        if (!$localExists && !$remoteExists) {
            $question = new ConfirmationQuestion(
                "Environment \"{$newName}\" does not exist. Create it? [Y/n] ",
                true
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Available: ' . implode(', ', $branches));
                return Command::FAILURE;
            }

            // Create the new branch from current, then let the rest of the flow handle it
            $command = $this->getApplication()->find('config:new');
            $returnCode = $command->run(new ArrayInput([
                'environment' => $newName,
                '--dir' => $repo_dir,
            ]), $output);
            return $returnCode;
        }

        if ($newName === $currentBranch) {
            $output->writeln("<info>Already on {$newName}.</info>");
            return Command::SUCCESS;
        }

        // Unlink current config
        $command = $this->getApplication()->find('config:unlink');
        $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);

        // Switch branch — use checkout which auto-tracks remote branches
        $result = Shell::run("git -C {$escapedRepo} checkout " . escapeshellarg($newName) . " 2>&1", $returnVar);

        // Verify the switch actually happened
        $actualBranch = trim(Shell::run("git -C {$escapedRepo} rev-parse --abbrev-ref HEAD 2>/dev/null") ?: '');

        if ($returnVar !== 0 || $actualBranch !== $newName) {
            $output->writeln("<error>Failed to switch to {$newName}:</error>");
            $output->writeln("<fg=gray>{$result}</>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Config repo switched to: {$newName}</info>");

        // Re-link (decrypts .env.enc if encryption key is present)
        $command = $this->getApplication()->find('config:link');
        $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);

        return Command::SUCCESS;
    }

}