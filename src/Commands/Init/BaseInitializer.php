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
namespace Gitcd\Commands\Init;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;

abstract class BaseInitializer implements ProjectInitializerInterface
{
    /**
     * Initialize the project structure
     * This is the main entry point that orchestrates the initialization
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return bool
     */
    public function initialize(string $repo_dir, OutputInterface $output): bool
    {
        $output->writeln("<comment>Initializing {$this->getName()} project structure...</comment>");

        // Run project-specific initialization
        $this->initializeProject($repo_dir, $output);

        $output->writeln("<info>✓ {$this->getName()} project initialized successfully</info>");

        return true;
    }

    /**
     * Project-specific initialization logic
     * Each initializer must implement this method
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return void
     */
    abstract protected function initializeProject(string $repo_dir, OutputInterface $output): void;

    /**
     * Create protocol.json with base configuration
     * This is called by ProtocolInit after project initialization
     *
     * @param string $repo_dir
     * @param string $projectTypeKey
     * @param OutputInterface $output
     * @return void
     */
    public function createProtocolJson(string $repo_dir, string $projectTypeKey, OutputInterface $output): void
    {
        $output->writeln('<comment>Creating protocol.json configuration...</comment>');

        // Write basic project metadata
        Json::write('name', basename($repo_dir), $repo_dir);
        Json::write('project_type', $projectTypeKey, $repo_dir);

        // Extract docker-compose.yml data if it exists
        if (file_exists("{$repo_dir}/docker-compose.yml")) {
            $containerName = Yaml::read('services.app.container_name', null, $repo_dir);
            $image = Yaml::read('services.app.image', null, $repo_dir);
            
            if ($containerName) {
                Json::write('docker.container_name', $containerName, $repo_dir);
            }
            if ($image) {
                Json::write('docker.image', $image, $repo_dir);
            }
        }

        // Extract git metadata
        $remoteurl = Git::RemoteUrl($repo_dir);
        if ($remoteurl) {
            Json::write('git.remote', $remoteurl, $repo_dir);
        }

        $remoteName = Git::remoteName($repo_dir);
        if ($remoteName) {
            Json::write('git.remotename', $remoteName, $repo_dir);
        }

        $branch = Git::branch($repo_dir);
        if ($branch) {
            Json::write('git.branch', $branch, $repo_dir);
        }

        // Save the JSON file
        Json::save($repo_dir);

        // Add protocol.lock to .gitignore
        Git::addIgnore('protocol.lock', $repo_dir);

        $output->writeln('  <info>✓</info> Created: <comment>protocol.json</comment>');
    }

    /**
     * Create a directory if it doesn't exist
     *
     * @param string $repo_dir
     * @param string $directory
     * @param OutputInterface $output
     * @return void
     */
    protected function createDirectory(string $repo_dir, string $directory, OutputInterface $output): void
    {
        $fullPath = rtrim($repo_dir, '/') . '/' . $directory;
        
        if (!is_dir($fullPath)) {
            Shell::run("mkdir -p '$fullPath'");
            $output->writeln("  <info>✓</info> Created directory: <comment>$directory</comment>");
        } else {
            $output->writeln("  <comment>→</comment> Directory already exists: <comment>$directory</comment>");
        }
    }

    /**
     * Copy a file from template to destination
     *
     * @param string $source
     * @param string $destination
     * @param string $displayName
     * @param OutputInterface $output
     * @return void
     */
    protected function copyFile(string $source, string $destination, string $displayName, OutputInterface $output): void
    {
        if (file_exists($source)) {
            if (!file_exists($destination)) {
                copy($source, $destination);
                $output->writeln("  <info>✓</info> Copied: <comment>$displayName</comment>");
            } else {
                $output->writeln("  <comment>→</comment> File already exists: <comment>$displayName</comment>");
            }
        } else {
            $output->writeln("  <error>✗</error> Template not found: <comment>$displayName</comment>");
        }
    }

    /**
     * Copy multiple files from a directory
     *
     * @param string $sourceDir
     * @param string $targetDir
     * @param array $files
     * @param string $displayPrefix
     * @param OutputInterface $output
     * @return void
     */
    protected function copyFiles(string $sourceDir, string $targetDir, array $files, string $displayPrefix, OutputInterface $output): void
    {
        foreach ($files as $file) {
            $source = "$sourceDir/$file";
            $destination = "$targetDir/$file";
            $displayName = "$displayPrefix/$file";
            
            $this->copyFile($source, $destination, $displayName, $output);
        }
    }

    /**
     * Initialize configuration repository
     * This handles the config repo setup that was previously in ConfigInit
     *
     * @param string $repo_dir
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $helper
     * @return void
     */
    public function initializeConfigRepo(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void
    {
        $output->writeln('');
        $output->writeln('<comment>Initializing configuration repository...</comment>');

        // Get the environment
        $environment = $input->getArgument('environment') ?: Config::read('env', false);
        if (!$environment) {
            $question = new Question('What is the current environment? (localhost, staging, production)', 'localhost');
            $environment = $helper->ask($input, $output, $question);
            Config::write('env', $environment);
        }

        // Setup config repo paths
        $configrepo = Config::repo($repo_dir);
        $basedir = dirname($repo_dir) . DIRECTORY_SEPARATOR;
        $foldername = basename($repo_dir) . '-config';

        // Create config directory
        if (!is_dir($configrepo)) {
            Shell::run("mkdir -p '$configrepo'");
            $output->writeln("  <info>✓</info> Created config directory: <comment>$foldername</comment>");
        }

        // Check for existing remote URL
        $preExistingRemoteUrl = Json::read('configuration.remote', false, $repo_dir);

        // Clone existing config repo if remote exists
        if ($preExistingRemoteUrl && !is_dir($basedir . $foldername . DIRECTORY_SEPARATOR . '.git')) {
            $output->writeln("  <comment>→</comment> Cloning existing config repo from: <comment>$preExistingRemoteUrl</comment>");
            Shell::passthru("git clone '$preExistingRemoteUrl' '$configrepo'");
            Git::fetch($configrepo);
        }

        // Initialize git repo if needed
        if (!is_dir($configrepo . '.git')) {
            if (!Git::initialize($configrepo)) {
                $output->writeln("  <error>✗</error> Unable to create git repo in: <comment>$configrepo</comment>");
                return;
            }
            Shell::run("git -C '$configrepo' branch -m $environment");
            $output->writeln("  <info>✓</info> Initialized config repo at: <comment>$configrepo</comment>");
            Json::write('configuration.local', '..' . DIRECTORY_SEPARATOR . $foldername, $repo_dir);
        }

        // Setup remote URL
        $configRemoteUrl = $preExistingRemoteUrl ?: Git::RemoteUrl($configrepo);
        if (!$configRemoteUrl) {
            $question = new Question('What is the remote git URL for your config repo? (optional)', false);
            $configRemoteUrl = $helper->ask($input, $output, $question);

            if ($configRemoteUrl) {
                Shell::passthru("git -C '$configrepo' remote add origin '$configRemoteUrl'");
                Json::write('configuration.remote', $configRemoteUrl, $repo_dir);
                $output->writeln("  <info>✓</info> Added remote: <comment>$configRemoteUrl</comment>");
            }
        }

        // Create environment branch if needed
        if ($environment !== Git::branch($configrepo)) {
            Shell::run("git -C '$configrepo' checkout -b $environment");
            $output->writeln("  <info>✓</info> Created environment branch: <comment>$environment</comment>");
        }

        // Copy template files for new repos
        if (!$preExistingRemoteUrl) {
            $templatedir = TEMPLATES_DIR . 'configrepo' . DIRECTORY_SEPARATOR;
            if (!file_exists($configrepo . 'README.md')) {
                Shell::run("cp -R '$templatedir'* '$configrepo'");
                Shell::run("git -C '$configrepo' add -A");
                Shell::run("git -C '$configrepo' commit -m 'initial commit'");
                $output->writeln("  <info>✓</info> Added template files and committed");
            }

            Json::write('configuration.environments', Git::branches($configrepo), $repo_dir);
        }

        // Offer to push
        if (!$preExistingRemoteUrl && $configRemoteUrl) {
            $question = new ConfirmationQuestion('  Push config repo to remote? [y/n] ', false);
            if ($helper->ask($input, $output, $question)) {
                Shell::passthru("git -C '$configrepo' push --all origin");
                $output->writeln("  <info>✓</info> Pushed to remote");
            }
        }

        Json::save($repo_dir);
        $output->writeln('<info>✓ Configuration repository initialized</info>');
    }
}
