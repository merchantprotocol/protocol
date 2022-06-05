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

Class Config {

    /**
     * Return the configuration repo folder
     *
     * @param [type] $repo_dir
     * @return void
     */
    public static function repo( $repo_dir )
    {
        $foldername = basename($repo_dir).'-config';

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

        // override the default
        $configfile = CONFIG_DIR.'config.php';
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
        $config = Config::getConfig( CONFIG_DIR.'config.php' );
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
        $configfile = CONFIG_DIR.'config.php';
        if (!file_exists($configfile)) {
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