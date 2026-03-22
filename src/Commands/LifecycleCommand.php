<?php
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Utils\Json;

class LifecycleCommand extends Command
{
    protected static $defaultName = 'lifecycle';
    protected static $defaultDescription = 'Manage post-start lifecycle hooks';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Manage commands that run automatically after `protocol start`.

            Commands prefixed with "exec:<service>" run inside the named
            docker compose service. Plain commands run on the host.

            Examples:
              protocol lifecycle list
              protocol lifecycle add "exec:app composer install --no-interaction"
              protocol lifecycle add "exec:worker php artisan queue:restart"
              protocol lifecycle add "echo deployment complete"
              protocol lifecycle remove 0
              protocol lifecycle clear

            HELP)
            ->addArgument('action', InputArgument::REQUIRED, '"list", "add", "remove", or "clear"')
            ->addArgument('value', InputArgument::OPTIONAL, 'Command to add, or index to remove')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = strtolower(trim($input->getArgument('action')));
        $value = $input->getArgument('value');
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        $hooks = Json::read('lifecycle.post_start', [], $repo_dir);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        $output->writeln('');

        switch ($action) {
            case 'list':
            case 'ls':
                if (empty($hooks)) {
                    $output->writeln('  <fg=gray>No post_start hooks configured.</>');
                    $output->writeln('  <fg=gray>Add one with:</> <fg=white>protocol lifecycle add "exec:app composer install"</>');
                } else {
                    $output->writeln('  <fg=white;options=bold>Post-start hooks:</>');
                    $output->writeln('');
                    foreach ($hooks as $i => $hook) {
                        $output->writeln("  <fg=cyan>[{$i}]</> {$hook}");
                    }
                }
                break;

            case 'add':
                if (!$value) {
                    $output->writeln('<error>Usage: protocol lifecycle add "exec:app composer install"</error>');
                    return Command::FAILURE;
                }
                $hooks[] = $value;
                Json::write('lifecycle.post_start', $hooks, $repo_dir);
                Json::save($repo_dir);
                $output->writeln("  <fg=green>✓</> Added: {$value}");
                break;

            case 'remove':
            case 'rm':
                if ($value === null || !is_numeric($value)) {
                    $output->writeln('<error>Usage: protocol lifecycle remove <index></error>');
                    return Command::FAILURE;
                }
                $index = (int) $value;
                if (!isset($hooks[$index])) {
                    $output->writeln("<error>No hook at index {$index}</error>");
                    return Command::FAILURE;
                }
                $removed = $hooks[$index];
                array_splice($hooks, $index, 1);
                Json::write('lifecycle.post_start', $hooks, $repo_dir);
                Json::save($repo_dir);
                $output->writeln("  <fg=green>✓</> Removed: {$removed}");
                break;

            case 'clear':
                Json::write('lifecycle.post_start', [], $repo_dir);
                Json::save($repo_dir);
                $output->writeln('  <fg=green>✓</> All post_start hooks cleared.');
                break;

            default:
                $output->writeln("<error>Unknown action \"{$action}\". Use: list, add, remove, clear</error>");
                return Command::FAILURE;
        }

        $output->writeln('');
        return Command::SUCCESS;
    }
}
