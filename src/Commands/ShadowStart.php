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
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\StageRunner;
use Gitcd\Utils\Json;

Class ShadowStart extends Command {

    use LockableTrait;

    protected static $defaultName = 'shadow:start';
    protected static $defaultDescription = 'Promote the shadow version to production (swap ports)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Swaps port mappings so the shadow version receives production traffic
            (ports 80/443) and the previously active version is demoted.

            Since the Docker image is already built, this operation completes in
            about one second. The old version remains in standby for instant rollback.

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

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $runner = new StageRunner($output);

        $activeVersion = null;
        $shadowVersion = null;

        // ── Stage 1: Validate ────────────────────────────────────
        $runner->run('Validating shadow state', function() use ($repo_dir, &$activeVersion, &$shadowVersion) {
            if (!BlueGreen::isEnabled($repo_dir)) {
                throw new \RuntimeException('Shadow deployment is not enabled. Run: protocol shadow:init');
            }

            $shadowVersion = BlueGreen::getShadowVersion($repo_dir);
            if (!$shadowVersion) {
                throw new \RuntimeException('No shadow version found. Build first: protocol shadow:build <version>');
            }

            $state = BlueGreen::getReleaseState($repo_dir, $shadowVersion);
            $status = $state['status'] ?? 'unknown';
            if ($status !== 'ready') {
                throw new \RuntimeException(
                    "Shadow version ({$shadowVersion}) is not ready. Current status: {$status}\n" .
                    "Build first: protocol shadow:build {$shadowVersion}"
                );
            }

            $shadowDir = BlueGreen::getReleaseDir($repo_dir, $shadowVersion);
            if (!is_dir($shadowDir)) {
                throw new \RuntimeException("Shadow version not found on disk: {$shadowVersion}/");
            }

            $activeVersion = BlueGreen::getActiveVersion($repo_dir);
        });

        // ── Stage 2: Promote shadow version ──────────────────────
        $runner->run("Promoting {$shadowVersion}", function() use ($repo_dir, $shadowVersion) {
            $promoted = BlueGreen::promote($repo_dir, $shadowVersion);
            if (!$promoted) {
                throw new \RuntimeException('Promotion failed — original version restored');
            }
        });

        // ── Stage 3: Verify ─────────────────────────────────────
        $runner->run('Verifying production health', function() use ($repo_dir, $shadowVersion) {
            $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
            if (!empty($healthChecks)) {
                if (!BlueGreen::runHealthChecks($repo_dir, BlueGreen::PRODUCTION_HTTP, $healthChecks, $shadowVersion)) {
                    throw new \RuntimeException('Post-promote health check failed. Run: protocol shadow:rollback');
                }
            }
        }, 'PASS');

        // ── Stage 4: Audit log ───────────────────────────────────
        $runner->run('Logging deployment', function() use ($repo_dir, $shadowVersion, $activeVersion) {
            AuditLog::logShadow($repo_dir, 'promote', $shadowVersion, $shadowVersion);
            AuditLog::logDeploy($repo_dir, $activeVersion ?: 'none', $shadowVersion, 'success', 'shadow-promote');
        });

        // ── Summary ─────────────────────────────────────────────
        $runner->writeSummary();

        $output->writeln('');
        $output->writeln("  <info>Promoted.</info> {$shadowVersion} is now serving on port " . BlueGreen::PRODUCTION_HTTP);
        if ($activeVersion) {
            $output->writeln("  Previous version ({$activeVersion}) on standby for instant rollback.");
        }
        $output->writeln('  Rollback: <comment>protocol shadow:rollback</comment>');

        return Command::SUCCESS;
    }
}
