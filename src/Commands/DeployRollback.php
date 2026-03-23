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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Utils\Json;
use Gitcd\Helpers\DeploymentState;

Class DeployRollback extends Command {

    use LockableTrait;

    protected static $defaultName = 'deploy:rollback';
    protected static $defaultDescription = 'Roll back ALL nodes to a specified or previous release';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Rolls back the active release pointer to the specified version,
            or to the previous version if none is specified.
            All nodes watching the pointer will revert.

            HELP)
        ;
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Version tag to roll back to (e.g., v1.0.0). Defaults to previous release.')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
        ;
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

        $targetVersion = $input->getArgument('version');

        if (!$targetVersion) {
            $prev = DeploymentState::previous($repo_dir);
            $targetVersion = $prev['version'] ?? null;
            if (!$targetVersion) {
                $output->writeln('<error>No previous release found. Specify a version: protocol deploy:rollback v1.0.0</error>');
                return Command::FAILURE;
            }
        }

        $cur = DeploymentState::current($repo_dir);
        $currentRelease = $cur['version'] ?? null;
        $output->writeln("<comment>Rolling back from {$currentRelease} to {$targetVersion}</comment>");

        // Delegate to deploy:push command
        $command = $this->getApplication()->find('deploy:push');
        $returnCode = $command->run(new ArrayInput([
            'version' => $targetVersion,
            '--dir' => $repo_dir,
        ]), $output);

        if ($returnCode === Command::SUCCESS) {
            AuditLog::logRollback($repo_dir, $currentRelease ?: 'none', $targetVersion, 'success', 'global');
        }

        return $returnCode;
    }
}
