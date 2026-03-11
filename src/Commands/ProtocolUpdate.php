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
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;

Class ProtocolUpdate extends Command {

    use LockableTrait;

    protected static $defaultName = 'self:update';
    protected static $defaultDescription = 'Update Protocol to the latest release (or nightly with --nightly)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Updates Protocol itself.

            By default, checks out the latest semver release tag.
            Use --nightly to follow the branch tip instead.

            HELP)
        ;
        $this
            ->addOption('nightly', null, InputOption::VALUE_NONE, 'Follow the branch tip instead of the latest release tag')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $protocoldir = WEBROOT_DIR;
        $remote = Git::remoteName($protocoldir) ?: 'origin';

        // Fetch everything including tags
        Shell::passthru("git -C " . escapeshellarg($protocoldir) . " fetch --all --tags");

        if ($input->getOption('nightly')) {
            // Nightly: reset to branch tip
            $branch = Git::branch($protocoldir) ?: 'main';
            $output->writeln("<comment>Updating to nightly ({$remote}/{$branch})</comment>");
            Shell::passthru("git -C " . escapeshellarg($protocoldir) . " reset --hard {$remote}/{$branch}");
        } else {
            // Release: find the latest semver tag
            $latestTag = trim(Shell::run(
                "git -C " . escapeshellarg($protocoldir) . " tag -l 'v*' --sort=-version:refname 2>/dev/null | head -1"
            ));

            if (empty($latestTag)) {
                $output->writeln('<error>No release tags found. Use --nightly to follow the branch tip.</error>');
                return Command::FAILURE;
            }

            $currentTag = trim(Shell::run(
                "git -C " . escapeshellarg($protocoldir) . " describe --tags --exact-match 2>/dev/null"
            ));

            if ($currentTag === $latestTag) {
                $output->writeln("<info>Already on latest release: {$latestTag}</info>");
                return Command::SUCCESS;
            }

            $output->writeln("<comment>Updating to release {$latestTag}</comment>");
            Shell::passthru("git -C " . escapeshellarg($protocoldir) . " checkout " . escapeshellarg($latestTag));
        }

        $output->writeln('<info>Protocol updated successfully</info>');

        return Command::SUCCESS;
    }

}
