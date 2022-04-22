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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Git;
use Gitcd\Helpers\Config;
use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class ConfigLink extends Command {

    protected static $defaultName = 'config:link';
    protected static $defaultDescription = 'Create symlinks for the configurations into the application dir';

    protected function configure(): void
    {
        // ...
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            This command will create symlinks for the configuration files into the application directory.

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
        // make sure we're in the application repo
        $repo_dir = Git::getGitLocalFolder();
        if (!$repo_dir) {
            $output->writeln("<error>This command must be run in the application repo.</error>");
            return Command::SUCCESS;
        }
        // make sure the config repo is initialized
        $configrepo = Json::read('configuration.local', false);
        if (!$configrepo) {
            $output->writeln("<error>Please run `protocol config:init` before using this command.</error>");
            return Command::SUCCESS;
        }
        $configrepo = Dir::realpath($repo_dir.$configrepo);
        $working_dir = WORKING_DIR;
        $ignored = ['.gitignore', 'README.md', '.git'];
        $configfiles = Dir::dirToArray($configrepo, $ignored);

        foreach($configfiles as $sourcepath) 
        {
            if (is_dir($sourcepath)) continue;
            if ($sourcepath=="/Users/jonathonbyrdziak/Sites/merchantprotocol/matomo-identity-resolution-config/.env")continue;

            $fulllink = str_replace($configrepo, $repo_dir, $sourcepath);
            $linkdir = dirname($fulllink).DIRECTORY_SEPARATOR;

            $filename = basename($sourcepath);
            $linkpath = str_replace(dirname($configrepo).DIRECTORY_SEPARATOR, '', $sourcepath);
            $dirpath = str_replace($filename, '',  $linkpath);
            $relpath = Dir::dirDepthToElipsis( $dirpath ).$dirpath.$filename;

            if (!is_dir($linkdir)) {
                Shell::run("mkdir -p '$linkdir'");
            }
            $linkcmd = "cd $linkdir && ln -s '$relpath' '$filename' && cd $working_dir";
            Shell::run($linkcmd);
        }
        JsonLock::write('configuration.symlinks', $configfiles);

        $environment = Config::read('env', false);
        JsonLock::write('configuration.active', $environment);
        JsonLock::save();

        $output->writeln("<info>Done creating symlinks</info>");
        return Command::SUCCESS;
    }

}