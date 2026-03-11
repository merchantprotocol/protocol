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
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\GitHub;

Class ReleaseCreate extends Command {

    use LockableTrait;

    protected static $defaultName = 'release:create';
    protected static $defaultDescription = 'Create a new release: write VERSION file, tag, push, and create GitHub Release';

    protected function configure(): void
    {
        $this
            ->setHelp(<<<HELP
            Full release workflow:
            1. Validate or auto-generate semver version
            2. Check for clean working tree
            3. Write VERSION file at repo root
            4. Commit the VERSION file
            5. Create git tag
            6. Push tag and commit to remote
            7. Create GitHub Release (with auto-generated notes)

            If no version is given, auto-bumps patch from latest tag.
            Use --major or --minor to bump those segments instead.

            HELP)
        ;
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Version to release (e.g., v1.2.3)')
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            ->addOption('major', null, InputOption::VALUE_NONE, 'Bump major version')
            ->addOption('minor', null, InputOption::VALUE_NONE, 'Bump minor version')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Create as draft release')
            ->addOption('no-push', null, InputOption::VALUE_NONE, 'Skip pushing to remote')
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

        // Determine version
        $version = $input->getArgument('version');
        if (!$version) {
            $bump = 'patch';
            if ($input->getOption('major')) $bump = 'major';
            if ($input->getOption('minor')) $bump = 'minor';
            $version = $this->autoVersion($repo_dir, $bump);

            if (!$version) {
                $output->writeln('<error>No existing tags found. Please specify a version: protocol release:create v1.0.0</error>');
                return Command::FAILURE;
            }
            $output->writeln("<comment>Auto-bumped {$bump} version: {$version}</comment>");
        }

        $version = $this->normalizeVersion($version);
        if (!$version) {
            $output->writeln('<error>Invalid version format. Use semver: v1.2.3</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Creating release {$version}</info>");
        $output->writeln('');

        // Check clean working tree
        $status = Shell::run("git -C " . escapeshellarg($repo_dir) . " status --porcelain 2>/dev/null");
        if (trim($status)) {
            $output->writeln('<error>Working tree is not clean. Commit or stash your changes first.</error>');
            return Command::FAILURE;
        }

        // Check tag doesn't exist
        if (GitHub::tagExists($version, $repo_dir)) {
            $output->writeln("<error>Tag {$version} already exists.</error>");
            return Command::FAILURE;
        }

        // Write VERSION file
        $versionNumber = ltrim($version, 'v');
        $versionFile = rtrim($repo_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'VERSION';
        file_put_contents($versionFile, $versionNumber . "\n");
        $output->writeln(" - Wrote VERSION file: {$versionNumber}");

        // Commit VERSION file
        Shell::run("git -C " . escapeshellarg($repo_dir) . " add VERSION");
        Shell::run("git -C " . escapeshellarg($repo_dir) . " commit -m " . escapeshellarg("Release {$version}"));
        $output->writeln(" - Committed VERSION file");

        // Create tag
        Shell::run("git -C " . escapeshellarg($repo_dir) . " tag -a " . escapeshellarg($version) . " -m " . escapeshellarg("Release {$version}"));
        $output->writeln(" - Created tag {$version}");

        // Push
        if (!$input->getOption('no-push')) {
            $remote = Git::remoteName($repo_dir) ?: 'origin';
            $branch = Git::branch($repo_dir);

            Shell::passthru("git -C " . escapeshellarg($repo_dir) . " push " . escapeshellarg($remote) . " " . escapeshellarg($branch));
            Shell::passthru("git -C " . escapeshellarg($repo_dir) . " push " . escapeshellarg($remote) . " " . escapeshellarg($version));
            $output->writeln(" - Pushed to {$remote}");

            // Create GitHub Release
            $draft = $input->getOption('draft');
            $title = "Release {$version}";
            if (GitHub::createRelease($version, $title, $draft, $repo_dir)) {
                $output->writeln(" - Created GitHub Release" . ($draft ? ' (draft)' : ''));
            } else {
                $output->writeln("<comment> - Could not create GitHub Release (gh CLI may not be available)</comment>");
            }
        } else {
            $output->writeln(" - Skipped push (--no-push)");
        }

        $output->writeln('');
        $output->writeln("<info>Release {$version} created successfully!</info>");
        $output->writeln("Deploy to all nodes: <comment>protocol deploy:push {$version}</comment>");

        return Command::SUCCESS;
    }

    /**
     * Normalize version string to v-prefixed semver.
     */
    protected function normalizeVersion(string $version): ?string
    {
        $version = ltrim($version, 'v');
        if (!preg_match('/^\d+\.\d+\.\d+(-[\w.]+)?$/', $version)) {
            return null;
        }
        return 'v' . $version;
    }

    /**
     * Auto-generate next version from latest tag.
     */
    protected function autoVersion(string $repo_dir, string $bump = 'patch'): ?string
    {
        $tags = GitHub::getTags($repo_dir);
        if (empty($tags)) return null;

        $latest = ltrim($tags[0], 'v');
        $parts = explode('.', $latest);
        if (count($parts) !== 3) return null;

        $major = (int) $parts[0];
        $minor = (int) $parts[1];
        $patch = (int) $parts[2];

        switch ($bump) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'patch':
            default:
                $patch++;
                break;
        }

        return "v{$major}.{$minor}.{$patch}";
    }
}
