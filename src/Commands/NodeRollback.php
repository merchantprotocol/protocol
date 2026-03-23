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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\DeploymentState;

Class NodeRollback extends Command {

    protected static $defaultName = 'node:rollback';
    protected static $defaultDescription = 'Roll back THIS node only to the previous release';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Rolls back this node to its previous release.
            Does NOT affect the global pointer or other nodes.

            For rolling back all nodes, use: protocol deploy:rollback

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

        $prev = DeploymentState::previous($repo_dir);
        $previousRelease = $prev['version'] ?? null;
        if (!$previousRelease) {
            $output->writeln('<error>No previous release found. Nothing to roll back to.</error>');
            return Command::FAILURE;
        }

        $cur = DeploymentState::current($repo_dir);
        $currentRelease = $cur['version'] ?? null;
        $output->writeln("<comment>Rolling back this node from {$currentRelease} to {$previousRelease}</comment>");

        $command = $this->getApplication()->find('node:deploy');
        $returnCode = $command->run(new ArrayInput([
            'version' => $previousRelease,
            '--dir' => $repo_dir,
        ]), $output);

        if ($returnCode === Command::SUCCESS) {
            AuditLog::logRollback($repo_dir, $currentRelease, $previousRelease, 'success', 'node');
        }

        return $returnCode;
    }
}
