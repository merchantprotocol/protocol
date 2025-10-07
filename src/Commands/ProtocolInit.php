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
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;
use Gitcd\Commands\Init\Php81;

Class ProtocolInit extends Command {

    use LockableTrait;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'init';
    protected static $defaultDescription = 'Creates the protocol.json file';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Creates the protocol json file in the directory.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('with-config', 'c', InputOption::VALUE_NONE, 'Also initialize configuration repository')
            // ...
        ;
    }

    /**
     * Get available project initializers
     *
     * @return array
     */
    protected function getAvailableInitializers(): array
    {
        return [
            'php81' => new Php81(),
            // Add more project types here as they become available
            // 'php82' => new Php82(),
            // 'node18' => new Node18(),
        ];
    }

    /**
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // is this a git repo?
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        $helper = $this->getHelper('question');
        $output->writeln('');
        $output->writeln('<info>Protocol Project Initialization</info>');
        $output->writeln('');

        // Get available project types
        $initializers = $this->getAvailableInitializers();
        $choices = [];
        foreach ($initializers as $key => $initializer) {
            $choices[$key] = $initializer->getName() . ' - ' . $initializer->getDescription();
        }

        // Ask user to select project type
        $question = new ChoiceQuestion(
            'What kind of project are you setting up?',
            $choices,
            'php81' // default
        );
        $question->setErrorMessage('Project type %s is invalid.');

        $selectedAnswer = $helper->ask($input, $output, $question);
        
        // Find the key from the selected answer
        $selectedKey = array_search($selectedAnswer, $choices);
        if ($selectedKey === false) {
            // If not found by value, the answer might be the key itself
            $selectedKey = $selectedAnswer;
        }
        
        $selectedInitializer = $initializers[$selectedKey];

        $output->writeln('');
        $output->writeln("<comment>Selected: {$selectedInitializer->getName()}</comment>");
        $output->writeln('');

        // Run the project-specific initialization
        $selectedInitializer->initialize($repo_dir, $output);

        $output->writeln('');

        // Create protocol.json using the base initializer
        $selectedInitializer->createProtocolJson($repo_dir, $selectedKey, $output);

        // Optionally initialize configuration repository
        if ($input->getOption('with-config')) {
            $selectedInitializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
        } else {
            $output->writeln('');
            $question = new ConfirmationQuestion('Do you want to initialize a configuration repository? [y/n] ', false);
            if ($helper->ask($input, $output, $question)) {
                $selectedInitializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
            }
        }

        $output->writeln('');
        $output->writeln('<info>✓ Protocol initialization complete!</info>');
        $output->writeln('');

        return Command::SUCCESS;
    }

}