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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Str;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Docker;
use Gitcd\Utils\Json;

Class ReleaseChangelog extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'release:changelog';
    protected static $defaultDescription = 'Creates a CHANGLOG.md file for your app';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command will use github-changelog-generator to create a changelog that is stored in a CHANGELOG.md file in your apps dir.

            HELP)
        ;
        $this
            // configure an argument
            ->addOption('dir', 'd', InputOption::VALUE_OPTIONAL, 'Directory Path', Git::getGitLocalFolder())
            // ...
        ;
    }

    /**
        Usage: github_changelog_generator --user USER --project PROJECT [options]
            -u, --user USER                  Username of the owner of the target GitHub repo OR the namespace of target Github repo if owned by an organization.
            -p, --project PROJECT            Name of project on GitHub.
            -t, --token TOKEN                To make more than 50 requests per hour your GitHub token is required. You can generate it at: https://github.com/settings/tokens/new
            -f, --date-format FORMAT         Date format. Default is %Y-%m-%d.
            -o, --output NAME                Output file. To print to STDOUT instead, use blank as path. Default is CHANGELOG.md
            -b, --base NAME                  Optional base file to append generated changes to. Default is HISTORY.md
                --summary-label LABEL        Set up custom label for the release summary section. Default is "".
                --breaking-label LABEL       Set up custom label for the breaking changes section. Default is "**Breaking changes:**".
                --enhancement-label LABEL    Set up custom label for enhancements section. Default is "**Implemented enhancements:**".
                --bugs-label LABEL           Set up custom label for bug-fixes section. Default is "**Fixed bugs:**".
                --deprecated-label LABEL     Set up custom label for the deprecated changes section. Default is "**Deprecated:**".
                --removed-label LABEL        Set up custom label for the removed changes section. Default is "**Removed:**".
                --security-label LABEL       Set up custom label for the security changes section. Default is "**Security fixes:**".
                --issues-label LABEL         Set up custom label for closed-issues section. Default is "**Closed issues:**".
                --header-label LABEL         Set up custom header label. Default is "# Changelog".
                --configure-sections HASH, STRING
                                            Define your own set of sections which overrides all default sections.
                --add-sections HASH, STRING  Add new sections but keep the default sections.
                --front-matter JSON          Add YAML front matter. Formatted as JSON because it's easier to add on the command line.
                --pr-label LABEL             Set up custom label for pull requests section. Default is "**Merged pull requests:**".
                --[no-]issues                Include closed issues in changelog. Default is true.
                --[no-]issues-wo-labels      Include closed issues without labels in changelog. Default is true.
                --[no-]pr-wo-labels          Include pull requests without labels in changelog. Default is true.
                --[no-]pull-requests         Include pull-requests in changelog. Default is true.
                --[no-]filter-by-milestone   Use milestone to detect when issue was resolved. Default is true.
                --[no-]issues-of-open-milestones
                                            Include issues of open milestones. Default is true.
                --[no-]author                Add author of pull request at the end. Default is true.
                --usernames-as-github-logins Use GitHub tags instead of Markdown links for the author of an issue or pull-request.
                --unreleased-only            Generate log from unreleased closed issues only.
                --[no-]unreleased            Add to log unreleased closed issues. Default is true.
                --unreleased-label LABEL     Set up custom label for unreleased closed issues section. Default is "**Unreleased:**".
                --[no-]compare-link          Include compare link (Full Changelog) between older version and newer version. Default is true.
                --include-labels  x,y,z      Of the labeled issues, only include the ones with the specified labels.
                --exclude-labels  x,y,z      Issues with the specified labels will be excluded from changelog. Default is 'duplicate,question,invalid,wontfix'.
                --summary-labels x,y,z       Issues with these labels will be added to a new section, called "Release Summary". The section display only body of issues. Default is 'release-summary,summary'.
                --breaking-labels x,y,z      Issues with these labels will be added to a new section, called "Breaking changes". Default is 'backwards-incompatible,breaking'.
                --enhancement-labels  x,y,z  Issues with the specified labels will be added to "Implemented enhancements" section. Default is 'enhancement,Enhancement'.
                --bug-labels  x,y,z          Issues with the specified labels will be added to "Fixed bugs" section. Default is 'bug,Bug'.
                --deprecated-labels x,y,z    Issues with the specified labels will be added to a section called "Deprecated". Default is 'deprecated,Deprecated'.
                --removed-labels x,y,z       Issues with the specified labels will be added to a section called "Removed". Default is 'removed,Removed'.
                --security-labels x,y,z      Issues with the specified labels will be added to a section called "Security fixes". Default is 'security,Security'.
                --issue-line-labels x,y,z    The specified labels will be shown in brackets next to each matching issue. Use "ALL" to show all labels. Default is [].
                --include-tags-regex REGEX   Apply a regular expression on tag names so that they can be included, for example: --include-tags-regex ".*+d{1,}".
                --exclude-tags  x,y,z        Changelog will exclude specified tags
                --exclude-tags-regex REGEX   Apply a regular expression on tag names so that they can be excluded, for example: --exclude-tags-regex ".*+d{1,}".
                --since-tag  x               Changelog will start after specified tag.
                --due-tag  x                 Changelog will end before specified tag.
                --since-commit  x            Fetch only commits after this time. eg. "2017-01-01 10:00:00"
                --max-issues NUMBER          Maximum number of issues to fetch from GitHub. Default is unlimited.
                --release-url URL            The URL to point to for release links, in printf format (with the tag as variable).
                --github-site URL            The Enterprise GitHub site where your project is hosted.
                --github-api URL             The enterprise endpoint to use for your GitHub API.
                --simple-list                Create a simple list from issues and pull requests. Default is false.
                --future-release RELEASE-VERSION
                                            Put the unreleased changes in the specified release number.
                --release-branch RELEASE-BRANCH
                                            Limit pull requests to the release branch, such as master or release.
                --[no-]http-cache            Use HTTP Cache to cache GitHub API requests (useful for large repos). Default is true.
                --cache-file CACHE-FILE      Filename to use for cache. Default is github-changelog-http-cache in a temporary directory.
                --cache-log CACHE-LOG        Filename to use for cache log. Default is github-changelog-logger.log in a temporary directory.
                --config-file CONFIG-FILE    Path to configuration file. Default is .github_changelog_generator.
                --ssl-ca-file PATH           Path to cacert.pem file. Default is a bundled lib/github_changelog_generator/ssl_certs/cacert.pem. Respects SSL_CA_PATH.
                --require x,y,z              Path to Ruby file(s) to require before generating changelog.
                --[no-]verbose               Run verbosely. Default is true.
            -v, --version                    Print version number.
            -h, --help                       Displays Help.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo_dir = Dir::realpath($input->getOption('dir'));
        Git::checkInitializedRepo( $output, $repo_dir );

        // make sure we're in the application repo
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        $helper = $this->getHelper('question');

        $remoteurl = Git::RemoteUrl( $repo_dir );
        $cleanurl = str_replace(['git@github.com:', '.git'], '', $remoteurl);
        list($user, $project) = explode('/', $cleanurl);

        // make sure we have a token
        $token = Json::read('git.token', false, $repo_dir);
        if (!$token) {
            $generateTokenUrl = 'https://github.com/settings/tokens/new?description=protocol-cli-tool&scopes=repo';
            $question = new Question("You need to a github personal access token to access your repo, go here to create one. <info>$generateTokenUrl</info>. Enter your Token:", '');
            $token = $helper->ask($input, $output, $question);
            Json::write('git.token', $token, $repo_dir);
            Json::save($repo_dir);
        }

        // push tags
        $command = "git -C $repo_dir push --tags";
        $response = Shell::run($command);

        // create the changelog
        $command = "docker run -it --rm -v \"$repo_dir\":\"/usr/local/src/your-app/\" githubchangeloggenerator/github-changelog-generator"
                ." --token $token"
                ." --user $user"
                ." --project $project"
                ." --no-pull-requests"
                ." --unreleased-label 'PRERELEASE'"
                ." --unreleased-only"
                ;
        $response = Shell::run($command, $failed);

        // remove the advertisement
        $command = "sed -i.bak '/github_changelog_generator/d' '{$repo_dir}CHANGELOG.md'";
        $response = Shell::run($command);
        // remove sed backup file
        unlink("{$repo_dir}CHANGELOG.md.bak");

        $output->writeln("<info>Changlog created.</info>");

        return Command::SUCCESS;
    }

}