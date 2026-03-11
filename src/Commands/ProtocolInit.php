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
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Secrets;
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;
use Gitcd\Commands\Init\Php81;

Class ProtocolInit extends Command {

    use LockableTrait;

    protected static $defaultName = 'init';
    protected static $defaultDescription = 'Initialize a new project or connect an existing repository';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Walk through setting up Protocol for a new or existing project.

            This command will:
            1. Verify your repository is compatible (git, docker, etc.)
            2. Create or update protocol.json with project settings
            3. Choose a deployment strategy (release-based or branch-based)
            4. Optionally set up encrypted secrets
            5. Optionally initialize a configuration repository

            Safe to run on existing Protocol projects — it detects current
            state and offers to update rather than overwrite.

            HELP)
        ;
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'What is the current environment?', false)
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('with-config', 'c', InputOption::VALUE_NONE, 'Also initialize configuration repository')
        ;
    }

    /**
     * Get available project initializers
     */
    protected function getAvailableInitializers(): array
    {
        return [
            'php81' => new Php81(),
        ];
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        $helper = $this->getHelper('question');
        $io = new SymfonyStyle($input, $output);

        $io->title('Protocol Project Setup');

        // ── Step 1: Compatibility checks ──────────────────────────
        $io->section('Step 1: Compatibility Check');

        $isGitRepo = Git::isInitializedRepo($repo_dir);
        $hasProtocolJson = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'protocol.json');
        $hasDockerCompose = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'docker-compose.yml');
        $hasGhCli = !empty(trim(Shell::run('which gh 2>/dev/null') ?: ''));

        $checks = [];
        $checks[] = [$isGitRepo ? '<info>PASS</info>' : '<error>FAIL</error>', 'Git repository', $isGitRepo ? Git::RemoteUrl($repo_dir) ?: 'local only' : 'Not a git repo'];
        $checks[] = [$hasProtocolJson ? '<comment>EXISTS</comment>' : '<info>NEW</info>', 'protocol.json', $hasProtocolJson ? 'Will update existing' : 'Will create new'];
        $checks[] = [$hasDockerCompose ? '<info>FOUND</info>' : '<comment>NONE</comment>', 'docker-compose.yml', $hasDockerCompose ? 'Docker config detected' : 'Can be added later'];
        $checks[] = [$hasGhCli ? '<info>FOUND</info>' : '<comment>NONE</comment>', 'GitHub CLI (gh)', $hasGhCli ? 'Release deployments available' : 'Install for release deployments'];

        $io->table(['', 'Check', 'Details'], $checks);

        if (!$isGitRepo) {
            $question = new ConfirmationQuestion('This directory is not a git repository. Initialize one now? [Y/n] ', true);
            if ($helper->ask($input, $output, $question)) {
                Shell::run("git -C " . escapeshellarg($repo_dir) . " init");
                $io->success('Git repository initialized');
            } else {
                $io->error('Protocol requires a git repository. Run: git init');
                return Command::FAILURE;
            }
        }

        // ── Step 2: Existing project vs new setup ─────────────────
        if ($hasProtocolJson) {
            $io->section('Step 2: Existing Protocol Project Detected');

            $currentStrategy = Json::read('deployment.strategy', 'branch', $repo_dir);
            $projectName = Json::read('name', basename($repo_dir), $repo_dir);

            $io->text([
                "Project: <info>{$projectName}</info>",
                "Current strategy: <info>{$currentStrategy}</info>",
            ]);

            $question = new ChoiceQuestion(
                'What would you like to do?',
                [
                    'update'   => 'Update project settings',
                    'strategy' => 'Change deployment strategy',
                    'secrets'  => 'Set up encrypted secrets',
                    'config'   => 'Initialize configuration repository',
                    'skip'     => 'Exit without changes',
                ],
                'update'
            );

            $action = $helper->ask($input, $output, $question);
            $actionKey = array_search($action, [
                'update'   => 'Update project settings',
                'strategy' => 'Change deployment strategy',
                'secrets'  => 'Set up encrypted secrets',
                'config'   => 'Initialize configuration repository',
                'skip'     => 'Exit without changes',
            ]);
            if ($actionKey === false) {
                $actionKey = $action;
            }

            switch ($actionKey) {
                case 'strategy':
                    $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper, $io);
                    return Command::SUCCESS;

                case 'secrets':
                    $command = $this->getApplication()->find('secrets:setup');
                    $command->run(new ArrayInput([]), $output);
                    return Command::SUCCESS;

                case 'config':
                    $initializers = $this->getAvailableInitializers();
                    $initializer = reset($initializers);
                    $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
                    return Command::SUCCESS;

                case 'skip':
                    $io->text('No changes made.');
                    return Command::SUCCESS;

                case 'update':
                default:
                    break;
            }
        }

        // ── Step 3: Project type selection ─────────────────────────
        $io->section($hasProtocolJson ? 'Step 2: Update Project Settings' : 'Step 2: Project Type');

        $initializers = $this->getAvailableInitializers();
        $choices = [];
        foreach ($initializers as $key => $initializer) {
            $choices[$key] = $initializer->getName() . ' - ' . $initializer->getDescription();
        }

        $question = new ChoiceQuestion(
            'What kind of project are you setting up?',
            $choices,
            'php81'
        );
        $question->setErrorMessage('Project type %s is invalid.');

        $selectedAnswer = $helper->ask($input, $output, $question);
        $selectedKey = array_search($selectedAnswer, $choices);
        if ($selectedKey === false) {
            $selectedKey = $selectedAnswer;
        }
        $selectedInitializer = $initializers[$selectedKey];

        $output->writeln('');
        $output->writeln("<comment>Selected: {$selectedInitializer->getName()}</comment>");
        $output->writeln('');

        $selectedInitializer->initialize($repo_dir, $input, $output, $helper);
        $output->writeln('');

        $selectedInitializer->createProtocolJson($repo_dir, $selectedKey, $output);

        // ── Step 4: Deployment strategy ────────────────────────────
        $io->section('Step 3: Deployment Strategy');
        $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper, $io);

        // ── Step 5: Encrypted secrets ──────────────────────────────
        $io->section('Step 4: Secrets Management');

        if (Secrets::hasKey()) {
            $io->text('Encryption key already present at ' . Secrets::keyPath());
        } else {
            $question = new ConfirmationQuestion('Set up encrypted secrets? (recommended for production) [y/N] ', false);
            if ($helper->ask($input, $output, $question)) {
                $command = $this->getApplication()->find('secrets:setup');
                $command->run(new ArrayInput([]), $output);
                Json::write('deployment.secrets', 'encrypted', $repo_dir);
                Json::save($repo_dir);
            } else {
                $io->text('Skipped. You can run <info>protocol secrets:setup</info> later.');
            }
        }

        // ── Step 6: Configuration repository ───────────────────────
        if ($input->getOption('with-config')) {
            $selectedInitializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
        } else {
            $io->section('Step 5: Configuration Repository');
            $question = new ConfirmationQuestion('Initialize a configuration repository? [y/N] ', false);
            if ($helper->ask($input, $output, $question)) {
                $selectedInitializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
            } else {
                $io->text('Skipped. You can run <info>protocol config:init</info> later.');
            }
        }

        // ── Done ───────────────────────────────────────────────────
        $io->newLine();
        $io->success('Protocol initialization complete!');

        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        $io->text('<fg=yellow>Next steps:</>');
        $io->listing([
            'Commit your changes: <info>git add protocol.json && git commit -m "Add Protocol config"</info>',
            $strategy === 'release'
                ? 'Create your first release: <info>protocol release:create</info>'
                : 'Start this node: <info>protocol start</info>',
            'Check node health: <info>protocol status</info>',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Configure deployment strategy (release vs branch)
     */
    protected function configureDeploymentStrategy(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): void {
        $currentStrategy = Json::read('deployment.strategy', null, $repo_dir);

        $io->text([
            '<fg=yellow>Release-based</> (recommended): Tag releases, deploy via GitHub variable.',
            '  All nodes poll a single pointer. Instant rollback, full audit trail.',
            '',
            '<fg=yellow>Branch-based</> (legacy): Nodes track a git branch tip.',
            '  Simpler but no versioning, no rollback, no audit log.',
        ]);
        $output->writeln('');

        $question = new ChoiceQuestion(
            'Select deployment strategy' . ($currentStrategy ? " (current: {$currentStrategy})" : ''),
            [
                'release' => 'Release-based (recommended)',
                'branch'  => 'Branch-based (legacy)',
            ],
            $currentStrategy ?: 'release'
        );

        $answer = $helper->ask($input, $output, $question);
        $strategyKey = array_search($answer, [
            'release' => 'Release-based (recommended)',
            'branch'  => 'Branch-based (legacy)',
        ]);
        if ($strategyKey === false) {
            $strategyKey = $answer;
        }

        Json::write('deployment.strategy', $strategyKey, $repo_dir);

        if ($strategyKey === 'release') {
            Json::write('deployment.pointer', 'github_variable', $repo_dir);
            Json::write('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
        }

        Json::save($repo_dir);
        $io->text("Deployment strategy set to: <info>{$strategyKey}</info>");
    }
}
