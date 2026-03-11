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
use Gitcd\Commands\Init\Php82Ffmpeg;

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
            'php82ffmpeg' => new Php82Ffmpeg(),
            'php81'       => new Php81(),
        ];
    }

    // ─── Display helpers ─────────────────────────────────────────

    protected function writeBanner(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>PROTOCOL</> <fg=gray>·</> <fg=yellow>Project Setup Wizard</>                       <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Release-based deployment for Docker applications</>   <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        $output->writeln('');
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }

    protected function writeInfo(OutputInterface $output, string $message): void
    {
        $output->writeln("    <fg=gray>›</> {$message}");
    }

    /**
     * Interactive dot-menu with arrow key navigation
     *
     * Renders options as colored dots. The selected option is green (●),
     * unselected are dim (○). Arrow keys move the selection, Enter confirms.
     * Falls back to numbered input in non-interactive mode.
     */
    protected function askWithDots(
        InputInterface $input,
        OutputInterface $output,
        $helper,
        array $options,
        string $recommended
    ): string {
        $keys = array_keys($options);
        $labels = array_values($options);
        $count = count($keys);
        $selectedIndex = array_search($recommended, $keys);
        if ($selectedIndex === false) {
            $selectedIndex = 0;
        }

        // Non-interactive: fall back to default
        if (!$input->isInteractive()) {
            $this->renderDotMenu($output, $keys, $labels, $selectedIndex, $recommended);
            $output->writeln('');
            return $keys[$selectedIndex];
        }

        // Interactive: arrow key navigation
        // Save terminal state and switch to raw mode
        $sttyState = trim(shell_exec('stty -g 2>/dev/null') ?: '');
        system('stty -echo -icanon min 1 2>/dev/null');

        // Use raw ANSI output to avoid Symfony formatter interference during redraws
        $this->renderDotMenuRaw($keys, $labels, $selectedIndex, $recommended);
        fwrite(STDOUT, "\n");
        fwrite(STDOUT, "    \033[90m↑↓ navigate · enter to select\033[0m");

        $stdin = fopen('php://stdin', 'r');

        while (true) {
            $char = fread($stdin, 1);

            if ($char === "\n" || $char === "\r") {
                break;
            }

            if ($char === "\033") {
                $seq = fread($stdin, 2);
                if ($seq === '[A') { // Up arrow
                    $selectedIndex = ($selectedIndex - 1 + $count) % $count;
                } elseif ($seq === '[B') { // Down arrow
                    $selectedIndex = ($selectedIndex + 1) % $count;
                }

                // Move cursor up to redraw menu (count lines + 1 for hint)
                fwrite(STDOUT, "\033[" . ($count + 1) . "A\r");
                // Clear from cursor down
                fwrite(STDOUT, "\033[J");

                $this->renderDotMenuRaw($keys, $labels, $selectedIndex, $recommended);
                fwrite(STDOUT, "\n");
                fwrite(STDOUT, "    \033[90m↑↓ navigate · enter to select\033[0m");
            }
        }

        // Clear the hint line and redraw final state using Symfony output for the final render
        fwrite(STDOUT, "\033[" . ($count + 1) . "A\r");
        fwrite(STDOUT, "\033[J");

        // Restore terminal before final Symfony render
        if ($sttyState) {
            system("stty '{$sttyState}' 2>/dev/null");
        } else {
            system('stty echo icanon 2>/dev/null');
        }

        $this->renderDotMenu($output, $keys, $labels, $selectedIndex, $recommended);

        $output->writeln('');
        return $keys[$selectedIndex];
    }

    /**
     * Render the dot menu using raw ANSI codes (for interactive redraws)
     */
    protected function renderDotMenuRaw(
        array $keys,
        array $labels,
        int $selectedIndex,
        string $recommended
    ): void {
        foreach ($keys as $i => $key) {
            $label = $labels[$i];
            $isSelected = ($i === $selectedIndex);
            $isRecommended = ($key === $recommended);
            $recTag = $isRecommended ? '  recommended' : '';

            if ($isSelected) {
                // Green dot + green label, white recTag
                fwrite(STDOUT, "    \033[32m●  {$label}\033[0m{$recTag}\n");
            } else {
                // Yellow dot, gray label
                fwrite(STDOUT, "    \033[33m○\033[0m  \033[90m{$label}{$recTag}\033[0m\n");
            }
        }
    }

    /**
     * Render the dot menu display (Symfony formatter version for static renders)
     */
    protected function renderDotMenu(
        OutputInterface $output,
        array $keys,
        array $labels,
        int $selectedIndex,
        string $recommended
    ): void {
        foreach ($keys as $i => $key) {
            $label = $labels[$i];
            $isSelected = ($i === $selectedIndex);
            $isRecommended = ($key === $recommended);
            $recTag = $isRecommended ? '  recommended' : '';

            if ($isSelected) {
                $output->writeln("    <fg=green>●  {$label}</>{$recTag}");
            } else {
                $output->writeln("    <fg=yellow>○</>  <fg=gray>{$label}{$recTag}</>");
            }
        }
    }

    /**
     * Detect project scenario
     *
     * @return string 'new' | 'existing' | 'protocol'
     */
    protected function detectScenario(string $repo_dir): string
    {
        $hasProtocolJson = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'protocol.json');
        if ($hasProtocolJson) {
            return 'protocol';
        }

        $isGitRepo = Git::isInitializedRepo($repo_dir);
        if ($isGitRepo) {
            return 'existing';
        }

        return 'new';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        if (!$repo_dir) {
            $repo_dir = getcwd();
        }
        $helper = $this->getHelper('question');
        $io = new SymfonyStyle($input, $output);

        $this->writeBanner($output);

        // ── Auto-detect scenario ─────────────────────────────────
        $isGitRepo = Git::isInitializedRepo($repo_dir);
        $hasProtocolJson = is_file(rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'protocol.json');

        if ($hasProtocolJson) {
            $scenario = 'protocol';
        } elseif ($isGitRepo) {
            $scenario = 'existing';
        } else {
            $scenario = 'new';
        }

        $selectedKey = $this->askWithDots($input, $output, $helper, [
            'new'      => 'Start a new project',
            'existing' => 'Connect an existing repository',
            'protocol' => 'Update an existing Protocol project',
        ], $scenario);

        // ── Route to the right flow ──────────────────────────────
        switch ($selectedKey) {
            case 'new':
                return $this->flowNewProject($repo_dir, $input, $output, $helper, $io);

            case 'existing':
                return $this->flowExistingProject($repo_dir, $input, $output, $helper, $io);

            case 'protocol':
                return $this->flowProtocolProject($repo_dir, $input, $output, $helper, $io);
        }

        return Command::SUCCESS;
    }

    // ─── Flow: New Project ───────────────────────────────────────

    protected function flowNewProject(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $totalSteps = 5;

        // Step 1: Initialize git
        $this->writeStep($output, 1, $totalSteps, 'Initialize Git Repository');

        if (!Git::isInitializedRepo($repo_dir)) {
            Shell::run("git -C " . escapeshellarg($repo_dir) . " init");
            $output->writeln("    <fg=green>✓</> Git repository initialized");
        } else {
            $output->writeln("    <fg=green>✓</> Git repository already initialized");
        }

        // Step 2: Project type + scaffold
        $this->writeStep($output, 2, $totalSteps, 'Project Type');
        $selectedInitializer = $this->selectProjectType($input, $output, $helper);

        $output->writeln('');
        $selectedInitializer->initialize($repo_dir, $input, $output, $helper);
        $output->writeln('');

        $selectedKey = $this->getInitializerKey($selectedInitializer);
        $selectedInitializer->createProtocolJson($repo_dir, $selectedKey, $output);

        // Step 3: Deployment strategy
        $this->writeStep($output, 3, $totalSteps, 'Deployment Strategy');
        $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper);

        // Step 4: Secrets
        $this->writeStep($output, 4, $totalSteps, 'Secrets Management');
        $this->configureSecrets($repo_dir, $input, $output, $helper);

        // Step 5: Config repo
        $this->writeStep($output, 5, $totalSteps, 'Configuration Repository');
        $this->configureConfigRepo($repo_dir, $input, $output, $helper, $selectedInitializer);

        // Done
        $this->writeCompletion($output, $repo_dir);

        return Command::SUCCESS;
    }

    // ─── Flow: Existing Project ──────────────────────────────────

    protected function flowExistingProject(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $totalSteps = 4;

        // Step 1: Project type + scaffold
        $this->writeStep($output, 1, $totalSteps, 'Project Type');

        $remote = Git::RemoteUrl($repo_dir) ?: 'local only';
        $output->writeln("    <fg=gray>Remote:</> <fg=white>{$remote}</>");
        $output->writeln('');

        $selectedInitializer = $this->selectProjectType($input, $output, $helper);

        $output->writeln('');
        $selectedInitializer->initialize($repo_dir, $input, $output, $helper);
        $output->writeln('');

        $selectedKey = $this->getInitializerKey($selectedInitializer);
        $selectedInitializer->createProtocolJson($repo_dir, $selectedKey, $output);

        // Step 2: Deployment strategy
        $this->writeStep($output, 2, $totalSteps, 'Deployment Strategy');
        $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper);

        // Step 3: Secrets
        $this->writeStep($output, 3, $totalSteps, 'Secrets Management');
        $this->configureSecrets($repo_dir, $input, $output, $helper);

        // Step 4: Config repo
        $this->writeStep($output, 4, $totalSteps, 'Configuration Repository');
        $this->configureConfigRepo($repo_dir, $input, $output, $helper, $selectedInitializer);

        // Done
        $this->writeCompletion($output, $repo_dir);

        return Command::SUCCESS;
    }

    // ─── Flow: Protocol Project ──────────────────────────────────

    protected function flowProtocolProject(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        SymfonyStyle $io
    ): int {
        $currentStrategy = Json::read('deployment.strategy', 'branch', $repo_dir);
        $projectName = Json::read('name', basename($repo_dir), $repo_dir);

        $output->writeln('');
        $output->writeln("    <fg=white;options=bold>{$projectName}</>");
        $output->writeln("    <fg=gray>Strategy:</> <fg=cyan>{$currentStrategy}</>  <fg=gray>·</>  <fg=gray>Dir:</> <fg=white>{$repo_dir}</>");
        $output->writeln('');

        $actionKey = $this->askWithDots($input, $output, $helper, [
            'settings' => 'Re-run full project setup',
            'strategy' => 'Change deployment strategy',
            'secrets'  => 'Set up encrypted secrets',
            'config'   => 'Initialize configuration repository',
            'exit'     => 'Exit without changes',
        ], 'settings');

        switch ($actionKey) {
            case 'settings':
                return $this->flowExistingProject($repo_dir, $input, $output, $helper, $io);

            case 'strategy':
                $output->writeln('');
                $this->configureDeploymentStrategy($repo_dir, $input, $output, $helper);
                $output->writeln('');
                return Command::SUCCESS;

            case 'secrets':
                $output->writeln('');
                $command = $this->getApplication()->find('secrets:setup');
                $command->run(new ArrayInput([]), $output);
                return Command::SUCCESS;

            case 'config':
                $output->writeln('');
                $initializers = $this->getAvailableInitializers();
                $initializer = reset($initializers);
                $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
                return Command::SUCCESS;

            case 'exit':
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                $output->writeln('');
                return Command::SUCCESS;
        }

        return Command::SUCCESS;
    }

    // ─── Shared steps ────────────────────────────────────────────

    protected function selectProjectType(InputInterface $input, OutputInterface $output, $helper)
    {
        $initializers = $this->getAvailableInitializers();
        $choices = [];
        foreach ($initializers as $key => $initializer) {
            $choices[$key] = $initializer->getName() . ' — ' . $initializer->getDescription();
        }

        $selectedKey = $this->askWithDots($input, $output, $helper, $choices, 'php82ffmpeg');

        $selectedInitializer = $initializers[$selectedKey];
        $output->writeln("    <fg=green>✓</> Selected: <fg=white;options=bold>{$selectedInitializer->getName()}</>");

        return $selectedInitializer;
    }

    protected function getInitializerKey($initializer): string
    {
        $initializers = $this->getAvailableInitializers();
        foreach ($initializers as $key => $init) {
            if (get_class($init) === get_class($initializer)) {
                return $key;
            }
        }
        return 'php82ffmpeg';
    }

    protected function configureDeploymentStrategy(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): void {
        $currentStrategy = Json::read('deployment.strategy', null, $repo_dir);

        $strategyKey = $this->askWithDots($input, $output, $helper, [
            'release' => 'Release-based — rollback, audit trail, multi-node',
            'branch'  => 'Branch-based — simple, tracks branch tip (legacy)',
        ], $currentStrategy ?: 'release');

        Json::write('deployment.strategy', $strategyKey, $repo_dir);

        if ($strategyKey === 'release') {
            Json::write('deployment.pointer', 'github_variable', $repo_dir);
            Json::write('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
        }

        Json::save($repo_dir);
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Strategy: <fg=white;options=bold>{$strategyKey}</>");
    }

    protected function configureSecrets(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper
    ): void {
        if (Secrets::hasKey()) {
            $output->writeln("    <fg=green>✓</> Encryption key present <fg=gray>— " . Secrets::keyPath() . "</>");
            return;
        }

        $output->writeln("    <fg=gray>Encrypt your .env files with AES-256-GCM.</>");
        $output->writeln("    <fg=gray>Keys stay local, secrets travel encrypted in git.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Set up encrypted secrets? <fg=gray>(recommended for production)</> [y/<fg=green>N</>] ', false
        );
        if ($helper->ask($input, $output, $question)) {
            $command = $this->getApplication()->find('secrets:setup');
            $command->run(new ArrayInput([]), $output);
            Json::write('deployment.secrets', 'encrypted', $repo_dir);
            Json::save($repo_dir);
        } else {
            $this->writeInfo($output, '<fg=gray>Skipped. Run</> <fg=cyan>protocol secrets:setup</> <fg=gray>later.</>');
        }
    }

    protected function configureConfigRepo(
        string $repo_dir,
        InputInterface $input,
        OutputInterface $output,
        $helper,
        $initializer
    ): void {
        if ($input->getOption('with-config')) {
            $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
            return;
        }

        $output->writeln("    <fg=gray>Store .env, nginx, and cron configs in a separate repo.</>");
        $output->writeln("    <fg=gray>Each branch = one environment. Symlinked into your app.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Initialize a configuration repository? [y/<fg=green>N</>] ', false
        );
        if ($helper->ask($input, $output, $question)) {
            $initializer->initializeConfigRepo($repo_dir, $input, $output, $helper);
        } else {
            $this->writeInfo($output, '<fg=gray>Skipped. Run</> <fg=cyan>protocol config:init</> <fg=gray>later.</>');
        }
    }

    protected function writeCompletion(OutputInterface $output, string $repo_dir): void
    {
        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        // Auto-commit protocol files
        $this->commitProtocolFiles($output, $repo_dir);

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Setup Complete!</>                                    <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>Next steps:</>                                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>1.</> <fg=white>protocol start</>           <fg=gray>Start this node</>       <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=yellow>2.</> <fg=white>protocol status</>          <fg=gray>Check node health</>     <fg=cyan>│</>');

        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function commitProtocolFiles(OutputInterface $output, string $repo_dir): void
    {
        if (!Git::isInitializedRepo($repo_dir)) {
            return;
        }

        $filesToAdd = [
            'protocol.json',
            'docker-compose.yml',
            '.gitignore',
            'nginx.d',
            'cron.d',
            'supervisor.d',
        ];

        $added = [];
        foreach ($filesToAdd as $file) {
            $fullPath = rtrim($repo_dir, '/') . '/' . $file;
            if (file_exists($fullPath) || is_dir($fullPath)) {
                Shell::run("git -C " . escapeshellarg($repo_dir) . " add " . escapeshellarg($file) . " 2>/dev/null");
                $added[] = $file;
            }
        }

        if (empty($added)) {
            return;
        }

        // Check if there's anything staged
        $status = Shell::run("git -C " . escapeshellarg($repo_dir) . " diff --cached --name-only 2>/dev/null");
        if (empty(trim($status))) {
            $output->writeln('');
            $output->writeln("    <fg=gray>›</> No changes to commit");
            return;
        }

        Shell::run("git -C " . escapeshellarg($repo_dir) . " commit -m 'protocol init' 2>/dev/null");
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Committed: <fg=white>" . implode(', ', $added) . "</>");
    }
}
