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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\AuditLog;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class Deploy extends Command {

    use LockableTrait;

    protected static $defaultName = 'deploy:push';
    protected static $defaultDescription = 'Deploy a release to ALL nodes by updating the GitHub release pointer';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Sets the active release pointer (GitHub repository variable) so that all
            nodes watching for changes will deploy the specified version.

            Requires the `gh` CLI to be installed and authenticated.

            HELP)
        ;
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Version tag to deploy (e.g., v1.2.3)')
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

        $version = $input->getArgument('version');

        // Validate gh CLI is available
        $ghPath = trim(\Gitcd\Helpers\Shell::run('which gh 2>/dev/null') ?: '');
        if (!$ghPath) {
            $output->writeln('<error>GitHub CLI (gh) is required for deploy:push. Install: https://cli.github.com/</error>');
            return Command::FAILURE;
        }

        // Verify tag exists
        if (!GitHub::tagExists($version, $repo_dir)) {
            // Check GitHub releases as fallback
            $releases = GitHub::listReleases($repo_dir);
            $found = false;
            foreach ($releases as $release) {
                if (($release['tagName'] ?? '') === $version) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $output->writeln("<error>Tag {$version} not found locally or on GitHub.</error>");
                $output->writeln("Create it first: <comment>protocol release:create {$version}</comment>");
                return Command::FAILURE;
            }
        }

        $pointerName = Json::read('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
        $currentRelease = JsonLock::read('release.current', null, $repo_dir);

        $output->writeln("<info>Deploying {$version} to all nodes</info>");
        $output->writeln(" - Setting {$pointerName} = {$version}");

        if (!GitHub::setVariable($pointerName, $version, $repo_dir)) {
            AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $version, 'failure', 'global');
            $output->writeln('<error>Failed to set GitHub variable. Check gh auth status.</error>');
            return Command::FAILURE;
        }

        AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $version, 'success', 'global');

        // Capture PR approval chain for SOC 2 audit trail
        $prs = GitHub::getMergedPRsForTag($version, $repo_dir);
        if (!empty($prs)) {
            foreach ($prs as $prData) {
                AuditLog::logApproval($repo_dir, $version, $prData);
            }
            $output->writeln(" - Captured approval chain for " . count($prs) . " PR(s)");
        }

        $output->writeln('');
        $output->writeln("<info>Deployed {$version} globally.</info>");
        $output->writeln('All nodes polling the release pointer will update automatically.');

        return Command::SUCCESS;
    }
}
