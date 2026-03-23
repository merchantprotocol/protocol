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
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\StageRunner;
use Gitcd\Utils\Json;
use Gitcd\Helpers\DeploymentState;
use Gitcd\Utils\NodeConfig;

Class ShadowStatus extends Command {

    use LockableTrait;

    protected static $defaultName = 'shadow:status';
    protected static $defaultDescription = 'Show shadow deployment status';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Displays the current state of all release versions: which is active,
            what version is on standby, port assignments, and whether containers
            are actually running.

            HELP)
        ;
        $this
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output raw JSON')
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

        if (!BlueGreen::isEnabled($repo_dir)) {
            $output->writeln('<error>Shadow deployment is not enabled.</error>');
            $output->writeln('Run: <comment>protocol shadow:init</comment>');
            return Command::FAILURE;
        }

        $activeVersion = BlueGreen::getActiveVersion($repo_dir);
        $previousVersion = BlueGreen::getPreviousVersion($repo_dir);
        $shadowVersion = BlueGreen::getShadowVersion($repo_dir);
        $project = DeploymentState::resolveProjectName($repo_dir);
        $promotedAt = $project ? NodeConfig::read($project, 'bluegreen.promoted_at') : null;
        $releases = BlueGreen::listReleases($repo_dir);
        $releasesDir = BlueGreen::getReleasesDir($repo_dir);

        if ($input->getOption('json')) {
            $data = [
                'active_version' => $activeVersion,
                'previous_version' => $previousVersion,
                'shadow_version' => $shadowVersion,
                'promoted_at' => $promotedAt,
                'releases_dir' => $releasesDir,
                'releases' => [],
            ];
            foreach ($releases as $release) {
                $state = BlueGreen::getReleaseState($repo_dir, $release);
                $data['releases'][$release] = array_merge($state, [
                    'running' => BlueGreen::isReleaseRunning($repo_dir, $state['version'] ?? $release),
                ]);
            }
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(StageRunner::isTty() ? "\033[1mShadow Deployment Status\033[0m" : 'Shadow Deployment Status');
        $output->writeln(str_repeat('-', 55));
        $output->writeln('');

        $output->writeln("  Active:   <info>" . ($activeVersion ?: 'none') . "</info>");
        if ($previousVersion) {
            $output->writeln("  Previous: <comment>{$previousVersion}</comment> (rollback available)");
        }
        if ($shadowVersion) {
            $output->writeln("  Shadow:   <comment>{$shadowVersion}</comment> (ready to promote)");
        }
        if ($promotedAt) {
            $output->writeln("  Promoted: <comment>{$promotedAt}</comment>");
        }
        $output->writeln("  Releases: <comment>{$releasesDir}</comment>");
        $output->writeln('');

        if (!empty($releases)) {
            $format = "  %-3s %-16s %-8s %-12s %-8s";
            $output->writeln(sprintf($format, '', 'Version', 'Port', 'Status', 'Running'));
            $output->writeln(sprintf($format, '', '-------', '----', '------', '-------'));

            foreach ($releases as $release) {
                $state = BlueGreen::getReleaseState($repo_dir, $release);
                $version = $state['version'] ?? $release;
                $port = $state['port'] ?? '-';
                $status = $state['status'] ?? 'unknown';
                $running = BlueGreen::isReleaseRunning($repo_dir, $version) ? 'yes' : 'no';
                $marker = ($version === $activeVersion) ? '* ' : '  ';
                $output->writeln($marker . sprintf(trim($format), '', $version, $port, $status, $running));
            }
            $output->writeln('');
        } else {
            $output->writeln('  <comment>No releases found.</comment>');
            $output->writeln('  Run: <comment>protocol shadow:build <version></comment>');
            $output->writeln('');
        }

        $autoPromote = Json::read('bluegreen.auto_promote', false, $repo_dir);
        $output->writeln('  Auto-promote: ' . ($autoPromote ? '<info>enabled</info>' : '<comment>disabled</comment>'));

        $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
        $output->writeln('  Health checks: ' . (count($healthChecks) > 0 ? count($healthChecks) . ' configured' : '<comment>none</comment>'));

        $output->writeln('');

        return Command::SUCCESS;
    }
}
