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
use Gitcd\Helpers\GitHubApp;

Class GitHubToken extends Command {

    protected static $defaultName = 'github:token';
    protected static $defaultDescription = 'Refresh GitHub App credentials for git operations';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Refreshes the GitHub App installation access token and writes it to the
            git-credentials file. Tokens expire after 1 hour, so this command should
            be called before git operations that require authentication.

            This command is designed to be called by long-running watcher scripts
            (git:slave, config:slave) before each git fetch cycle.

            Exit codes:
              0  Token refreshed successfully (or no GitHub App configured)
              1  Token refresh failed

            HELP)
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!GitHubApp::isConfigured()) {
            return Command::SUCCESS;
        }

        $creds = GitHubApp::loadCredentials();
        $owner = $creds['owner'] ?? null;

        if (!$owner) {
            $output->writeln('<error>GitHub App credentials missing owner field</error>');
            return Command::FAILURE;
        }

        $refreshed = GitHubApp::refreshGitCredentials($owner);

        if (!$refreshed) {
            $output->writeln('<error>Failed to refresh GitHub App token</error>');
            return Command::FAILURE;
        }

        if ($output->isVerbose()) {
            $output->writeln('<info>GitHub App token refreshed</info>');
        }

        return Command::SUCCESS;
    }
}
