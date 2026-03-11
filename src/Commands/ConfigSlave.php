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
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Git;
use Gitcd\Utils\JsonLock;

Class ConfigSlave extends BaseSlaveCommand {

    protected static $defaultName = 'config:slave';
    protected static $defaultDescription = 'Keep the config repo updated with the remote changes';

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

        // command should only have one running instance
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        // make sure we're in the application repo
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        // check that the config repo exists
        $configrepo = Config::repo($repo_dir);
        if (!$configrepo) {
            $output->writeln("<error>Please run `protocol config:init` before using this command.</error>");
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Continuously monitoring configuration repo for changes</comment>');

        // Check to see if the PID is still running, fail if it is
        $pid = JsonLock::read('configuration.slave.pid', null, $repo_dir);
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

        [$increment, $daemon] = $this->parseSlaveOptions($input);

        $output->writeln(" - If any changes are made to <info>$remoteurl</info> we'll update <info>$configrepo</info>".PHP_EOL);
        $command = SCRIPT_DIR."git-repo-watcher -d $configrepo -o $remoteName -b $branch -h ".SCRIPT_DIR."git-repo-watcher-hooks -i $increment";

        if ($daemon) {
            $output->writeln(" - This command will run in the <info>background</info> every $increment seconds until you kill it.".PHP_EOL);
        } else {
            $output->writeln(" - This command will run in the <info>foreground</info> every $increment seconds until you kill it.".PHP_EOL);
        }

        return $this->runSlaveCommand(
            $command,
            $daemon,
            $increment,
            [
                'configuration.slave.branch'     => $branch,
                'configuration.slave.remote'     => $remoteurl,
                'configuration.slave.remotename' => $remoteName,
                'configuration.slave.local'      => $configrepo,
                'configuration.slave.increment'  => $increment,
            ],
            'configuration.slave.pid',
            $repo_dir,
            $output
        );
    }

}
