<?php
namespace Gitcd\Plugins\sulla\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Plugins\sulla\SullaHelper;
use Gitcd\Helpers\Shell;

class SullaInstall extends Command
{
    protected static $defaultName = 'sulla:install';
    protected static $defaultDescription = 'Download and install the Sulla agent for the current OS';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');

        $this->writeBanner($output);

        // ── Prerequisites ───────────────────────────────────────────
        $output->writeln("    <fg=gray>Checking prerequisites...</>");

        // Node.js
        $nodeVersion = trim(Shell::run('node --version 2>/dev/null', $rv));
        if ($rv !== 0) {
            $output->writeln("    <fg=red>✖</> Node.js is not installed");
            $output->writeln("    <fg=gray>Install Node.js 20+ from https://nodejs.org</>");
            $output->writeln('');
            return Command::FAILURE;
        }
        $output->writeln("    <fg=green>✓</> Node.js <fg=gray>{$nodeVersion}</>");

        // Git
        Shell::run('git --version 2>/dev/null', $rv);
        if ($rv !== 0) {
            $output->writeln("    <fg=red>✖</> Git is not installed");
            return Command::FAILURE;
        }
        $output->writeln("    <fg=green>✓</> Git");
        $output->writeln('');

        // ── Check existing installation ─────────────────────────────
        $repoDir = SullaHelper::repoDir();

        if (SullaHelper::isInstalled()) {
            $output->writeln("    <fg=green>✓</> Sulla agent is already installed");
            $output->writeln("    <fg=gray>Location:</> <fg=white>{$repoDir}</>");
            $output->writeln('');

            $question = new ConfirmationQuestion(
                '    Update to latest? [<fg=green>Y</>/n] ', true
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('');
                $output->writeln('    <fg=gray>No changes made.</>');
                $output->writeln('');
                return Command::SUCCESS;
            }
            $output->writeln('');

            // Pull latest
            $output->writeln("    <fg=gray>Pulling latest changes...</>");
            Shell::passthru("cd " . escapeshellarg($repoDir) . " && git pull --ff-only");
            $output->writeln('');
        } else {
            // ── Clone the repository ────────────────────────────────
            $installDir = SullaHelper::installDir();
            $gitUrl = SullaHelper::GITHUB_SSH;

            if (is_dir($repoDir)) {
                // Directory exists but not fully built — remove and re-clone
                $output->writeln("    <fg=yellow>!</> Incomplete installation found — re-cloning");
                Shell::run("rm -rf " . escapeshellarg($repoDir));
            }

            $output->writeln("    <fg=gray>Cloning dalla-agent from GitHub...</>");
            $output->writeln("    <fg=gray>Repo:</> <fg=white>{$gitUrl}</>");
            $output->writeln('');

            Shell::passthru(sprintf(
                'git clone %s %s',
                escapeshellarg($gitUrl),
                escapeshellarg($repoDir)
            ));
            $output->writeln('');

            if (!is_dir($repoDir . '/src')) {
                $output->writeln("    <fg=red>✖</> Clone failed — check SSH access to {$gitUrl}");
                return Command::FAILURE;
            }
            $output->writeln("    <fg=green>✓</> Cloned to <fg=gray>{$repoDir}</>");
        }

        // ── Install dependencies ────────────────────────────────────
        $output->writeln("    <fg=gray>Installing npm dependencies...</>");
        Shell::passthru("cd " . escapeshellarg($repoDir) . " && npm install --production=false");
        $output->writeln('');

        // ── Build ───────────────────────────────────────────────────
        $output->writeln("    <fg=gray>Building...</>");
        Shell::passthru("cd " . escapeshellarg($repoDir) . " && npm run build");
        $output->writeln('');

        $entryScript = SullaHelper::entryScript();
        if (!is_file($entryScript)) {
            $output->writeln("    <fg=red>✖</> Build failed — dist/sulla-agent.js not found");
            return Command::FAILURE;
        }
        $output->writeln("    <fg=green>✓</> Built successfully");

        // ── Create wrapper script ───────────────────────────────────
        SullaHelper::createWrapper();
        $output->writeln("    <fg=green>✓</> Created wrapper: <fg=gray>" . SullaHelper::binaryPath() . "</>");

        // ── Seed .env if it doesn't exist ───────────────────────────
        $envFile = SullaHelper::envPath();
        if (!is_file($envFile)) {
            $template = $repoDir . '.env.example';
            if (is_file($template)) {
                copy($template, $envFile);
                $output->writeln("    <fg=green>✓</> Created .env from template");
            } else {
                // Write minimal .env
                file_put_contents($envFile, implode("\n", [
                    '# Sulla Agent Configuration',
                    'SULLA_GATEWAY_URL=ws://localhost:8081/ws/agent',
                    'SULLA_GATEWAY_API_KEY=',
                    'SULLA_AGENT_NAME=' . gethostname() . '-sulla',
                    'MCP_SERVERS=',
                    '',
                ]), LOCK_EX);
                $output->writeln("    <fg=green>✓</> Created default .env");
            }
        }

        // ── Done ────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=green;options=bold>✓  Sulla Agent Installed!</>                            <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>Next:</>  <fg=white>protocol sulla:init</>                              <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        return Command::SUCCESS;
    }

    protected function writeBanner(OutputInterface $output): void
    {
        fwrite(STDOUT, "\033[2J\033[H");
        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>SULLA AGENT</> <fg=gray>·</> <fg=yellow>Install</>                              <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>   <fg=gray>Clone and build the Sulla AI agent</>                  <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  │</>                                                         <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');
    }
}
