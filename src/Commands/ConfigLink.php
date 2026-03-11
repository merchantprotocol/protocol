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
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Helpers\Secrets;
use Gitcd\Helpers\FileEncryption;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ConfigLink extends Command {

    protected static $defaultName = 'config:link';
    protected static $defaultDescription = 'Create symlinks for the configurations into the application dir';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command will create symlinks for the configuration files into the application directory.

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
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // make sure we're in the application repo
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }
        // make sure the config repo is initialized
        $configrepo = Config::requireRepo($repo_dir, $output);
        if (!$configrepo) {
            return Command::SUCCESS;
        }
        $working_dir = WORKING_DIR;
        $ignored = ['.gitignore', 'README.md', '.git'];

        // Decrypt all .enc files first, before creating symlinks
        $decryptedFiles = [];
        if (Secrets::hasKey()) {
            $decryptedEntries = FileEncryption::decryptDirectory($configrepo, $output, $ignored);
            $keyFingerprint = substr(md5(file_get_contents(Secrets::keyPath())), 0, 8);
            foreach ($decryptedEntries as $entry) {
                $decryptedFiles[] = [
                    'source' => $entry['source'],
                    'decrypted' => $entry['decrypted'],
                    'key_fingerprint' => $keyFingerprint,
                ];
            }
        }

        $configfiles = Dir::dirToArray($configrepo, $ignored);

        foreach($configfiles as $sourcepath)
        {
            if (is_dir($sourcepath)) continue;

            $filename = basename($sourcepath);

            // Skip .enc files — we link the decrypted versions instead
            if (str_ends_with($filename, '.enc')) {
                if (!Secrets::hasKey()) {
                    $output->writeln("<comment>  Skipping encrypted file (no key): {$filename}</comment>");
                }
                continue;
            }

            $fulllink = str_replace($configrepo, $repo_dir, $sourcepath);
            $linkdir = dirname($fulllink).DIRECTORY_SEPARATOR;

            $linkpath = str_replace(dirname($configrepo).DIRECTORY_SEPARATOR, '', $sourcepath);
            $dirpath = str_replace($filename, '',  $linkpath);
            $relpath = Dir::dirDepthToElipsis( $dirpath ).$dirpath.$filename;

            if (!is_dir($linkdir)) {
                Shell::run("mkdir -p " . escapeshellarg($linkdir));
            }
            $linkcmd = "cd " . escapeshellarg($linkdir) . " && ln -s " . escapeshellarg($relpath) . " " . escapeshellarg($filename) . " && cd " . escapeshellarg($working_dir);
            Shell::run($linkcmd);
        }
        JsonLock::write('configuration.symlinks', $configfiles, $repo_dir);
        if (!empty($decryptedFiles)) {
            JsonLock::write('configuration.decrypted_files', $decryptedFiles, $repo_dir);
        }

        $environment = Config::read('env', false);
        JsonLock::write('configuration.active', $environment, $repo_dir);
        JsonLock::save($repo_dir);

        // Also write decrypted file tracking to the config repo's own lock file
        if (!empty($decryptedFiles)) {
            $configLockFile = rtrim($configrepo, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.protocol-secrets.json';
            $lockData = [
                'decrypted_files' => $decryptedFiles,
                'decrypted_at' => date('c'),
            ];
            file_put_contents($configLockFile, json_encode($lockData, JSON_PRETTY_PRINT) . "\n");

            // Make sure decrypted plaintext files are gitignored in the config repo
            foreach ($decryptedFiles as $entry) {
                Git::addIgnore($entry['decrypted'], $configrepo);
            }
        }

        $output->writeln("<info>Done creating symlinks</info>");
        return Command::SUCCESS;
    }

}