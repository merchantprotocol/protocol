<?php
namespace Gitcd\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\AuditLog;
use Gitcd\Utils\Json;
use Gitcd\Utils\NodeConfig;

class DeployStrategy extends Command
{
    protected static $defaultName = 'deploy:strategy';
    protected static $defaultDescription = 'View or change the deployment strategy (branch, release)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            View or switch the deployment strategy for this node.

            Strategies:
              branch   — Tip-of-branch polling (git-repo-watcher)
              release  — Release tag polling via PROTOCOL_ACTIVE_RELEASE (release-watcher)

            After switching, run `protocol restart` to activate the new watcher.

            Examples:
              protocol deploy:strategy              # Show current, prompt to change
              protocol deploy:strategy release       # Switch to release strategy
              protocol deploy:strategy branch        # Switch to branch strategy

            HELP)
            ->addArgument('strategy', InputArgument::OPTIONAL, 'Target strategy: branch, release')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo($output, $repo_dir);

        $currentStrategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        // Also check node config
        $projectName = NodeConfig::findByRepoDir($repo_dir);
        if (!$projectName) {
            $match = NodeConfig::findByActiveDir($repo_dir);
            if ($match) {
                $projectName = $match[0];
            }
        }
        $nodeData = $projectName ? NodeConfig::load($projectName) : [];
        $nodeStrategy = $nodeData['deployment']['strategy'] ?? null;
        $awaitingRelease = $nodeData['deployment']['awaiting_release'] ?? false;

        // Display current state
        $output->writeln('');
        $output->writeln("  <fg=white;options=bold>Current Strategy:</> <fg=cyan>{$currentStrategy}</>");
        if ($nodeStrategy && $nodeStrategy !== $currentStrategy) {
            $output->writeln("  <fg=gray>Node config strategy:</> <fg=yellow>{$nodeStrategy}</>");
        }
        if ($awaitingRelease) {
            $output->writeln("  <fg=yellow>⚠</> Awaiting first release tag (auto-switch enabled)");
        }
        $output->writeln('');

        $validStrategies = ['branch', 'release'];
        $newStrategy = $input->getArgument('strategy');

        if (!$newStrategy) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                "  Select deployment strategy (current: {$currentStrategy}):",
                $validStrategies,
                array_search($currentStrategy, $validStrategies)
            );
            $newStrategy = $helper->ask($input, $output, $question);
            $output->writeln('');
        }

        if (!in_array($newStrategy, $validStrategies)) {
            $output->writeln("<error>Invalid strategy: {$newStrategy}. Use: " . implode(', ', $validStrategies) . "</error>");
            return Command::FAILURE;
        }

        if ($newStrategy === $currentStrategy && !$awaitingRelease) {
            $output->writeln("  Already on <fg=cyan>{$currentStrategy}</> strategy.");
            return Command::SUCCESS;
        }

        // Update protocol.json
        Json::write('deployment.strategy', $newStrategy, $repo_dir);
        Json::save($repo_dir);

        // Update node config if it exists
        if ($projectName) {
            $nodeData['deployment']['strategy'] = $newStrategy;
            unset($nodeData['deployment']['awaiting_release']);

            if ($newStrategy === 'branch') {
                $branch = Git::branch($repo_dir);
                $nodeData['deployment']['branch'] = $branch;
            }
            // Keep deployment.branch even when switching to release —
            // protocol stop needs it to find containers started under branch strategy

            NodeConfig::save($projectName, $nodeData);
        }

        AuditLog::logConfig($repo_dir, 'strategy_switch', "{$currentStrategy} -> {$newStrategy}");

        $output->writeln("  <info>✓</info> Strategy changed: <fg=yellow>{$currentStrategy}</> → <fg=cyan>{$newStrategy}</>");
        $output->writeln('');
        $output->writeln("  Run <fg=cyan>protocol restart</> to activate the new watcher.");
        $output->writeln('');

        return Command::SUCCESS;
    }
}
