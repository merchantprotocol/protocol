<?php
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\PluginManager;

class PluginDisable extends Command
{
    protected static $defaultName = 'plugin:disable';
    protected static $defaultDescription = 'Disable a plugin globally';

    protected function configure(): void
    {
        $this
            ->addArgument('plugin', InputArgument::REQUIRED, 'The plugin slug to disable')
            ->setHelp(<<<HELP
            Disable a plugin globally. The plugin's commands will no longer
            appear in the command list. Re-enable anytime without reconfiguring.

            Example:
              protocol plugin:disable cloudflare
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
            return Command::FAILURE;
        }

        if (!PluginManager::isEnabled($slug)) {
            $output->writeln('');
            $output->writeln("  <fg=gray>Plugin \"{$slug}\" is not enabled.</>");
            $output->writeln('');
            return Command::SUCCESS;
        }

        $meta = PluginManager::readMeta($slug);
        $name = $meta['name'] ?? $slug;

        PluginManager::disable($slug);

        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Plugin <fg=white;options=bold>{$name}</> disabled");
        $output->writeln('    <fg=gray>Run</> <fg=cyan>protocol plugin:enable {$slug}</> <fg=gray>to re-enable.</>');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
