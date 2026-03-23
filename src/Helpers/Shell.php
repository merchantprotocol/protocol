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

use Gitcd\Utils\Json;
use Gitcd\Utils\JsonLock;

Class Shell
{
    CONST MAC = 'mac';
    CONST LINUX = 'linux';
    CONST CYGWIN = 'cygwin';
    CONST MINGW = 'mingw';

    /**
     * Return the process
     *
     * @param [type] $process
     * @param boolean $column
     * @return boolean
     */
    public static function hasProcess( $process, $column = false )
    {
        $processes = Self::getProcesses();
        $has = [];
        foreach ($processes as $ps) {
            $string = implode(" ", $ps);
            if (strpos($string, $process)!==false) {
                $has[] = $ps;
            }
        }
        return $has;
    }

    /**
     * Returns an array of processes
     *
     * @param boolean $process
     * @return boolean
     */
    public static function getProcesses()
    {
        $dash = '';
        if (SELF::MAC != self::getOS()) {
            $dash = '-';
        }
        $cmd = <<<CMD
        ps -exo %mem,%cpu,pid,command | awk '{OORS=ORS; ORS=""; print $1"   "$2"   "$3"   "; ORS=OORS; $1="";$2="";$3=""; print $0; }'
        CMD;

        $processes = Shell::run($cmd);
        return self::shellTableToArray($processes);
    }

    /**
     * Function that converts shell tables to an array
     *
     * @param [type] $blob
     * @return void
     */
    public static function shellTableToArray( $blob )
    {
        $processes = explode(PHP_EOL, $blob);

        $keys = array_shift($processes);
        $keys = preg_split("/\s{2,}/", $keys);

        $psArray = [];
        foreach ($processes as $ps) {
            $values = preg_split("/\s{2,}/", $ps);
            $row = [];
            foreach ($values as $key => $value) {
                $row[ $keys[$key] ] = $value;
            }
            $psArray[] = $row;
        }
        return $psArray;
    }

    /**
     * returns the process
     *
     * @param boolean $process
     * @return boolean
     */
    public static function getProcess( $process = false )
    {
        $filter = "";
        if ($process) {
            $filter = "| grep " . escapeshellarg($process);
        }

        $cmd = "ps aux $filter";
        $processes = Shell::run($cmd);
        $processes = explode(PHP_EOL, $processes);

        foreach ($processes as $key => $_ps) {
            if (strpos($_ps, "grep")!==false && strpos($_ps, $process)!==false) {
                unset($processes[$key]);
            }
            if (strpos($_ps, $cmd)!==false) {
                unset($processes[$key]);
            }
        }
        return $processes;
    }

    /**
     * determines what operating system we're running
     *
     * @return void
     */
    public static function getOS()
    {
        $uname = Shell::run("uname -a");

        if (strpos($uname, "Linux")!==false) {
            return SELF::LINUX;
        } elseif (strpos($uname, "Darwin")!==false) {
            return SELF::MAC;
        } elseif (strpos($uname, "CYGWIN")!==false) {
            return SELF::CYGWIN;
        } elseif (strpos($uname, "MINGW")!==false) {
            return SELF::MINGW;
        }

        return 'unknown';
    }

    /**
     * Provides a passthrough exec option
     *
     * @param [type] $command
     * @return void
     */
    public static function passthru( $command )
    {
        // Recover from invalid cwd before spawning a shell
        if (@getcwd() === false) {
            @chdir('/tmp');
        }

        $descriptorSpec = array(
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        );
        $pipes = array();
        $process = proc_open($command, $descriptorSpec, $pipes);
        $exitCode = 0;
        if (is_resource($process)) {
            $exitCode = proc_close($process);
        }

        return $exitCode;
    }

    /**
     * Runs the shell command and returns the result
     *
     * @param [string] $command
     * @param [int] $return_var
     * @return void
     */
    public static function run( $command, &$return_var = null )
    {
        // Detect invalid cwd BEFORE spawning a shell. When the current
        // working directory has been deleted (e.g. a release dir removed by
        // a previous cycle), every exec() call prints:
        //   shell-init: error retrieving current directory: getcwd: ...
        // Recover by chdir'ing to /tmp so the shell can initialize cleanly.
        $cwd = @getcwd();
        if ($cwd === false) {
            @chdir('/tmp');
        }

        $response = null;
        exec($command, $response, $return_var);

        if (is_array($response)) {
            $response = implode(PHP_EOL, $response);
        }

        return $response;
    }

    /**
     * Run this command in the background. This launches the command $cmd, redirects
     * the command output to $outputfile, and writes the process id to $pidfile.
     *
     * @param [type] $command
     * @return void
     */
    public static function background( $command )
    {
        // Recover from invalid cwd before spawning a shell
        if (@getcwd() === false) {
            @chdir('/tmp');
        }

        $outputfile = Log::getLogFile();

        // Write PID to a temp file instead of capturing via stdout pipe.
        // PHP's exec() blocks until ALL holders of the stdout pipe close it.
        // A backgrounded process inherits the pipe FD, so exec() hangs
        // until the daemon exits. Redirecting the group's stdout to /dev/null
        // closes the pipe immediately.
        $pidFile = sys_get_temp_dir() . '/protocol-bg-' . substr(md5($command), 0, 8) . '.pid';
        @unlink($pidFile);

        $wrapped = sprintf(
            "{ %s >> %s 2>&1 </dev/null & echo \$! > %s; } >/dev/null 2>&1",
            $command,
            escapeshellarg($outputfile),
            escapeshellarg($pidFile)
        );
        exec($wrapped);

        $response = '';
        if (is_file($pidFile)) {
            $response = trim(file_get_contents($pidFile));
            @unlink($pidFile);
        }

        return $response;
    }

    /**
     *
     *
     * @return boolean
     */
    public static function isLockedPIDStillRunning()
    {
        $pid = JsonLock::read('slave.pid');
        return self::isRunning( $pid );
    }

    /**
     * Allows you to check on a background running process
     *
     * @param [type] $pid
     * @return boolean
     */
    public static function isRunning( $pid )
    {
        if (!$pid) return false;

        $test = intval($pid);
        if ($pid != $test) return false;

        try {
            $result = shell_exec(sprintf("ps %d", $pid));
            if( count(preg_split("/\n/", $result)) > 2){
                return true;
            }
        } catch(\Exception $e) {}

        return false;
    }
}
