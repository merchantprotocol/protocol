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
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;
use Gitcd\Helpers\DeploymentState;

Class DeployStatus extends Command {

    protected static $defaultName = 'deploy:status';
    protected static $defaultDescription = 'Show the current active release and this node\'s deployed version';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Shows the active release pointer and whether this node is in sync.

            HELP)
        ;
        $this
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

        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        $rows = [];
        $rows[] = ['Deployment Strategy', "<info>{$strategy}</info>"];

        if ($strategy === 'release') {
            $pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
            $activePointer = GitHub::getVariable($pointerName, $repo_dir);
            $cur = DeploymentState::current($repo_dir);
            $currentRelease = $cur['version'] ?? null;
            $deployedAt = $cur['deployed_at'] ?? null;
            $prev = DeploymentState::previous($repo_dir);
            $previousRelease = $prev['version'] ?? null;

            $rows[] = ['Active Pointer', $activePointer ? "<info>{$activePointer}</info>" : '<comment>not set</comment>'];
            $rows[] = ['This Node', $currentRelease ? "<info>{$currentRelease}</info>" : '<comment>not deployed</comment>'];

            if ($previousRelease) {
                $rows[] = ['Previous Release', $previousRelease];
            }
            if ($deployedAt) {
                $rows[] = ['Deployed At', $deployedAt];
            }

            // Sync status
            if ($activePointer && $currentRelease) {
                $inSync = $activePointer === $currentRelease;
                $rows[] = ['Sync Status', $inSync ? '<info>IN SYNC</info>' : '<error>OUT OF SYNC</error>'];
            }
        } else {
            $branch = Git::branch($repo_dir);
            $rows[] = ['Branch', "<info>{$branch}</info>"];
        }

        $table = new Table($output);
        $table->setHeaders(['Property', 'Value']);
        $table->setRows($rows);

        $output->writeln('');
        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
