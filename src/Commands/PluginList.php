<?php
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\PluginManager;

class PluginList extends Command
{
    protected static $defaultName = 'plugin:list';
    protected static $defaultDescription = 'List all available plugins and their status';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $available = PluginManager::available();
        $enabled = PluginManager::enabled();

        if (empty($available)) {
            $output->writeln('');
            $output->writeln('  <fg=gray>No plugins found.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<fg=cyan>  ┌─────────────────────────────────────────────────────────┐</>');
        $output->writeln('<fg=cyan>  │</>   <fg=white;options=bold>PROTOCOL</> <fg=gray>·</> <fg=yellow>Plugins</>                                  <fg=cyan>│</>');
        $output->writeln('<fg=cyan>  └─────────────────────────────────────────────────────────┘</>');
        $output->writeln('');

        foreach ($available as $slug => $meta) {
            $isEnabled = in_array($slug, $enabled, true);
            $status = $isEnabled
                ? '<fg=green>enabled</>'
                : '<fg=gray>available</>';

            $name = $meta['name'] ?? $slug;
            $description = $meta['description'] ?? '';
            $commandCount = count($meta['commands'] ?? []);

            $output->writeln("    <fg=white;options=bold>{$name}</> ({$slug})");
            $output->writeln("    <fg=gray>{$description}</>");
            $output->writeln("    Status: {$status}  ·  {$commandCount} commands");

            if ($isEnabled && !empty($meta['commands'])) {
                $output->writeln('');
                foreach ($meta['commands'] as $cmd => $desc) {
                    $output->writeln("      <fg=green>{$cmd}</>  <fg=gray>{$desc}</>");
                }
            }

            if (!$isEnabled) {
                $output->writeln("    <fg=gray>Run:</> <fg=cyan>protocol plugin:enable {$slug}</>");
            }

            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
