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
use Gitcd\Helpers\Log;
use Gitcd\Helpers\Config;

Class Crontab
{
    /**
     * Ensure crontab is available on the system.
     * Installs cronie if missing (Amazon Linux / RHEL / Debian).
     */
    public static function ensureInstalled(): bool
    {
        if (Shell::run("which crontab 2>/dev/null")) {
            return true;
        }

        // Try to install cronie
        if (Shell::run("which yum 2>/dev/null")) {
            Shell::run("sudo yum install -y cronie 2>/dev/null");
        } elseif (Shell::run("which dnf 2>/dev/null")) {
            Shell::run("sudo dnf install -y cronie 2>/dev/null");
        } elseif (Shell::run("which apt-get 2>/dev/null")) {
            Shell::run("sudo apt-get install -y cron 2>/dev/null");
        }

        // Enable and start the service
        Shell::run("sudo systemctl enable crond 2>/dev/null || sudo systemctl enable cron 2>/dev/null");
        Shell::run("sudo systemctl start crond 2>/dev/null || sudo systemctl start cron 2>/dev/null");

        return (bool) Shell::run("which crontab 2>/dev/null");
    }

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
        if (!self::ensureInstalled()) {
            return false;
        }
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
        $crontab = Shell::run("crontab -l");
        return strpos($crontab, $command) !== false;
    }

    /**
     * Command to append the contents into the crontab
     *
     * @param [type] $toappend
     * @return void
     */
    public static function appendCrontab( $toappend )
    {
        $existing = Shell::run("crontab -l", $returnVar);
        // If crontab -l fails (no crontab), start fresh
        if ($returnVar !== 0) {
            $existing = '';
        }
        $newCrontab = rtrim($existing) . PHP_EOL . $toappend;
        return self::overwriteCrontab($newCrontab);
    }

    /**
     * overwrite all contents in the crontab
     *
     * @param [type] $toappend
     * @return void
     */
    public static function overwriteCrontab( $tooverwrite )
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'crontab_');
        file_put_contents($tmpFile, $tooverwrite);
        $result = Shell::run("crontab " . escapeshellarg($tmpFile), $returnVar);
        if ($returnVar !== 0) {
            Log::error('crontab', "overwrite failed (exit={$returnVar}): {$result}");
            Log::debug('crontab', "content: " . str_replace("\n", "\\n", $tooverwrite));
        }
        unlink($tmpFile);
        return $result;
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

    /**
     * Generate the docker cleanup cron command for the given repo.
     *
     * @param string $repo_dir
     * @param string $schedule Cron schedule expression (default: daily at 3am)
     * @return string
     */
    public static function dockerCleanupCommand( $repo_dir, $schedule = '0 3 * * *' )
    {
        $realpath = Dir::realpath( $repo_dir );
        $protocolpath = Dir::realpath( WEBROOT_DIR."protocol" );

        return <<<SETTINGS
            #~ PROTOCOL DOCKER CLEANUP START ~
            {$schedule} php '$protocolpath' docker:cleanup --dir='$realpath'
            #~ PROTOCOL DOCKER CLEANUP END ~
            SETTINGS;
    }

    /**
     * Add a scheduled docker cleanup cron job.
     *
     * @param string $repo_dir
     * @param string $schedule Cron schedule expression
     * @return bool
     */
    public static function addDockerCleanup( $repo_dir, $schedule = '0 3 * * *' )
    {
        // Remove existing first to avoid duplicates
        if (self::hasDockerCleanup($repo_dir)) {
            self::removeDockerCleanup($repo_dir);
        }

        $body = self::dockerCleanupCommand( $repo_dir, $schedule ).PHP_EOL;
        self::appendCrontab( $body );
        return true;
    }

    /**
     * Remove the docker cleanup cron job.
     *
     * @param string $repo_dir
     * @return void
     */
    public static function removeDockerCleanup( $repo_dir )
    {
        // We need to remove any docker cleanup block regardless of schedule
        $crontab = Shell::run("crontab -l");
        $cleaned = preg_replace(
            '/#~ PROTOCOL DOCKER CLEANUP START ~.*?#~ PROTOCOL DOCKER CLEANUP END ~/s',
            '',
            $crontab
        );
        self::overwriteCrontab( trim($cleaned).PHP_EOL.PHP_EOL );
    }

    /**
     * Check if a docker cleanup cron job exists.
     *
     * @param string $repo_dir
     * @return bool
     */
    public static function hasDockerCleanup( $repo_dir )
    {
        $crontab = Shell::run("crontab -l");
        return strpos($crontab, '#~ PROTOCOL DOCKER CLEANUP START ~') !== false;
    }
}