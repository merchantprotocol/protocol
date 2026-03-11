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

use Gitcd\Helpers\Shell;
use Gitcd\Helpers\Dir;

Class Git
{
    /**
     * Build the git -C flag for a given repo directory.
     *
     * @param string|false $repo_dir
     * @return string  The -C flag string, or empty string if no repo_dir.
     */
    private static function repoFlag( $repo_dir = false ): string
    {
        if ($repo_dir) {
            return " -C " . escapeshellarg(Dir::realpath($repo_dir)) . " ";
        }
        return '';
    }

    /**
     * If you notice your .git folder bloating, this is the cleansing you need
     *
     * @param string|false $repo_dir
     * @return void
     */
    public static function clean( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        $origin = escapeshellarg(self::remoteName( $repo_dir ));

        Shell::passthru(<<<CMD
        git $flag remote prune $origin \
            && git $flag repack \
            && git $flag prune-packed \
            && git $flag reflog expire --expire=1.month.ago \
            && git $flag gc --aggressive
        CMD);
    }

    /**
     * push the changes
     *
     * @param string|false $repo_dir
     * @param string|false $origin
     * @param string|false $branch
     * @return void
     */
    public static function push( $repo_dir = false, $origin = false, $branch = false )
    {
        $flag = self::repoFlag($repo_dir);
        if (!$origin) {
            $origin = self::remoteName( $repo_dir );
        }
        if (!$branch) {
            $branch = self::branch( $repo_dir );
        }
        Shell::passthru("git $flag push " . escapeshellarg($origin) . " " . escapeshellarg($branch));
    }

    /**
     * Adds and commits all untracked changes to a repo. Requires a message
     *
     * @param string $message
     * @param string|false $repo_dir
     * @return void
     */
    public static function commit( $message, $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        Shell::run("git $flag add -A");
        Shell::run("git $flag commit -m " . escapeshellarg($message));
    }

    /**
     * Untracked changes
     *
     * @param string|false $repo_dir
     * @return boolean
     */
    public static function hasUntrackedFiles( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        $response = Shell::run("git $flag status");
        return strpos($response, 'Untracked files') !== false;
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

        // Make sure the entry doesn't already exist
        if (is_file($ignorepath)) {
            $contents = file_get_contents($ignorepath);
            if (strpos($contents, $file) !== false) {
                return;
            }
        }

        file_put_contents($ignorepath, $file . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Return the first name of the remote 
     *
     * @param boolean $repo_dir
     * @return void
     */
    public static function remoteName( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        $remotes = Shell::run("git $flag remote");
        $remotearray = explode(PHP_EOL, $remotes);
        return array_shift($remotearray);
    }

    /**
     * Return the remote url of the current working directory
     *
     * @param string|false $repo_dir
     * @return string
     */
    public static function RemoteUrl( $repo_dir = false )
    {
        $remote = self::remoteName( $repo_dir );
        $flag = self::repoFlag($repo_dir);
        return Shell::run("git $flag config --get remote." . escapeshellarg($remote) . ".url");
    }

    /**
     * Create a new branch and switch to it
     *
     * @param string $branch
     * @param string|false $repo_dir
     * @return void
     */
    public static function createBranch( $branch, $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        Shell::run("git $flag checkout -b " . escapeshellarg($branch));
    }

    /**
     * Switch to branch
     *
     * @param string $branch
     * @param string|false $repo_dir
     * @return void
     */
    public static function switchBranch( $branch, $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        Shell::run("git $flag checkout " . escapeshellarg($branch));
    }

    /**
     * return an array of branches
     *
     * @param string|false $repo_dir
     * @return array
     */
    public static function branches( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        $branchstring = Shell::run("git $flag branch");
        $branchstring = str_replace('*','', $branchstring);
        $branches = explode(PHP_EOL, $branchstring);
        return array_map('trim', $branches);
    }

    /**
     * Return the branch name
     *
     * @param string|false $repo_dir
     * @return string
     */
    public static function branch( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        return Shell::run("git $flag branch | sed -n -e 's/^\* \(.*\)/\\1/p'");
    }

    /**
     * Truncates the dir but leaves .git and optionally leaves the specified array of files
     *
     * @param string|false $repo_dir
     * @param array $ignore
     * @return bool
     */
    public static function truncateBranch( $repo_dir = false, $ignore = [] )
    {
        $realpath = Dir::realpath($repo_dir);
        $files = Dir::dirToArray( $repo_dir, $ignore );
        $files = array_reverse($files); // directories are handled last
        foreach ($files as $file) {
            $absoluteFilePath = $realpath . $file;
            if (is_file($absoluteFilePath)) {
                Shell::run("rm -f " . escapeshellarg($absoluteFilePath));
            } elseif (is_dir($absoluteFilePath)) {
                rmdir($absoluteFilePath);
            }
        }
        return true;
    }

    /**
     * Fetch all for the given repo
     *
     * @param string|false $repo_dir
     * @return void
     */
    public static function fetch( $repo_dir = false )
    {
        $flag = self::repoFlag($repo_dir);
        Shell::run("git $flag fetch --all");
    }

    /**
     * Undocumented function
     *
     * @return void
     */
    public static function getGitLocalFolder()
    {
        $path = null;
        if (is_dir(WORKING_DIR.'.git')) {
            $path = WORKING_DIR;
        }
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
    public static function checkInitializedRepo( $output, $repo_dir = null )
    {
        if (self::isInitializedRepo($repo_dir)) {
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
    public static function isInitializedRepo( $repo_dir = null )
    {
        $cd = '';
        if (!is_null($repo_dir)) {
            // if we implicitly set the repodir but it does not exist
            if (!is_dir($repo_dir)) {
                return false;
            }
            $cd = "cd " . escapeshellarg($repo_dir) . " &&";
        }
        $command = "$cd git log";
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
        }
        if (!is_dir($repo_dir)) {
            Shell::run("mkdir -p " . escapeshellarg($repo_dir));
        }
        $flag = self::repoFlag($repo_dir);
        Shell::run("git $flag init");
        Shell::run("git $flag config core.mergeoptions --no-edit");
        Shell::run("git $flag config core.fileMode false");
        if (!is_dir($repo_dir.'.git')) {
            return false;
        }
        return true;
    }
}