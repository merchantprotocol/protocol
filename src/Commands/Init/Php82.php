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

class Php82 extends BaseInitializer
{
    public function getName(): string
    {
        return 'PHP 8.2';
    }

    public function getDescription(): string
    {
        return 'Nginx + PHP 8.2 FPM — SOC2 compliant, ModSecurity WAF';
    }

    public function getDockerImage(): string
    {
        return 'byrdziak/merchantprotocol-webserver-nginx-php8.2-fpm:latest';
    }

    public function getGitHubRepo(): string
    {
        return 'https://github.com/merchantprotocol/docker-nginx-php8.2-fpm';
    }

    public function getTemplateDir(): string
    {
        return __DIR__ . '/Php82';
    }

    protected function initializeProject(string $repo_dir, InputInterface $input, OutputInterface $output, $helper): void
    {
        $this->generateDockerCompose($repo_dir, $input, $output, $helper);
    }
}
