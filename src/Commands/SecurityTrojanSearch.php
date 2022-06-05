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
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;
use Gitcd\Helpers\Config;

Class SecurityTrojanSearch extends Command {

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'security:trojansearch';
    protected static $defaultDescription = 'Find anything that looks like a trojan in our application';

    protected function configure(): void
    {
        // ...
        $this
            ->setHidden(true)
            // the command help shown when running the command with the "--help" option
            ->setHelp(<<<HELP
            We're searching for anything that can run commands on our webserver from a browser or other remote program. The more indicators a file gives that it's using these illegal functions the higher it's trojan rating.

            We encourage you to review any the files individually.

            HELP)
        ;
        $this
            // configure an argument
            ->addArgument('repo_dir', InputArgument::OPTIONAL, 'The local git directory to manage')
            // ...
        ;
    }

    /**
     * Searches the files
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return integer
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Checking for possible trojan horses</comment>');

        $repo_dir = Dir::realpath($input->getArgument('repo_dir'), Config::read('repo_dir'));

        // Building the command
        $changedwithin = "7";
        $find = "find '$repo_dir' -type f -mtime -{$changedwithin} 2>&1 | grep -v 'No such file or directory' | grep -v 'Permission denied' | grep -v 'Operation not permitted'";
        $trojanCommandsGrep = [
            'eval', 'base64_decode', 'gzinflate', 'str_rot13'
        ];
        $trojanCommandsEgrep = [
            'mail', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'exec', 'system', 'passthru', 'eval', 'base64_decode'
        ];

        // starts and displays the progress bar
        $progressBar = new ProgressBar($output, (count($trojanCommandsGrep)+count($trojanCommandsEgrep) +1));
        $progressBar->start();

        // Search the files and score them
        $files = [];
        foreach ($trojanCommandsGrep as $trojan) {
            $command = "$find | xargs grep -l \"{$trojan} *(\" --color";
            $response = Shell::run($command);

            $lines = explode(PHP_EOL, $response);
            foreach ($lines as $line) {
                if (array_key_exists($line, $files)) {
                    $files[$line]++;
                } else {
                    $files[$line] = 1;
                }
            }

            $progressBar->advance();
        }
        foreach ($trojanCommandsEgrep as $trojan) {break;
            $command = "$find | xargs egrep -i \"{$trojan} *(\" --color";
            $response = Shell::run($command);
            $progressBar->advance();
        }

        // $command = "$find | xargs egrep -i \"preg_replace *\((['|\"])(.).*\2[a-z]*e[^\1]*\1 *,\" --color";
        // $response = Shell::run($command);
        // $progressBar->advance();

        // ensures that the progress bar is at 100%
        $progressBar->finish();
        
        // display the output
        asort($files, SORT_NUMERIC);
        $files = array_reverse($files, true);

        $tableRows = [];
        foreach ($files as $file => $score) {
            $row = [];
            $row[] = $score;
            $row[] = $file;
            $tableRows[] = $row;
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Rating', 'Filename'])
            ->setRows($tableRows);
        $table->render();

        return Command::SUCCESS;
    }

}