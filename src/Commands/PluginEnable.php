<?php
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\PluginManager;
use Gitcd\Helpers\Shell;

class PluginEnable extends Command
{
    protected static $defaultName = 'plugin:enable';
    protected static $defaultDescription = 'Enable a plugin and verify its credentials';

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::REQUIRED, 'The plugin slug to enable')
            ->setHelp(<<<HELP
            Enable a plugin globally. When enabling, the plugin's credentials
            and tooling are verified to ensure everything is ready to use.

            Plugin state is stored in ~/.protocol/.node/plugins.json so it applies
            across all projects on this machine.

            Example:
              protocol plugin:enable cloudflare

            To see available plugins:
              protocol plugin:list
            HELP)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('plugin');

        if (!PluginManager::exists($slug)) {
            $output->writeln('');
            $output->writeln("  <error>Plugin \"{$slug}\" not found.</error>");
            $output->writeln('');
            $output->writeln('  <fg=gray>Available plugins:</>');
            foreach (PluginManager::available() as $s => $meta) {
                $output->writeln("    <fg=white>{$s}</>  <fg=gray>" . ($meta['description'] ?? '') . "</>");
            }
            $output->writeln('');
            return Command::FAILURE;
        }

        $meta = PluginManager::readMeta($slug);
        $name = $meta['name'] ?? $slug;

        if (PluginManager::isEnabled($slug)) {
            $output->writeln('');
            $output->writeln("  <fg=yellow>Plugin \"{$slug}\" is already enabled.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln("<fg=cyan>  │</>   <fg=white;options=bold>Enabling Plugin:</> <fg=yellow>{$name}</>                               <fg=cyan>│</>");
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        if (!empty($meta['description'])) {
            $output->writeln("    <fg=gray>{$meta['description']}</>");
            $output->writeln('');
        }

        // Verify credentials / tooling if the plugin defines a verify command
        $verifyCommand = $meta['verify_command'] ?? null;
        if ($verifyCommand) {
            $output->writeln('  <fg=cyan>── Verifying Credentials ─────────────────────────────────</>');
            $output->writeln('');

            $result = Shell::run($verifyCommand, $returnVar);

            if ($returnVar !== 0) {
                $output->writeln("    <fg=red>✗</> Credential check failed");
                $output->writeln('');
                $output->writeln("    <fg=gray>Command:</> <fg=white>{$verifyCommand}</>");
                $output->writeln('');
                if ($result) {
                    foreach (explode(PHP_EOL, trim($result)) as $line) {
                        $output->writeln("    <fg=gray>{$line}</>");
                    }
                    $output->writeln('');
                }
                $output->writeln("    <fg=gray>Please log in first, then try again.</>");
                $output->writeln('');
                return Command::FAILURE;
            }

            // Show the whoami output so the user can confirm the right account
            foreach (explode(PHP_EOL, trim($result)) as $line) {
                $output->writeln("    <fg=gray>{$line}</>");
            }
            $output->writeln('');
            $output->writeln("    <fg=green>✓</> Credentials verified");
            $output->writeln('');
        }

        // Enable the plugin globally
        PluginManager::enable($slug);

        $output->writeln("    <fg=green>✓</> Plugin <fg=white;options=bold>{$name}</> enabled globally");
        $output->writeln("    <fg=gray>Stored in:</> <fg=white>" . PluginManager::configPath() . "</>");
        $output->writeln('');

        if (!empty($meta['commands'])) {
            $output->writeln('    <fg=gray>Available commands:</>');
            foreach ($meta['commands'] as $cmd => $desc) {
                $output->writeln("      <fg=green>{$cmd}</>  <fg=gray>{$desc}</>");
            }
            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
