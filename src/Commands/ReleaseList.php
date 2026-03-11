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
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Utils\JsonLock;

Class ReleaseList extends Command {

    protected static $defaultName = 'release:list';
    protected static $defaultDescription = 'List available releases for this repository';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Lists releases from GitHub (or local tags as fallback).
            Shows which release is currently deployed and active.

            HELP)
        ;
        $this
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

        $currentRelease = JsonLock::read('release.current', null, $repo_dir);
        $activePointer = GitHub::getVariable('PROTOCOL_ACTIVE_RELEASE', $repo_dir);

        // Try GitHub releases first, fall back to local tags
        $releases = GitHub::listReleases($repo_dir);

        if (!empty($releases)) {
            $rows = [];
            foreach ($releases as $release) {
                $tag = $release['tagName'] ?? '';
                $name = $release['name'] ?? '';
                $date = isset($release['publishedAt']) ? date('Y-m-d', strtotime($release['publishedAt'])) : '';
                $status = [];

                if ($tag === $currentRelease) $status[] = '<info>DEPLOYED</info>';
                if ($tag === $activePointer) $status[] = '<comment>ACTIVE</comment>';
                if (!empty($release['isDraft'])) $status[] = '<comment>DRAFT</comment>';
                if (!empty($release['isPrerelease'])) $status[] = '<comment>PRE</comment>';

                $rows[] = [$tag, $name, $date, implode(' ', $status)];
            }

            $table = new Table($output);
            $table->setHeaders(['Tag', 'Title', 'Date', 'Status']);
            $table->setRows($rows);
            $table->render();
        } else {
            // Fall back to local tags
            $tags = GitHub::getTags($repo_dir);
            if (empty($tags)) {
                $output->writeln('<comment>No releases or tags found.</comment>');
                $output->writeln('Create one with: <info>protocol release:create</info>');
                return Command::SUCCESS;
            }

            $output->writeln('<comment>Showing local tags (GitHub releases not available):</comment>');
            $output->writeln('');
            foreach ($tags as $tag) {
                $marker = '';
                if ($tag === $currentRelease) $marker .= ' <info>[DEPLOYED]</info>';
                if ($tag === $activePointer) $marker .= ' <comment>[ACTIVE]</comment>';
                $output->writeln("  {$tag}{$marker}");
            }
        }

        $output->writeln('');
        if ($activePointer) {
            $output->writeln("Active pointer: <info>{$activePointer}</info>");
        }

        return Command::SUCCESS;
    }
}
