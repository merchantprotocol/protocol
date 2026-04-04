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
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\GitHub;
use Gitcd\Helpers\Release;

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
            $version = Release::autoVersion($repo_dir, $bump);

            if (!$version) {
                $output->writeln('<error>No existing tags found. Please specify a version: protocol release:create v1.0.0</error>');
                return Command::FAILURE;
            }
            $output->writeln("<comment>Auto-bumped {$bump} version: {$version}</comment>");
        }

        $version = Release::normalizeVersion($version);
        if (!$version) {
            $output->writeln('<error>Invalid version format. Use semver: v1.2.3</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Creating release {$version}</info>");
        $output->writeln('');

        // Check clean working tree
        $status = trim(Shell::run("git -C " . escapeshellarg($repo_dir) . " status --porcelain 2>/dev/null"));
        if ($status) {
            $output->writeln('<error>Working tree is not clean. Commit your changes before creating a release.</error>');
            $output->writeln('');
            $output->writeln($status);
            return Command::FAILURE;
        }

        // Fetch remote and check if local is behind
        $remote = Git::remoteName($repo_dir) ?: 'origin';
        $branch = Git::branch($repo_dir);
        Shell::run("git -C " . escapeshellarg($repo_dir) . " fetch " . escapeshellarg($remote) . " 2>&1");

        $behind = (int) trim(Shell::run(
            "git -C " . escapeshellarg($repo_dir)
            . " rev-list --count HEAD.." . escapeshellarg("{$remote}/{$branch}") . " 2>/dev/null"
        ));
        if ($behind > 0) {
            $output->writeln("<error>Local branch '{$branch}' is {$behind} commit(s) behind {$remote}/{$branch}.</error>");
            $output->writeln('<error>Pull the latest changes before creating a release.</error>');
            return Command::FAILURE;
        }

        $ahead = (int) trim(Shell::run(
            "git -C " . escapeshellarg($repo_dir)
            . " rev-list --count " . escapeshellarg("{$remote}/{$branch}") . "..HEAD 2>/dev/null"
        ));
        if ($ahead > 0) {
            $output->writeln("<comment>Local branch is {$ahead} commit(s) ahead — these will be pushed with the release.</comment>");
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
            // Push branch first — abort entirely if rejected
            $branchPushResult = Shell::run(
                "git -C " . escapeshellarg($repo_dir) . " push " . escapeshellarg($remote) . " " . escapeshellarg($branch) . " 2>&1",
                $branchPushExit
            );
            if ($branchPushExit !== 0) {
                $output->writeln('<error>Branch push rejected. Removing local tag and undoing VERSION commit.</error>');
                $output->writeln($branchPushResult);
                // Rollback: delete local tag and undo the VERSION commit
                Shell::run("git -C " . escapeshellarg($repo_dir) . " tag -d " . escapeshellarg($version) . " 2>/dev/null");
                Shell::run("git -C " . escapeshellarg($repo_dir) . " reset --soft HEAD~1 2>/dev/null");
                Shell::run("git -C " . escapeshellarg($repo_dir) . " checkout -- VERSION 2>/dev/null");
                $output->writeln('<comment>Rolled back local tag and VERSION commit. Pull the latest changes and try again.</comment>');
                return Command::FAILURE;
            }
            $output->writeln(" - Pushed branch to {$remote}");

            // Push tag
            $tagPushResult = Shell::run(
                "git -C " . escapeshellarg($repo_dir) . " push " . escapeshellarg($remote) . " " . escapeshellarg($version) . " 2>&1",
                $tagPushExit
            );
            if ($tagPushExit !== 0) {
                $output->writeln('<error>Tag push failed.</error>');
                $output->writeln($tagPushResult);
                return Command::FAILURE;
            }
            $output->writeln(" - Pushed tag {$version} to {$remote}");

            // Create GitHub Release
            $draft = $input->getOption('draft');
            $title = "Release {$version}";
            if (GitHub::createRelease($version, $title, $draft, $repo_dir)) {
                $output->writeln(" - Created GitHub Release" . ($draft ? ' (draft)' : ''));
            } else {
                $output->writeln("<comment> - Could not create GitHub Release (check gh auth status or GitHub App credentials)</comment>");
            }
        } else {
            $output->writeln(" - Skipped push (--no-push)");
        }

        $output->writeln('');
        $output->writeln("<info>Release {$version} created successfully!</info>");
        $output->writeln("Deploy to all nodes: <comment>protocol deploy:push {$version}</comment>");

        return Command::SUCCESS;
    }

}
