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

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Gitcd\Helpers\Shell;

Class ConfigMove extends ConfigCopy {

    protected static $defaultName = 'config:mv';
    protected static $defaultDescription = 'Move a file into the config repo, delete it from the app repo and create a symlink back.';

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(<<<HELP
        Sending a relative file path to this command will move the file from the application repo into the configurations repo. Additionally the file will be added to the application repos .gitignore file and the config repo will be pushed to it's remote.

        A symlink will be added back into the application repo.

        HELP);
    }

    /**
     * Prevent moving symlinks.
     */
    protected function validateSource(string $currentpath, OutputInterface $output): bool
    {
        if (is_link($currentpath)) {
            $output->writeln("<error>Cannot move a symlink.</error>");
            return false;
        }
        return true;
    }

    /**
     * After copying, remove the source file and refresh symlinks.
     */
    protected function afterCopy(string $currentpath, string $newpath, string $repo_dir, OutputInterface $output): void
    {
        if (strpos($currentpath, WORKING_DIR) !== false && is_file($currentpath)) {
            Shell::passthru("rm -f " . escapeshellarg($currentpath));
            Shell::run("git -C " . escapeshellarg($repo_dir) . " rm --cached " . escapeshellarg($currentpath));

            $command = $this->getApplication()->find('config:refresh');
            $command->run((new ArrayInput([])), $output);
        }
    }

}
