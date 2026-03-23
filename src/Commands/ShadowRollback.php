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

Class ShadowRollback extends Command {

    use LockableTrait;

    protected static $defaultName = 'shadow:rollback';
    protected static $defaultDescription = 'Roll back to the previous version (instant)';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Promotes the previous version back to production. Since the previous
            version's containers are already built and cached on disk, this is
            a near-instant port swap (~1 second).

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
        $previousVersion = null;

        // ── Stage 1: Validate ────────────────────────────────────
        $runner->run('Validating rollback state', function() use ($repo_dir, &$activeVersion, &$previousVersion) {
            if (!BlueGreen::isEnabled($repo_dir)) {
                throw new \RuntimeException('Shadow deployment is not enabled. Run: protocol shadow:init');
            }

            $activeVersion = BlueGreen::getActiveVersion($repo_dir);
            $previousVersion = BlueGreen::getPreviousVersion($repo_dir);

            if (!$previousVersion) {
                throw new \RuntimeException('No previous version available for rollback.');
            }

            $prevDir = BlueGreen::getReleaseDir($repo_dir, $previousVersion);
            if (!is_dir($prevDir)) {
                throw new \RuntimeException("Previous version not found on disk: {$previousVersion}/");
            }

            $state = BlueGreen::getReleaseState($repo_dir, $previousVersion);
            $status = $state['status'] ?? 'unknown';
            if ($status !== 'standby') {
                throw new \RuntimeException(
                    "Previous version ({$previousVersion}) has status: {$status}\n" .
                    "Rollback requires 'standby' status."
                );
            }
        });

        // ── Stage 2: Promote previous version ────────────────────
        $runner->run("Rolling back to {$previousVersion}", function() use ($repo_dir, $previousVersion) {
            $promoted = BlueGreen::promote($repo_dir, $previousVersion);
            if (!$promoted) {
                throw new \RuntimeException('Rollback failed');
            }
        });

        // ── Stage 3: Verify ─────────────────────────────────────
        $runner->run('Verifying production health', function() use ($repo_dir, $previousVersion) {
            $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
            if (!empty($healthChecks)) {
                if (!BlueGreen::runHealthChecks($repo_dir, BlueGreen::PRODUCTION_HTTP, $healthChecks, $previousVersion)) {
                    throw new \RuntimeException('Post-rollback health check failed');
                }
            }
        }, 'PASS');

        // ── Stage 4: Audit log ───────────────────────────────────
        $runner->run('Logging rollback', function() use ($repo_dir, $activeVersion, $previousVersion) {
            AuditLog::logShadow($repo_dir, 'rollback', $previousVersion, $previousVersion);
            AuditLog::logRollback($repo_dir, $activeVersion, $previousVersion, 'success', 'shadow');
        });

        // ── Summary ─────────────────────────────────────────────
        $runner->writeSummary();

        $output->writeln('');
        $output->writeln("  <info>Rolled back.</info> {$previousVersion} is now serving.");
        $output->writeln("  Rolled back from {$activeVersion}.");

        return Command::SUCCESS;
    }
}
