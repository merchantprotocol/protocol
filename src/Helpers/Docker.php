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
use Gitcd\Utils\Json;
use Gitcd\Utils\Yaml;

Class Docker
{
    /**
     * Tells us if there's a docker-compose file in the repo
     *
     * @param boolean $repo_dir
     * @return boolean
     */
    public static function isDockerInitialized( $repo_dir = false )
    {
        if (!$repo_dir) {
            $repo_dir = '..'.DIRECTORY_SEPARATOR;
        }
        $yaml = $repo_dir.'docker-compose.yml';
        return is_file($yaml);
    }

    /**
     * Boolean response as to whether or not a docker service is running
     *
     * @param [type] $service
     * @return boolean
     */
    public static function isDockerContainerRunning( $service )
    {
        $processes = Self::getDockerProcesses();
        if (!$processes) {
            return false;
        }
        $names = array_column($processes, "NAMES");
        if (in_array($service, $names) === false) {
            return false;
        }

        return true;
    }

    /**
     * Boolean response as to whether or not a docker service is running
     *
     * @param [type] $service
     * @return boolean
     */
    public static function getContainerNamesFromDockerComposeFile( $repo_dir = false )
    {
        $dockerServices = Yaml::read('services', null, $repo_dir);
        $containerNames = [];
        foreach ($dockerServices as $services => $serviceConfigs) {
            if (array_key_exists('container_name', $serviceConfigs)) {
                $containerNames[] = $serviceConfigs['container_name'];
            }
        }
        return $containerNames;
    }

    /**
     * Returns an array of the docker processes
     *
     * @return void
     */
    public static function getDockerProcesses()
    {
        $cmd = "docker ps";

        $processes = Shell::run($cmd);
        return Shell::shellTableToArray($processes);
    }
}