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
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Git;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ConfigSlave extends Command {

    use LockableTrait;

    protected static $defaultName = 'config:slave';
    protected static $defaultDescription = 'Keep the config repo updated with the remote changes';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command interfaces with a bash script that was created to constantly monitor the git repo
            without leaking memory. When this command is run it will constantly monitor the repository. Any
            updates to the remote repository will be reflected on this node within 10 seconds.

            Our script will make sure that your repository has not diverged from it's source before running
            the update command.

            Additionally, it will keep your repo synced with the same remote branch as you've specified locally.

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('increment', 'i', InputOption::VALUE_OPTIONAL, 'How many seconds to sleep between remote checks')
            ->addOption('no-daemon', 'no-d', InputOption::VALUE_OPTIONAL, 'Do not run as a background service', false)
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
        $io = new SymfonyStyle($input, $output);

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        // make sure we're in the application repo
        $repo_dir = Git::getGitLocalFolder();
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        // check that the config repo exists
        $configrepo = Json::read('configuration.local', false);
        if (!$configrepo) {
            $output->writeln("<error>Please run `protocol config:init` before using this command.</error>");
            return Command::SUCCESS;
        }

        $io->title('Continously Monitoring Config Repository For Changes');

        // Check to see if the PID is still running, fail if it is
        $pid = JsonLock::read('configuration.slave.pid');
        $running = Shell::isRunning( $pid );
        if ($running) {
            $output->writeln("Slave mode is already running on the config repo");
            return Command::SUCCESS;
        }

        $environment = Config::read('env', false);
        $remoteName = Git::remoteName( $configrepo );
        $branch = Git::branch( $configrepo );

        $remoteurl = Git::RemoteUrl( $configrepo );
        if (!$remoteurl) {
            $output->writeln("Your config repo is not connected to a remote source of truth. cancelling...");
            return Command::FAILURE;
        }
        $increment = $input->getOption('increment');
        if (!$increment) {
            $increment = 10;
        }
        $nodaemon = $input->getOption('no-daemon');
        if (is_null($nodaemon)) {
            $nodaemon = true;
        }
        $daemon = !$nodaemon;
        $realpath = realpath($configrepo);

        // trigger the daemon or run it yourself
        if ($daemon) {
            $output->writeln(" - This command will run in the background every $increment seconds until you kill it.");
        } else {
            $output->writeln(" - This command will run in the foreground every $increment seconds until you kill it.");
        }
        $output->writeln(" - If any changes are made to $remoteurl we'll update $realpath".PHP_EOL);

        // execute command
        $command = SCRIPT_DIR."git-repo-watcher -d $realpath -o $remoteName -b $branch -h ".SCRIPT_DIR."git-repo-watcher-hooks -i $increment";
        if ($daemon) {
            // Run the command in the background as a daemon
            JsonLock::write('configuration.slave.branch', $branch);
            JsonLock::write('configuration.slave.remote', $remoteurl);
            JsonLock::write('configuration.slave.remotename', $remoteName);
            JsonLock::write('configuration.slave.local', $realpath);
            JsonLock::write('configuration.slave.increment', $increment);

            $pid = Shell::background($command);
            JsonLock::write('configuration.slave.pid', $pid);
            sleep(1);
            Json::save();

            return Command::SUCCESS;
        }

        // run the command as a passthru to the user
        Shell::passthru($command);
        return Command::SUCCESS;
    }

}