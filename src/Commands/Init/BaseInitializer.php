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
use Symfony\Component\Console\Question\ChoiceQuestion;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;

abstract class BaseInitializer implements ProjectInitializerInterface
{
    /**
     * Get the Docker Hub image for this project type
     */
    abstract public function getDockerImage(): string;

    /**
     * Get the GitHub repository URL for the Docker image source
     */
    abstract public function getGitHubRepo(): string;

    /**
     * Initialize the project structure
     * This is the main entry point that orchestrates the initialization
     *
     * @param string $repo_dir
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $helper
     * @return bool
     */
    public function initialize(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): bool
    {
        $output->writeln("<comment>Initializing {$this->getName()} project structure...</comment>");

        // Run project-specific initialization
        $this->initializeProject($repo_dir, $input, $output, $helper);

        $output->writeln("<info>✓ {$this->getName()} project initialized successfully</info>");

        return true;
    }

    /**
     * Project-specific initialization logic
     * Each initializer must implement this method
     *
     * @param string $repo_dir
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $helper
     * @return void
     */
    abstract protected function initializeProject(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void;

    /**
     * Generate a docker-compose.yml that builds from the GitHub repo
     */
    protected function generateDockerCompose(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void
    {
        $dockerComposePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
        $githubRepo = $this->getGitHubRepo();
        $projectName = basename($repo_dir) ?: 'app';

        $output->writeln('');
        $output->writeln("    <fg=gray>Build from:</> <fg=cyan>{$githubRepo}</>");
        $output->writeln('');

        // Ask for container name
        $question = new Question("    Container name [{$projectName}]: ", $projectName);
        $containerName = $helper->ask($input, $output, $question);

        // Ask if they want to use a custom Docker Hub image instead
        $question = new ConfirmationQuestion(
            '    Use a custom Docker Hub image instead of building from GitHub? [y/<fg=green>N</>] ', false
        );
        $useCustomImage = $helper->ask($input, $output, $question);

        $customImage = null;
        if ($useCustomImage) {
            $question = new Question('    Docker image (e.g. myorg/myimage:latest): ');
            $customImage = $helper->ask($input, $output, $question);
        }

        if (file_exists($dockerComposePath)) {
            $question = new ConfirmationQuestion(
                '    docker-compose.yml already exists. Overwrite? [y/<fg=green>N</>] ', false
            );
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln("    <fg=green>✓</> Keeping existing docker-compose.yml");
                $this->createOverrideDirectories($repo_dir, $output);
                return;
            }
        }

        if ($customImage) {
            $imageOrBuild = "    image: {$customImage}";
            $sourceLabel = $customImage;
        } else {
            $imageOrBuild = "    build:\n      context: {$githubRepo}";
            $sourceLabel = $githubRepo;
        }

        $compose = <<<YAML
services:
  app:
    container_name: {$containerName}
{$imageOrBuild}
    restart: unless-stopped
    hostname: \${DOCKER_HOSTNAME}
    ports:
      - "80:80"
      - "443:443"
    extra_hosts:
      - "host.docker.internal:host-gateway"
    volumes:
      - ".:/var/www/html:rw"
      - "./supervisor.d:/etc/supervisor/conf.d/custom:ro"
    ulimits:
      core:
        hard: 0
        soft: 0
YAML;

        file_put_contents($dockerComposePath, $compose . "\n");
        $output->writeln('');
        $output->writeln("    <fg=green>✓</> Created docker-compose.yml");
        $output->writeln("    <fg=green>✓</> Container: <fg=white>{$containerName}</>");
        $output->writeln("    <fg=green>✓</> Source: <fg=white>{$sourceLabel}</>");

        // Create override directories with README files
        $this->createOverrideDirectories($repo_dir, $output);
    }

    /**
     * Create override directories with README files explaining their purpose
     */
    protected function createOverrideDirectories(string $repo_dir, OutputInterface $output): void
    {
        $dirs = [
            'nginx.d' => <<<'README'
# nginx.d — Nginx Configuration Overrides

Drop Nginx configuration files here to customize the web server
inside the container.

## How it works

This directory is mounted at `/var/www/html/nginx.d/` inside the
container. The base Nginx config is baked into the Docker image —
files here override or extend it.

## Common uses

- **Custom virtual host**: Add a `.conf` file with a `server {}` block
- **SSL certificates**: Place cert files here and reference them in config
- **Rewrites / redirects**: Add rewrite rules in a `.conf` file

## Example

```nginx
# nginx.d/custom-headers.conf
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
```

Files are NOT auto-included. Include them from your main config
or replace the default site config entirely.
README
            ,
            'cron.d' => <<<'README'
# cron.d — Cron Job Scripts

Place shell scripts here to run as scheduled tasks inside the
container.

## How it works

This directory is mounted at `/var/www/html/cron.d/` inside the
container. The Docker image includes a crontab that runs
`/opt/scripts/runcron.sh` which executes scripts from this
directory.

## Adding a cron job

1. Create a `.sh` script in this directory
2. Make it executable: `chmod +x cron.d/myscript.sh`
3. The container's crontab will pick it up automatically

## Example

```bash
#!/bin/bash
# cron.d/clear-cache.sh
cd /var/www/html && php artisan cache:clear
```
README
            ,
            'supervisor.d' => <<<'README'
# supervisor.d — Supervisor Program Configs

Add Supervisor configuration files here to run additional
background processes inside the container.

## How it works

This directory is mounted at `/etc/supervisor/conf.d/custom/`
inside the container. Supervisor automatically picks up `.conf`
files and manages the processes.

## Built-in programs

The Docker image already runs these via Supervisor:
- `php-fpm` — PHP FastCGI process manager
- `nginx` — Web server
- `cron` — Cron daemon

## Adding a worker

Create a `.conf` file following Supervisor's format:

```ini
; supervisor.d/queue-worker.conf
[program:queue-worker]
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=1
stdout_logfile=/var/log/queue-worker.log
stderr_logfile=/var/log/queue-worker-error.log
```

## Common uses

- Queue workers (Laravel, Symfony Messenger, etc.)
- WebSocket servers
- Long-running daemons
- Log processors
README
        ];

        $output->writeln('');
        foreach ($dirs as $dir => $readme) {
            $fullPath = rtrim($repo_dir, '/') . '/' . $dir;
            $readmePath = $fullPath . '/README.md';

            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
                $output->writeln("    <fg=green>✓</> Created <fg=white>{$dir}/</>");
            } else {
                $output->writeln("    <fg=gray>›</> <fg=gray>{$dir}/ already exists</>");
            }

            if (!file_exists($readmePath)) {
                file_put_contents($readmePath, $readme . "\n");
            }
        }
    }

    /**
     * Get list of directories in the repo
     */
    protected function getDirectories(string $repo_dir): array
    {
        $directories = [];
        if ($repo_dir === '' || !is_dir($repo_dir)) {
            return $directories;
        }
        $items = scandir($repo_dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.git') {
                continue;
            }

            $fullPath = rtrim($repo_dir, '/') . '/' . $item;
            if (is_dir($fullPath)) {
                $directories[] = $item;
            }
        }

        return $directories;
    }

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
        
        // Add config volume to docker-compose.yml
        $this->addConfigVolumeToDockerCompose($repo_dir, $foldername, $output);
        
        $output->writeln('<info>✓ Configuration repository initialized</info>');
    }

    /**
     * Add configuration volume to docker-compose.yml
     *
     * @param string $repo_dir
     * @param string $configFolderName
     * @param OutputInterface $output
     * @return void
     */
    protected function addConfigVolumeToDockerCompose(string $repo_dir, string $configFolderName, OutputInterface $output): void
    {
        $dockerComposePath = rtrim($repo_dir, '/') . '/docker-compose.yml';
        
        if (!file_exists($dockerComposePath)) {
            return;
        }

        $content = file_get_contents($dockerComposePath);
        
        // Check if config volume already exists
        if (strpos($content, $configFolderName) !== false) {
            return;
        }

        // Find the volumes section and add the config volume
        $configVolumeLine = "      - \"../{$configFolderName}/:/var/www/{$configFolderName}:rw\"";
        
        // Look for the volumes section with the main app volume
        if (preg_match('/(    volumes:\s*\n\s*- "\.:.+?:rw")/s', $content, $matches)) {
            $replacement = $matches[1] . "\n" . $configVolumeLine;
            $content = str_replace($matches[1], $replacement, $content);
            
            file_put_contents($dockerComposePath, $content);
            $output->writeln("  <info>✓</info> Added config volume to docker-compose.yml");
        }
    }
}
