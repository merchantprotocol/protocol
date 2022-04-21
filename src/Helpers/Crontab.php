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
use Gitcd\Helpers\Config;

Class Crontab
{
    /**
     * This command will cause protocol to be restarted when the server is restarted
     * 
     *
     * @param [type] $repo_dir
     * @return void
     */
    public static function restartcommand( $repo_dir )
    {
        $realpath = Dir::realpath( $repo_dir );
        $protocolpath = Dir::realpath( WEBROOT_DIR."protocol" );

        return <<<SETTINGS
            #~ PROTOCOL START ~
            @reboot php '$protocolpath' restart '$realpath'
            #~ PROTOCOL END ~
            SETTINGS;
    }

    /**
     * Adds the crontab restart command for the given repo
     *
     * @return void
     */
    public static function addCrontabRestart( $repo_dir )
    {
        $body = self::restartcommand( $repo_dir ).PHP_EOL;
        self::appendCrontab( $body );
        return true;
    }

    /**
     * Remove crontab restart command
     *
     * @param [type] $repo_dir
     * @return void
     */
    public static function removeCrontabRestart( $repo_dir )
    {
        $body = self::restartcommand( $repo_dir );
        self::removeCrontabJob( $body );
    }

    /**
     * Remove crontab restart command
     *
     * @param [type] $repo_dir
     * @return void
     */
    public static function hasCrontabRestart( $repo_dir )
    {
        $body = self::restartcommand( $repo_dir );
        return self::hasCrontabCommand( $repo_dir, $body );
    }

    /**
     * Adds the crontab restart command for the given repo
     *
     * @return void
     */
    public static function hasCrontabCommand( $repo_dir, $command )
    {
        $body = self::restartcommand( $repo_dir ).PHP_EOL;
        $crontabl = Shell::run("crontab -l");
        return strpos($crontabl, $command) !== false;
    }

    /**
     * Command to append the contents into the crontab
     *
     * @param [type] $toappend
     * @return void
     */
    public static function appendCrontab( $toappend )
    {
        $cmd = "(crontab -l ; echo \"$toappend\") | crontab";
        return Shell::run($cmd);
    }

    /**
     * overwrite all contents in the crontab
     *
     * @param [type] $toappend
     * @return void
     */
    public static function overwriteCrontab( $tooverwrite )
    {
        $cmd = "(echo \"$tooverwrite\") | crontab";
        return Shell::run($cmd);
    }

    /**
     * Will remove a job from the crontab
     *
     * @param [type] $toremove
     * @return void
     */
    public static function removeCrontabJob( $toremove )
    {
        $crontabl = Shell::run("crontab -l");
        $crontabl = str_replace($toremove, '', $crontabl);

        self::overwriteCrontab( trim($crontabl).PHP_EOL.PHP_EOL );
    }
}