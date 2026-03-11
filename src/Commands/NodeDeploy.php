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
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\AuditLog;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class NodeDeploy extends Command {

    use LockableTrait;

    protected static $defaultName = 'node:deploy';
    protected static $defaultDescription = 'Deploy a specific release on THIS node only (for staging/testing)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Deploys a specific release version on this node only.
            Does NOT update the GitHub variable — other nodes are unaffected.

            Use this for staging/testing before running `protocol deploy:push <version>`
            to publish to all nodes.

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

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $version = $input->getArgument('version');

        // Fetch latest tags
        $remote = Git::remoteName($repo_dir) ?: 'origin';
        Shell::run("git -C " . escapeshellarg($repo_dir) . " fetch " . escapeshellarg($remote) . " --tags 2>/dev/null");

        // Verify tag exists
        if (!\Gitcd\Helpers\GitHub::tagExists($version, $repo_dir)) {
            $output->writeln("<error>Tag {$version} not found. Fetch or create it first.</error>");
            return Command::FAILURE;
        }

        $currentRelease = JsonLock::read('release.current', null, $repo_dir);
        $output->writeln("<info>Deploying {$version} on this node</info>");

        // Checkout the tag (detached HEAD)
        Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout " . escapeshellarg($version) . " 2>&1");
        $output->writeln(" - Checked out {$version}");

        // Update lock file
        JsonLock::write('release.previous', $currentRelease, $repo_dir);
        JsonLock::write('release.current', $version, $repo_dir);
        JsonLock::write('release.deployed_at', date('Y-m-d\TH:i:sP'), $repo_dir);
        JsonLock::save($repo_dir);
        $output->writeln(' - Updated protocol.lock');

        // Handle encrypted secrets
        $secretsMode = Json::read('deployment.secrets', 'file', $repo_dir);
        $configRepo = Config::repo($repo_dir);

        if ($secretsMode === 'encrypted' && $configRepo) {
            $encFile = $configRepo . '.env.enc';
            if (is_file($encFile) && Secrets::hasKey()) {
                $tmpEnv = Secrets::decryptToTempFile($encFile);
                if ($tmpEnv) {
                    $output->writeln(' - Decrypted secrets to temp file');

                    // Rebuild docker with env file
                    Shell::passthru("docker compose -f " . escapeshellarg($repo_dir . 'docker-compose.yml') . " --env-file " . escapeshellarg($tmpEnv) . " up -d --build 2>&1");

                    // Delete temp file immediately
                    unlink($tmpEnv);
                    $output->writeln(' - Docker containers rebuilt (secrets injected and cleaned)');
                } else {
                    $output->writeln('<error>Failed to decrypt secrets</error>');
                }
            } else {
                // Rebuild docker without secrets
                $command = $this->getApplication()->find('docker:compose:rebuild');
                $command->run(new ArrayInput(['--dir' => $repo_dir]), $output);
            }
        } else {
            // Rebuild docker normally
            $command = $this->getApplication()->find('docker:compose:rebuild');
            $command->run(new ArrayInput(['--dir' => $repo_dir]), $output);
            $output->writeln(' - Docker containers rebuilt');
        }

        AuditLog::logDeploy($repo_dir, $currentRelease ?: 'none', $version, 'success', 'node');

        $output->writeln(PHP_EOL . "<info>Deployed {$version} on this node.</info>");
        $output->writeln('To deploy to all nodes: protocol deploy:push ' . $version);

        return Command::SUCCESS;
    }
}
