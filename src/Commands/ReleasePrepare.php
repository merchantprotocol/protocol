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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Release;
use Gitcd\Utils\Json;

Class ReleasePrepare extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'release:prepare';
    protected static $defaultDescription = 'Prepares the codebase for the next release';

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
            // ...
        ;
    }

    /**
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $generateTokenUrl = 'https://github.com/settings/tokens/new?description=protocol-cli-tool&scopes=repo';

        // make sure we're in the application repo
        $repo_dir = $input->getOption('dir');
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }

        // loading the organization and repository name
        $remoteurl = Git::RemoteUrl( $repo_dir );
        $cleanurl = str_replace(['git@github.com:', '.git'], '', $remoteurl);
        list($user, $repo) = explode('/', $cleanurl);

        // loading the username and token
        $token = Json::read('git.token', false, $repo_dir);
        // If we didn't find a token, ask for it
        if (!$token) {
            $question = new Question("You need to create a github personal access token to access your repo, go here to create one. <info>$generateTokenUrl</info>. Enter your Token: ", '');
            $token = $helper->ask($input, $output, $question);
            Json::write('git.token', $token, $repo_dir);
            Json::save($repo_dir);
        }

        $client = new \Github\Client();
        try {
            $client->authenticate($token, \Github\AuthMethod::ACCESS_TOKEN);
            $releases = $client->api('repo')->releases()->all($user, $repo);

        // If the token/username don't match, then ask for the real username
        } catch (\Exception $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            if ($e->getMessage() == 'Bad credentials') {
                $output->writeln("<error>Verify the token inside of protocol.json and try again. Exiting...</error>");
                $output->writeln("You may need to create another github personal access token to access your repo, go here to create one. <info>$generateTokenUrl</info>.");
            }
            return Command::SUCCESS;
        }

        // Update the CHANGELOG
        $command = $this->getApplication()->find('release:changelog');
        $command->run((new ArrayInput(['--dir' => $repo_dir])), $output);
        $parsedChangelog = Release::parseChangelog( "{$repo_dir}CHANGELOG.md" );

        // Figure out what the current release structure is
        $draft = false;
        $lastReleaseTag = '0.0.0';
        $lastRelease = array_shift($releases);
        if (empty( $lastRelease )) {
            // there are no releases
            // $lastReleaseTag = '0.0.0';
        } else {
            if (!array_key_exists('tag_name', $lastRelease) || !$lastRelease['tag_name']) {
                // This is a draft release
                $draft = $lastRelease;
                $lastRelease = array_shift($releases);
                if (!$lastRelease['tag_name']) {
                    // $lastReleaseTag = '0.0.0';
                }
            }
            if (array_key_exists('tag_name', $lastRelease) && $lastRelease['tag_name']) {
                $lastReleaseTag = $lastRelease['tag_name'];
            }
        }

        




        return Command::SUCCESS;
    }

}