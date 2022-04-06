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
namespace Gitcd\Helpers;

Class Git 
{
    /**
     * push the changes
     *
     * @param boolean $repo_dir
     * @param boolean $origin
     * @param boolean $branch
     * @return void
     */
    public static function push( $repo_dir = false, $origin = false, $branch = false )
    {
        if ($repo_dir) {
            $repo_dir = Dir::realpath($repo_dir);
            $repo_dir = " -C '$repo_dir' ";
        }
        if (!$origin) {
            $origin = self::remoteName( $repo_dir );
        }
        if (!$branch) {
            $branch = self::branch( $repo_dir );
        }
        Shell::passthru("git $repo_dir push $origin $branch");
    }

    /**
     * Adds and commits all untracked changes to a repo. Requires a message
     *
     * @param [type] $message
     * @param boolean $repo_dir
     * @return void
     */
    public static function commit( $message, $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = Dir::realpath($repo_dir);
            $repo_dir = " -C '$repo_dir' ";
        }
        Shell::run("git $repo_dir add -A");
        Shell::run("git $repo_dir commit -m '$message'");
    }

    /**
     * Untracked changes
     *
     * @return boolean
     */
    public static function hasUntrackedFiles( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = Dir::realpath($repo_dir);
            $repo_dir = " -C '$repo_dir' ";
        }
        $response = Shell::run("git $repo_dir status");
        if (strpos($response, 'Untracked files')===false) {
            return false;
        }
        return true;
    }

    /**
     * Add an entry to the gitignore file
     *
     * @param [type] $file
     * @param boolean $repo_dir
     * @return void
     */
    public static function addIgnore( $file, $repo_dir = false )
    {
        $ignorepath = rtrim($repo_dir, '/').DIRECTORY_SEPARATOR.'.gitignore';

        // @todo make sure the entry doesn't already exist
        $command = "echo '".<<<FILE
        $file
        FILE."' >> $ignorepath";

        Shell::run($command);
    }

    /**
     * Return the first name of the remote 
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function remoteName( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }
        $remotes = Shell::run("git $repo_dir remote");
        $remotearray = explode(PHP_EOL, $remotes);
        $remote = array_shift($remotearray);
        return $remote;
    }

    /**
     * Return the remote url of the current working directory
     *
     * @return void
     */
    public static function RemoteUrl( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }
        $command = "git $repo_dir config --get remote.origin.url";
        return Shell::run( $command );
    }

    /**
     * Create a new branch and switch to it
     *
     * @param [type] $branch
     * @param boolean $repo_dir
     * @return void
     */
    public static function createBranch( $branch, $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }

        $command = "git $repo_dir checkout -b $branch";
        $branchstring = Shell::run( $command );
    }

    /**
     * Switch to branch
     *
     * @param [type] $branch
     * @param boolean $repo_dir
     * @return void
     */
    public static function switchBranch( $branch, $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }

        $command = "git $repo_dir checkout $branch";
        $branchstring = Shell::run( $command );
    }

    /**
     * return an array of branches
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function branches( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }
        $command = "git $repo_dir branch";
        $branchstring = Shell::run( $command );
        $branchstring = str_replace('*','', $branchstring);
        $branches = explode(PHP_EOL, $branchstring);
        $branches = array_map('trim', $branches);
        return $branches;
    }

    /**
     * Return the branch name
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function branch( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }
        $command = "git $repo_dir branch | sed -n -e 's/^\* \(.*\)/\\1/p'";
        return Shell::run( $command );
    }

    /**
     * truncates the dir but leaves the .git and optionally leaves the specified array of files
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function truncateBranch( $repo_dir = false, $ignore = [] )
    {
        if ($repo_dir) {
            $repo_dir = " -C '$repo_dir' ";
        }

    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public static function getGitLocalFolder()
    {
        $path = WORKING_DIR;
        for ($i=0; $i<10; $i++) {
            if ($path == '//') {
                break;
            }
            if (is_dir($path.'.git')) {
                return $path;
            }
            $path = dirname($path).DIRECTORY_SEPARATOR;
        }
        return false;
    }

    /**
     * Fails if the current repo is not a git repo
     *
     * @param [type] $output
     * @return void
     */
    public static function checkInitializedRepo( $output )
    {
        if (self::isInitializedRepo()) {
            return true;
        }
        $output->writeln(PHP_EOL."     fatal: not a git repository (or any of the parent directories): .git".PHP_EOL);
        exit(1);
    }

    /**
     * Checks to see if the current repo is a git repo or any of the parent repositories
     *
     * @return boolean
     */
    public static function isInitializedRepo()
    {
        $command = 'git log';
        $response = Shell::run($command);
        if (strpos($response, 'not a git repository') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Initializes a new repo 
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function initialize( $repo_dir = false )
    {
        if ($repo_dir) {
            $repo_dir = Dir::realpath( $repo_dir );
            if (!$repo_dir) {
                return false;
            }
            $cRepoDir = " -C '$repo_dir' ";
        }
        if (!is_dir($repo_dir)) {
            Shell::run("mkdir -p '$repo_dir'");
        }
        Shell::run("git $cRepoDir init");
        Shell::run("git $cRepoDir config core.mergeoptions --no-edit");
        Shell::run("git $cRepoDir config core.fileMode false");
        if (!is_dir($repo_dir.'.git')) {
            return false;
        }
        return true;
    }
}