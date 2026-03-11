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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Crontab;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\Secrets;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ProtocolStatus extends Command {

    protected static $defaultName = 'status';
    protected static $defaultDescription = 'Checks on the system to see its health';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            Quickly determine the health of a node.

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
     * When the node is relaunched after sleeping through assumed changes
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

        $strategy = Json::read('deployment.strategy', 'branch', $repo_dir);

        $tableRows = [];
        $tableRows[] = ["Environment", "<info>".Config::read('env', 'not set')."</info>"];
        $tableRows[] = ["Deployment Strategy", "<info>{$strategy}</info>"];

        // Show release info in release mode
        if ($strategy === 'release') {
            $currentRelease = JsonLock::read('release.current', null, $repo_dir);
            $previousRelease = JsonLock::read('release.previous', null, $repo_dir);
            $deployedAt = JsonLock::read('release.deployed_at', null, $repo_dir);

            $tableRows[] = ["Deployed Release", $currentRelease ? "<info>{$currentRelease}</info>" : "<comment>none</comment>"];
            if ($previousRelease) {
                $tableRows[] = ["Previous Release", $previousRelease];
            }
            if ($deployedAt) {
                $tableRows[] = ["Deployed At", $deployedAt];
            }

            // VERSION file
            $versionFile = rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'VERSION';
            if (is_file($versionFile)) {
                $tableRows[] = ["VERSION File", "<info>" . trim(file_get_contents($versionFile)) . "</info>"];
            }
        }

        if (Git::isInitializedRepo( $configrepo )) {
            $branch = Git::branch($configrepo);
            $configmatch = Config::read('env', 'not set') == $branch;
            $tableRows[] = ["Configuration Branch", $configmatch ?"<info>$branch</info>" :"<comment>$branch</comment>"];
        } else {
            $tableRows[] = ["Configuration Branch", "<comment>NONE</comment>"];
        }

        // Secrets status
        $secretsMode = Json::read('deployment.secrets', 'file', $repo_dir);
        if ($secretsMode === 'encrypted') {
            $tableRows[] = ["Secrets Mode", "<info>encrypted</info>"];
            $tableRows[] = ["Encryption Key", Secrets::hasKey() ? "<info>PRESENT</info>" : "<error>MISSING</error>"];
        } else {
            $tableRows[] = ["Secrets Mode", "<comment>file (plaintext)</comment>"];
        }

        // Check watcher status based on deployment strategy
        if ($strategy === 'release') {
            $pid = JsonLock::read('release.slave.pid', null, $repo_dir);
            $running = $pid && Shell::isRunning($pid);

            if (!$running) {
                $tableRows[] = ["Release Watcher", "<comment>STOPPED</comment>"];

                // Check for dangling processes
                $processes = Shell::hasProcess("release-watcher.php --dir=");
                if (!empty($processes)) {
                    $pids = array_column($processes, "PID");
                    $pids = implode(",", $pids);
                    $tableRows[] = ["Dangling Watchers", "<error>$pids</error>"];
                }
            } else {
                $tableRows[] = ["Release Watcher", "<info>RUNNING (PID: {$pid})</info>"];
            }
        } else {
            // Legacy branch mode watcher status
            $pid = JsonLock::read('slave.pid', null, $repo_dir );
            $running = Shell::isRunning( $pid );

            if (!$pid || !$running) {
                $tableRows[] = ["Repository Slave Mode", "<comment>STOPPED</comment>"];

                $processes = Shell::hasProcess("git-repo-watcher -d '$repo_dir'");
                $processes2 = Shell::hasProcess("git-repo-watcher -d $repo_dir");
                $processes = $processes+ $processes2;
                if (!empty($processes)) {
                    $pids = array_column($processes, "PID");
                    $pids = implode(",", $pids);
                    $tableRows[] = ["Dangling Watchers", "<error>$pids</error>"];
                }
            } else {
                $tableRows[] = ["Repository Slave Mode", "<info>RUNNING</info>"];
            }
        }

        if (Git::isInitializedRepo( $configrepo )) {
            // Check to see if the PID is still running, fail if it is
            $pid = JsonLock::read('configuration.slave.pid', null, $repo_dir );
            $running = Shell::isRunning( $pid );
            if (!$pid || !$running) {
                $tableRows[] = ["Configuration Slave Mode", "<comment>STOPPED</comment>"];

                // do an additional check for dangling processes
                $processes = Shell::hasProcess("git-repo-watcher -d $configrepo");
                if (!empty($processes)) {
                    $pids = array_column($processes, "PID");
                    $pids = implode(",", $pids);
                    $tableRows[] = ["Dangling Configuration Watchers", "<error>$pids</error>"];
                }
            } else {
                $tableRows[] = ["Configuration Slave Mode", "<info>RUNNING</info>"];
            }
        }

        // let's check on docker
        if (Docker::isDockerInitialized( $repo_dir )) {
            $containers = Docker::getContainerNamesFromDockerComposeFile($repo_dir);
            foreach ($containers as $container) {
                $tableRows[] = ["Docker Service ($container)", (
                    Docker::isDockerContainerRunning( $container )?"<info>RUNNING</info>":"<comment>STOPPED</comment>"
                )];
            }
        }

        // check to see that the crontab is installed
        $tableRows[] = ["Crontab Restart Command Installed", (
            Crontab::hasCrontabRestart( $repo_dir ) ?"<info>YES</info>" :"<comment>NO</comment>"
        )];

        // display the output
        $table = new Table($output);
        $table
            ->setHeaders(['Service', 'Status'])
            ->setRows($tableRows);

        $output->writeln(PHP_EOL);
        $table->render();
        $output->writeln(PHP_EOL);

        return Command::SUCCESS;
    }

}