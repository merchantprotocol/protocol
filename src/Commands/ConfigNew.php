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

Class ConfigNew extends Command {

    protected static $defaultName = 'config:new';
    protected static $defaultDescription = 'Create a new environment';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command will help you create a new configuration environment. Run the command and it will interactively walk you through your options to create a new environment for you.

            What is the environment name?
            Do you want to copy an existing environment or create a new one?

            HELP)
        ;
        $this
            // configure an argument
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
        $helper = $this->getHelper('question');

        // make sure we're in the application repo
        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        // make sure we have a config repo to start with
        $configrepo = Config::repo($repo_dir);
        if (!$configrepo) {
            $output->writeln("<error>Please run `protocol config:init` before using this command.</error>");
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

        // get the correct environment
        $question = new Question('New Environment Name: ');
        $newName = $helper->ask($input, $output, $question);

        $slug = Str::slugify( $newName );
        if ($newName != $slug) {
            $question = new ConfirmationQuestion("We cleaned the name you gave us, is this okay? ($slug) [Y/n]");
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }
        }
        $newName = $slug;

        $activeEnv = JsonLock::read('configuration.active', false, $repo_dir);
        if ($activeEnv) {
            $output->writeln("<info>The $activeEnv environment is currently active. We need to disable this env to proceed.</info>");
            $question = new ConfirmationQuestion("Can we unlink the currently active env? [Y/n]");
            if (!$helper->ask($input, $output, $question)) {
                return Command::SUCCESS;
            }

            $command = $this->getApplication()->find('config:unlink');
            $returnCode = $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);
        }

        // do they want to copy another env?
        $question = new ConfirmationQuestion("To copy an existing environment enter (y), or to start fresh enter (n): ");

        $command = $this->getApplication()->find('config:unlink');
        $returnCode = $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);

        // copy another branch
        if ($helper->ask($input, $output, $question)) {
            // pull the latest branches
            Git::fetch( $configrepo );
            $branches = Git::branches( $configrepo );
            $branchStr = implode(', ',$branches);

            $output->writeln("<info>You have the following environments: $branchStr</info>");
            $question = new Question('Type the name of the environment you\'d like to copy: ');
            $copyEnv = $helper->ask($input, $output, $question);
            if (!in_array($copyEnv, $branches)) {
                $question = new Question('We couldn\'t understand that. Type the name of the environment you\'d like to copy: ');
                $copyEnv = $helper->ask($input, $output, $question);
            }

            // Copy a branch
            Git::switchBranch( $copyEnv, $configrepo );
            Git::createBranch( $newName, $configrepo );

        // create a clean branch
        } else {
            Git::createBranch( $newName, $configrepo );

            $ignored = ['.gitignore', 'README.md', '.git'];
            Git::truncateBranch( $configrepo, $ignored );
        }

        $command = $this->getApplication()->find('config:link');
        $returnCode = $command->run((new ArrayInput([])), $output);
        
        $output->writeln("<info>Your new config environment has been created</info>");
        Shell::passthru("ls -la $configrepo");
        $output->writeln("");

        $question = new ConfirmationQuestion("Do you want to save this new environment to remote? [Y/n]");
        if ($helper->ask($input, $output, $question)) {
            Git::push( $configrepo );
            $output->writeln("");
        }
        
        $output->writeln("<info>Done! Your new environment has been created.</info>");
        return Command::SUCCESS;
    }

}