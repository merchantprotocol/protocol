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

use Gitcd\Utils\Config as UtilConfig;
use Gitcd\Utils\Json;
use Gitcd\Helpers\Dir;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;

Class Config {

    /**
     * Validate that the config repo exists and is initialized.
     * Returns the config repo path, or null if validation fails (with error output).
     *
     * @param string $repo_dir
     * @param OutputInterface $output
     * @return string|null
     */
    public static function requireRepo(string $repo_dir, OutputInterface $output): ?string
    {
        $configrepo = self::repo($repo_dir);
        if (!$configrepo) {
            $output->writeln('<error>Please run `protocol config:init` before using this command.</error>');
            return null;
        }
        return $configrepo;
    }

    /**
     * Return the configuration repo folder
     *
     * @param [type] $repo_dir
     * @return void
     */
    public static function repo( $repo_dir )
    {
        $foldername = basename(rtrim($repo_dir, '/')). '-config';

        // Detect slave context: config repo lives inside the releases directory
        $nodeInfo = \Gitcd\Utils\NodeConfig::findByActiveDir($repo_dir);
        if ($nodeInfo) {
            $nodeData = $nodeInfo[1];
            $releasesDir = rtrim($nodeData['bluegreen']['releases_dir'] ?? '', '/');
            if ($releasesDir) {
                $configLocal = Json::read('configuration.local', false, $repo_dir);
                if ($configLocal) {
                    // configuration.local is relative to the project dir (e.g. "../enterprise-gateway-config")
                    // In releases context, resolve it relative to the active release dir
                    $path = $repo_dir . $configLocal . '/';
                } else {
                    $projectName = $nodeData['name'] ?? basename(rtrim($nodeData['repo_dir'] ?? $repo_dir, '/'));
                    $path = $releasesDir . '/' . $projectName . '-config/';
                }
                return Dir::realpath($path);
            }
        }

        $path = Json::read('configuration.local', '..'.DIRECTORY_SEPARATOR.$foldername.DIRECTORY_SEPARATOR, $repo_dir);
        if (strpos($path, '..')!==false) {
            $path = $repo_dir.$path;
        }
        return Dir::realpath($path);
    }

    /**
     * 
     *
     * @param [string] $path
     * @param [bool] $default
     * @return void
     */
    public static function read( $property = null, $default = null )
    {
        // update the default
        $global = self::getGlobal();
        $default = $global->get( $property, $default );

        // override the default from node-level config
        $configfile = NODE_DATA_DIR.'config.php';
        if (file_exists($configfile))
        {
            $config = self::getConfig();
            $default = $config->get( $property, $default );
        }
        return $default;
    }

    /**
     * Write the value to the local config.php file
     *
     * @param [type] $property
     * @param [type] $value
     * @return void
     */
    public static function write( $property = null, $value = null )
    {
        if (is_null($property)) {
            return false;
        }
        $config = Config::getConfig( NODE_DATA_DIR.'config.php' );
        $config->set($property, $value);
        $config->put();
    }

    /**
     * Return the config.php instance
     *
     * @return void
     */
    public static function getConfig()
    {
        $configfile = NODE_DATA_DIR.'config.php';
        if (!file_exists($configfile)) {
            if (!is_dir(NODE_DATA_DIR)) {
                mkdir(NODE_DATA_DIR, 0700, true);
            }
            @touch($configfile);
        }
        $config = UtilConfig::getInstance( $configfile );
        return $config;
    }

    /**
     * Return the config.php instance
     *
     * @return void
     */
    public static function getGlobal()
    {
        $config = UtilConfig::getInstance();
        return $config;
    }
}