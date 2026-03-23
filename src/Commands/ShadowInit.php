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
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\StageRunner;
use Gitcd\Utils\Json;
use Gitcd\Commands\Init\DotMenuTrait;

Class ShadowInit extends Command {

    use LockableTrait;
    use DotMenuTrait;

    protected static $defaultName = 'shadow:init';
    protected static $defaultDescription = 'Initialize shadow deployment configuration';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Interactive wizard to set up shadow deployment.

            Configures protocol.json with shadow deployment settings including
            the releases directory path, auto-promote preferences, and health
            checks. Release directories are created automatically when you run
            shadow:build <version>.

            Run this once before using any other shadow:* commands.

            HELP)
        ;
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    // ─── Display helpers ─────────────────────────────────────────

    protected function clearAndBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>PROTOCOL</> <fg=gray>·</> <fg=yellow>Shadow Deployment Setup</>                <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Zero-downtime deploys with instant rollback</>         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }

    protected function writeStep(OutputInterface $output, int $step, int $total, string $title): void
    {
        $this->clearAndBanner($output);
        $output->writeln("<fg=cyan>  ── </><fg=white;options=bold>[{$step}/{$total}] {$title}</><fg=cyan> ──────────────────────────────────────</>");
        $output->writeln('');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $helper = $this->getHelper('question');
        $totalSteps = 4;

        // ── Step 1: Explain and confirm ──────────────────────────
        $this->writeStep($output, 1, $totalSteps, 'Shadow Deployment Overview');

        $output->writeln("    <fg=gray>Shadow deployment builds each release version into its own</>");
        $output->writeln("    <fg=gray>self-contained directory with a full git clone, config, and</>");
        $output->writeln("    <fg=gray>Docker containers named with the release tag.</>");
        $output->writeln('');
        $output->writeln("    <fg=white>How it works:</>");
        $output->writeln("    <fg=gray>  1. shadow:build v1.2.0  — clone + build on port 8080</>");
        $output->writeln("    <fg=gray>  2. shadow:start         — swap to production port 80 (~1s)</>");
        $output->writeln("    <fg=gray>  3. shadow:rollback      — swap back instantly if needed</>");
        $output->writeln('');

        $defaultReleasesDir = basename(rtrim($repo_dir, '/')) . '-releases';
        $defaultReleasesPath = dirname(rtrim($repo_dir, '/')) . '/' . $defaultReleasesDir . '/';

        $output->writeln("    <fg=gray>Releases will be stored in a sibling directory:</>");
        $output->writeln("    <fg=white>  {$defaultReleasesPath}</><fg=gray>v1.2.0/</>");
        $output->writeln("    <fg=white>  {$defaultReleasesPath}</><fg=gray>v1.3.0/</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Enable shadow deployment? [<fg=green>Y</>/n] ', true
        );
        if (!$helper->ask($input, $output, $question)) {
            $output->writeln('');
            $output->writeln('    <fg=gray>Cancelled. No changes made.</>');
            return Command::SUCCESS;
        }

        // Enable in protocol.json
        Json::write('bluegreen.enabled', true, $repo_dir);
        Json::save($repo_dir);

        // ── Step 2: Releases directory ───────────────────────────
        $this->writeStep($output, 2, $totalSteps, 'Releases Directory');

        $output->writeln("    <fg=gray>Each version gets its own directory with a full git clone.</>");
        $output->writeln("    <fg=gray>The releases directory is a sibling to your project.</>");
        $output->writeln('');

        $question = new Question(
            "    Releases directory [<fg=green>{$defaultReleasesDir}</>/]: ",
            $defaultReleasesDir
        );
        $releasesDir = $helper->ask($input, $output, $question);

        Json::write('bluegreen.releases_dir', $releasesDir, $repo_dir);

        // Store git remote if not already set
        $gitRemote = Git::RemoteUrl($repo_dir);
        if ($gitRemote) {
            Json::write('bluegreen.git_remote', $gitRemote, $repo_dir);
        }
        Json::save($repo_dir);

        $resolvedPath = BlueGreen::getReleasesDir($repo_dir);
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Releases directory: <fg=white>{$resolvedPath}</>");

        // ── Step 3: Auto-promote preference ──────────────────────
        $this->writeStep($output, 3, $totalSteps, 'Auto-Promote');

        $output->writeln("    <fg=gray>When the release watcher detects a new version, it will</>");
        $output->writeln("    <fg=gray>automatically build a shadow release. After health checks pass:</>");
        $output->writeln('');

        $options = [
            'manual' => 'Manual — wait for you to run shadow:start',
            'auto'   => 'Automatic — promote to production immediately',
        ];

        $output->writeln("    <fg=gray>How should new releases be promoted?</>");
        $output->writeln('');

        $choice = $this->askWithDots($input, $output, $helper, $options, 'manual');

        $autoPromote = ($choice === 'auto');
        Json::write('bluegreen.auto_promote', $autoPromote, $repo_dir);
        Json::save($repo_dir);

        // ── Step 4: Health checks ────────────────────────────────
        $this->writeStep($output, 4, $totalSteps, 'Health Checks');

        $output->writeln("    <fg=gray>Health checks verify the shadow build is working before it</>");
        $output->writeln("    <fg=gray>can be promoted to production. They run against the shadow</>");
        $output->writeln("    <fg=gray>port (8080) after each build.</>");
        $output->writeln('');

        $question = new ConfirmationQuestion(
            '    Add a default HTTP health check (GET /health → 200)? [<fg=green>Y</>/n] ', true
        );
        if ($helper->ask($input, $output, $question)) {
            Json::write('bluegreen.health_checks', [
                ['type' => 'http', 'path' => '/health', 'expect_status' => 200],
            ], $repo_dir);
            Json::save($repo_dir);
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Health check added: <fg=white>GET /health → 200</>");

            $output->writeln('');
            $output->writeln("    <fg=gray>You can add more checks later in protocol.json under</>");
            $output->writeln("    <fg=gray>bluegreen.health_checks (HTTP and exec types supported).</>");
        } else {
            Json::write('bluegreen.health_checks', [], $repo_dir);
            Json::save($repo_dir);
            $output->writeln('');
            $output->writeln("    <fg=gray>Skipped. You can add checks later in protocol.json.</>");
        }

        // ── Completion screen ────────────────────────────────────
        $this->clearAndBanner($output);

        $autoLabel = $autoPromote ? '<fg=green>automatic</>' : '<fg=yellow>manual</>';
        $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
        $healthLabel = count($healthChecks) > 0 ? '<fg=green>' . count($healthChecks) . ' configured</>' : '<fg=yellow>none</>';

        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Shadow Deployment Setup Complete!</>                <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Releases dir:</> <fg=white>" . basename(rtrim($resolvedPath, '/')) . "/</>" . str_repeat(' ', max(1, 30 - strlen(basename(rtrim($resolvedPath, '/'))))) . "<fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Auto-promote:</> {$autoLabel}                             <fg=cyan>│</>");
        $output->writeln("<fg=cyan>  │</>   <fg=gray>Health checks:</> {$healthLabel}                        <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
        $output->writeln('    <fg=gray>Next steps:</>');
        $output->writeln('    <fg=cyan>protocol shadow:build <version></>  <fg=gray>— build a release</>');
        $output->writeln('    <fg=cyan>protocol shadow:start</>            <fg=gray>— promote to production (~1 second)</>');
        $output->writeln('    <fg=cyan>protocol shadow:status</>           <fg=gray>— check release states</>');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
