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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\BlueGreen;
use Gitcd\Helpers\AuditLog;
use Gitcd\Helpers\StageRunner;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ShadowBuild extends Command {

    use LockableTrait;

    protected static $defaultName = 'shadow:build';
    protected static $defaultDescription = 'Build a release version in a shadow release directory';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Creates a new release directory (<project>-releases/<version>/),
            clones the repo, checks out the specified tag, builds Docker
            containers, and runs health checks — all on shadow ports (8080/8443)
            so production traffic is unaffected.

            This is the slow step. Once complete, run shadow:start to promote
            the shadow version to production in under a second.

            HELP)
        ;
        $this
            ->addArgument('version', InputArgument::REQUIRED, 'Version tag to build (e.g., v1.2.3)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('skip-health-check', null, InputOption::VALUE_NONE, 'Skip health checks after build')
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

        $version = $input->getArgument('version');
        $skipHealth = $input->getOption('skip-health-check');

        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $runner = new StageRunner($output);

        $gitRemote = null;

        // ── Stage 1: Validate ────────────────────────────────────
        $runner->run('Validating configuration', function() use ($repo_dir, $version, &$gitRemote) {
            if (!BlueGreen::isEnabled($repo_dir)) {
                throw new \RuntimeException('Shadow deployment is not enabled. Run: protocol shadow:init');
            }

            $gitRemote = BlueGreen::getGitRemote($repo_dir);
            if (!$gitRemote) {
                throw new \RuntimeException('No git remote configured. Set bluegreen.git_remote in protocol.json');
            }

            if (!GitHub::tagExists($version, $repo_dir)) {
                $releases = GitHub::listReleases($repo_dir);
                $found = false;
                foreach ($releases as $release) {
                    if (($release['tagName'] ?? '') === $version) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw new \RuntimeException("Tag {$version} not found. Create it first: protocol release:create {$version}");
                }
            }

            // Check if this version is already the active version
            $activeVersion = BlueGreen::getActiveVersion($repo_dir);
            if ($activeVersion === $version) {
                throw new \RuntimeException("{$version} is already the active version.");
            }
        });

        $releaseDir = BlueGreen::getReleaseDir($repo_dir, $version);
        $releasesBase = BlueGreen::getReleasesDir($repo_dir);

        // ── Stage 2: Initialize release directory ────────────────
        $runner->run("Cloning into {$version}/", function() use ($repo_dir, $version, $gitRemote) {
            if (!BlueGreen::initReleaseDir($repo_dir, $version, $gitRemote)) {
                throw new \RuntimeException("Failed to clone into {$version}/");
            }
        });

        // ── Stage 3: Checkout version ────────────────────────────
        $runner->run("Checking out {$version}", function() use ($releaseDir, $version) {
            if (!BlueGreen::checkoutVersion($releaseDir, $version)) {
                throw new \RuntimeException("Failed to checkout {$version}");
            }
        });

        // ── Stage 4: Patch compose file ──────────────────────────
        $runner->run('Patching docker-compose.yml', function() use ($releaseDir) {
            BlueGreen::patchComposeFile($releaseDir);
        });

        // ── Stage 5: Write shadow env ────────────────────────────
        $runner->run('Configuring shadow ports', function() use ($releaseDir, $version) {
            BlueGreen::writeReleaseEnv($releaseDir, BlueGreen::SHADOW_HTTP, BlueGreen::SHADOW_HTTPS, $version);
        });

        // ── Stage 6: Build containers (slow) ─────────────────────
        $runner->run('Building containers', function() use ($releaseDir) {
            if (!BlueGreen::buildContainers($releaseDir)) {
                throw new \RuntimeException('Docker build failed');
            }
        });

        // ── Stage 7: Health checks ───────────────────────────────
        if (!$skipHealth) {
            $runner->run('Running health checks', function() use ($repo_dir, $version) {
                $healthChecks = Json::read('bluegreen.health_checks', [], $repo_dir);
                if (!BlueGreen::runHealthChecks($repo_dir, BlueGreen::SHADOW_HTTP, $healthChecks, $version)) {
                    throw new \RuntimeException('Health checks failed on shadow port ' . BlueGreen::SHADOW_HTTP);
                }
            }, 'PASS');
        }

        // ── Stage 8: Update state ────────────────────────────────
        $runner->run('Updating deployment state', function() use ($repo_dir, $version) {
            BlueGreen::setReleaseState($repo_dir, $version, BlueGreen::SHADOW_HTTP, 'ready');
            BlueGreen::setShadowVersion($repo_dir, $version);
            AuditLog::logShadow($repo_dir, 'build', $version, $version);
        });

        // ── Summary ─────────────────────────────────────────────
        $runner->writeSummary();

        $output->writeln('');
        $output->writeln("  <info>Shadow build complete.</info> {$version} running on port " . BlueGreen::SHADOW_HTTP);
        $output->writeln("  Releases dir: <comment>{$releasesBase}</comment>");
        $output->writeln('  Promote to production: <comment>protocol shadow:start</comment>');

        return Command::SUCCESS;
    }
}
