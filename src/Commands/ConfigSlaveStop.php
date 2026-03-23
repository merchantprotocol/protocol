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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;
use Gitcd\Utils\NodeConfig;
use Gitcd\Helpers\DeploymentState;

Class ConfigSlaveStop extends Command {

    use LockableTrait;

    protected static $defaultName = 'config:slave:stop';
    protected static $defaultDescription = 'Stops the config repo slave mode when its running';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
     * 
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        $configrepo = Config::repo($repo_dir);
        $killed = false;

        // Try the tracked PID first
        $project = DeploymentState::resolveProjectName($repo_dir);
        $pid = $project ? NodeConfig::read($project, 'configuration.slave_pid') : null;
        $running = Shell::isRunning( $pid );
        if ($pid && $running) {
            Shell::run("kill " . intval($pid));
            if ($project) {
                NodeConfig::modify($project, function (array $nd) {
                    $nd['configuration']['slave_pid'] = null;
                    return $nd;
                });
            }
            $output->writeln("Slave mode stopped on the config repo (PID: $pid)");
            $killed = true;
        }

        // Sweep for orphaned watchers matching this project's config repo
        if ($configrepo) {
            $processes = Shell::hasProcess("git-repo-watcher -d $configrepo");
            if (!empty($processes)) {
                $pids = array_column($processes, "PID");
                foreach ($pids as $orphanPid) {
                    $orphanPid = intval($orphanPid);
                    if ($orphanPid > 0) {
                        Shell::run("kill $orphanPid");
                        $output->writeln("Killed orphaned config watcher (PID: $orphanPid)");
                        $killed = true;
                    }
                }
            }
        }

        if (!$killed) {
            $output->writeln("No config watchers running");
        }

        return Command::SUCCESS;
    }

}