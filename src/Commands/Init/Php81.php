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
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

class Php81 extends BaseInitializer
{
    /**
     * Get the display name for this project type
     *
     * @return string
     */
    public function getName(): string
    {
        return 'PHP 8.1';
    }

    /**
     * Get the description for this project type
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'PHP 8.1 with Nginx web server';
    }

    /**
     * Project-specific initialization logic
     *
     * @param string $repo_dir
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $helper
     * @return void
     */
    protected function initializeProject(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void
    {
        // Create required directories
        $this->createDirectories($repo_dir, $output);

        // Ask for web root directory
        $webRoot = $this->askForWebRoot($repo_dir, $input, $output, $helper);

        // Copy nginx configuration files
        $this->copyNginxConfigs($repo_dir, $output);

        // Update nginx-ssl.conf with the selected web root
        $this->updateNginxWebRoot($repo_dir, $webRoot, $output);

        // Copy docker-compose.yml
        $this->copyDockerCompose($repo_dir, $output);
    }

    /**
     * Get the template directory path for this initializer
     *
     * @return string
     */
    public function getTemplateDir(): string
    {
        return __DIR__ . '/Php81';
    }

    /**
     * Create required directories
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return void
     */
    protected function createDirectories(string $repo_dir, OutputInterface $output): void
    {
        $this->createDirectory($repo_dir, 'nginx.d', $output);
        $this->createDirectory($repo_dir, 'cron.d', $output);
    }

    /**
     * Copy nginx configuration files
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return void
     */
    protected function copyNginxConfigs(string $repo_dir, OutputInterface $output): void
    {
        $templateDir = $this->getTemplateDir() . '/nginx.d';
        $targetDir = rtrim($repo_dir, '/') . '/nginx.d';

        $files = [
            'nginx.conf',
            'nginx-ssl.conf',
            'php-fpm.conf',
            'php.ini'
        ];

        $this->copyFiles($templateDir, $targetDir, $files, 'nginx.d', $output);
    }

    /**
     * Copy docker-compose.yml template
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return void
     */
    protected function copyDockerCompose(string $repo_dir, OutputInterface $output): void
    {
        $source = $this->getTemplateDir() . '/docker-compose.yml';
        $destination = rtrim($repo_dir, '/') . '/docker-compose.yml';

        $this->copyFile($source, $destination, 'docker-compose.yml', $output);
    }

    /**
     * Ask user for web root directory
     *
     * @param string $repo_dir
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param mixed $helper
     * @return string
     */
    protected function askForWebRoot(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): string
    {
        $output->writeln('');
        $output->writeln('<comment>Configuring web root directory...</comment>');

        // Get list of directories in the repo
        $directories = $this->getDirectories($repo_dir);
        
        // Add common options
        $commonOptions = [
            'public',
            'public_html',
            'web',
            'www',
            'html',
            '.' // Current directory
        ];

        // Merge and deduplicate
        $allOptions = array_unique(array_merge($commonOptions, $directories));
        
        // Create choices array with keys
        $choices = [];
        foreach ($allOptions as $dir) {
            $choices[$dir] = $dir === '.' ? 'Current directory (/)' : $dir;
        }

        $question = new ChoiceQuestion(
            'Where is your website root directory?',
            $choices,
            'public' // default
        );
        $question->setErrorMessage('Directory %s is invalid.');

        $selectedAnswer = $helper->ask($input, $output, $question);
        
        // Find the key from the selected answer
        $webRoot = array_search($selectedAnswer, $choices);
        if ($webRoot === false) {
            $webRoot = $selectedAnswer;
        }

        // Allow manual entry if not in list
        if (!in_array($webRoot, $allOptions)) {
            $question = new Question('Enter the web root directory path: ', 'public');
            $webRoot = $helper->ask($input, $output, $question);
        }

        $output->writeln("  <info>✓</info> Web root set to: <comment>$webRoot</comment>");

        return $webRoot === '.' ? '' : $webRoot;
    }

    /**
     * Get list of directories in the repo
     *
     * @param string $repo_dir
     * @return array
     */
    protected function getDirectories(string $repo_dir): array
    {
        $directories = [];
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
     * Update nginx-ssl.conf with the web root
     *
     * @param string $repo_dir
     * @param string $webRoot
     * @param OutputInterface $output
     * @return void
     */
    protected function updateNginxWebRoot(string $repo_dir, string $webRoot, OutputInterface $output): void
    {
        $nginxSslPath = rtrim($repo_dir, '/') . '/nginx.d/nginx-ssl.conf';
        
        if (!file_exists($nginxSslPath)) {
            return;
        }

        $content = file_get_contents($nginxSslPath);
        
        // Build the new root path
        $newRoot = '/var/www/html' . ($webRoot ? '/' . $webRoot : '');
        
        // Replace the root directive
        $content = preg_replace(
            '/root\s+\/var\/www\/html\/public;/',
            "root $newRoot;",
            $content
        );
        
        file_put_contents($nginxSslPath, $content);
        $output->writeln("  <info>✓</info> Updated nginx web root to: <comment>$newRoot</comment>");
    }
}
