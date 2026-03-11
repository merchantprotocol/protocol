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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\GitHub;
use Gitcd\Utils\Json;

Class Migrate extends Command {

    protected static $defaultName = 'migrate';
    protected static $defaultDescription = 'Migrate a legacy branch-based repo to the new release-based architecture';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Migrates an existing Protocol-managed repository from branch-based
            deployment to release-based deployment with encrypted secrets.

            This command will:
            1. Update protocol.json with deployment strategy settings
            2. Set up the encryption key (if not present)
            3. Encrypt existing .env files in the config repo
            4. Stop the legacy git watcher
            5. Offer to create an initial release
            6. Start the new release watcher

            Safe to run on existing repos — it checks current state before each step.

            HELP)
        ;
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('secrets-only', null, InputOption::VALUE_NONE, 'Only migrate secrets (encrypt config, skip deployment strategy change)')
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

        $helper = $this->getHelper('question');
        $secretsOnly = $input->getOption('secrets-only');

        $output->writeln('<info>Protocol Migration</info>');
        $output->writeln('');

        // Step 1: Check current state
        $currentStrategy = Json::read('deployment.strategy', null, $repo_dir);
        $currentSecrets = Json::read('deployment.secrets', null, $repo_dir);

        if ($currentStrategy === 'release' && $currentSecrets === 'encrypted' && !$secretsOnly) {
            $output->writeln('<comment>This repo is already using release-based deployment with encrypted secrets.</comment>');
            return Command::SUCCESS;
        }

        // Step 2: Secrets encryption
        $output->writeln('<comment>Step 1: Secrets encryption</comment>');

        if (!Secrets::hasKey()) {
            $question = new ConfirmationQuestion('No encryption key found. Generate one now? [Y/n] ', true);
            if ($helper->ask($input, $output, $question)) {
                $command = $this->getApplication()->find('secrets:setup');
                $command->run(new ArrayInput([]), $output);
            } else {
                $output->writeln('Skipping key generation. You can run: protocol secrets:setup');
            }
        } else {
            $output->writeln(' - Encryption key already present at ' . Secrets::keyPath());
        }

        // Step 3: Encrypt existing .env files
        $configRepo = Config::repo($repo_dir);
        if ($configRepo && is_dir($configRepo)) {
            $envFile = $configRepo . '.env';
            $encFile = $configRepo . '.env.enc';

            if (is_file($envFile) && !is_file($encFile)) {
                $output->writeln('');
                $output->writeln("<comment>Step 2: Encrypting config repo secrets</comment>");

                if (Secrets::hasKey()) {
                    $result = Secrets::encryptFile($envFile, $encFile);
                    if ($result) {
                        $output->writeln(" - Encrypted {$envFile} -> {$encFile}");
                        $output->writeln(" - <comment>Remember to remove plaintext: rm " . escapeshellarg($envFile) . "</comment>");
                        $output->writeln(" - <comment>Then commit: protocol config:save</comment>");
                    } else {
                        $output->writeln('<error>Failed to encrypt .env file</error>');
                    }
                } else {
                    $output->writeln('<comment>No key available. Skipping encryption.</comment>');
                }
            } elseif (is_file($encFile)) {
                $output->writeln('');
                $output->writeln('<comment>Step 2: Secrets already encrypted (.env.enc exists)</comment>');
            } else {
                $output->writeln('');
                $output->writeln('<comment>Step 2: No .env file found in config repo. Skipping.</comment>');
            }
        }

        if ($secretsOnly) {
            // Update just the secrets setting in protocol.json
            Json::write('deployment.secrets', 'encrypted', $repo_dir);
            Json::save($repo_dir);
            $output->writeln('');
            $output->writeln('<info>Secrets migration complete. protocol.json updated with secrets: "encrypted"</info>');
            return Command::SUCCESS;
        }

        // Step 4: Update protocol.json
        $output->writeln('');
        $output->writeln('<comment>Step 3: Updating protocol.json</comment>');

        Json::write('deployment.strategy', 'release', $repo_dir);
        Json::write('deployment.pointer', 'github_variable', $repo_dir);
        Json::write('deployment.pointer_name', 'PROTOCOL_ACTIVE_RELEASE', $repo_dir);
        Json::write('deployment.secrets', 'encrypted', $repo_dir);
        Json::save($repo_dir);
        $output->writeln(' - Updated deployment strategy to "release"');
        $output->writeln(' - Updated secrets mode to "encrypted"');

        // Step 5: Stop legacy watcher
        $output->writeln('');
        $output->writeln('<comment>Step 4: Stopping legacy git watcher</comment>');
        try {
            $command = $this->getApplication()->find('git:slave:stop');
            $command->run(new ArrayInput(['--dir' => $repo_dir]), $output);
        } catch (\Exception $e) {
            $output->writeln(' - No legacy watcher running');
        }

        // Step 6: Offer to create initial release
        $output->writeln('');
        $output->writeln('<comment>Step 5: Initial release</comment>');

        $tags = GitHub::getTags($repo_dir);
        if (empty($tags)) {
            $question = new ConfirmationQuestion('No tags found. Create an initial v1.0.0 release? [Y/n] ', true);
            if ($helper->ask($input, $output, $question)) {
                $command = $this->getApplication()->find('release:create');
                $command->run(new ArrayInput(['version' => 'v1.0.0', '--dir' => $repo_dir]), $output);
            }
        } else {
            $output->writeln(" - Latest tag: {$tags[0]}");
            $output->writeln(' - You can deploy it with: protocol deploy:push ' . $tags[0]);
        }

        // Step 7: Start release watcher
        $output->writeln('');
        $output->writeln('<comment>Step 6: Starting release watcher</comment>');
        $question = new ConfirmationQuestion('Start the release watcher now? [Y/n] ', true);
        if ($helper->ask($input, $output, $question)) {
            $command = $this->getApplication()->find('deploy:slave');
            $command->run(new ArrayInput(['--dir' => $repo_dir]), $output);
        }

        $output->writeln('');
        $output->writeln('<info>Migration complete!</info>');
        $output->writeln('');
        $output->writeln('Next steps:');
        $output->writeln('  1. Commit protocol.json: git add protocol.json && git commit -m "Migrate to release-based deployment"');
        $output->writeln('  2. If you encrypted secrets, remove plaintext and commit config repo');
        $output->writeln('  3. Deploy: protocol deploy:push <version>');
        $output->writeln('  4. Run on each node: protocol secrets:setup "your-key" && protocol start');

        return Command::SUCCESS;
    }
}
