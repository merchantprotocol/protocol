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
 * @package    merchantprotocol/github-continuous-delivery
 * @copyright  Copyright (c) 2019 Merchant Protocol, LLC (https://merchantprotocol.com/)
 * @license    MIT License
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Config;

Class Shell {



    /**
     * Runs the shell command and returns the result
     *
     * @param [string] $command
     * @param [int] $return_var
     * @return void
     */
    public static function run( $command, &$return_var = null )
    {
        $response = null;
        exec("$command 2>&1", $response, $return_var);

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
        global $webroot;
        $outputfile = $webroot.DIRECTORY_SEPARATOR.Config::read('shell.outputfile');
        if (!file_exists($outputfile)) {
            touch($outputfile);
        }
        $pidfile = $webroot.DIRECTORY_SEPARATOR.Config::read('shell.pidfile');

        return exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $command, $outputfile, $pidfile));
    }

    /**
     * allows you to check on a background running process
     *
     * @param [type] $pid
     * @return boolean
     */
    public static function isRunning( $pid )
    {
        try{
            $result = shell_exec(sprintf("ps %d", $pid));
            if( count(preg_split("/\n/", $result)) > 2){
                return true;
            }
        }catch(Exception $e){}

        return false;
    }
}