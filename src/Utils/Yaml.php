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
namespace Gitcd\Utils;

use Gitcd\Utils\Config;
use Gitcd\Helpers\Git;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

/**
 * Configurations Class
 *
 * Manages the complete xml configuration system via an api
 */
Class Yaml extends Config
{
	/**
	 * file_put_contents
	 * 
	 * Method will create a configuration file
	 *
	 * @param unknown_type $file
	 */
	function put( $file = null ) {
		$file = $this->configfile;
		
		if (file_exists($file)) {
			if (fileperms($file) !== 33279) {
				if (!chmod($file, 0755)) {
					@unlink($file);
				}
			}
		}
		$yaml_data = SymfonyYaml::dump($this->data, JSON_PRETTY_PRINT);
		return ($file) ?file_put_contents( $file, $yaml_data ) :false;
	}

	/**
	 * Creates the json file
	 *
	 * @return void
	 */
	public static function save()
	{
		$file = WORKING_DIR.'docker-compose.yml';
		$self = self::getInstance();
        $self->put( $file );
	}

    /**
     * Creates a lock file
     *
     * @param boolean $file
     * @return void
     */
    public static function lock( $file = false )
    {
		if (!$file) {
			$file = WORKING_DIR.'docker-compose.yml';
		}
		$self = self::getInstance();
        $self->put( $file );
    }
	
	/**
	 * Constructor.
	 * 
	 * @param string $file
	 */
	function __construct( $file )
	{
		$this->configfile = $file;
		$this->data = SymfonyYaml::parseFile($this->configfile);
	}

	/**
	 * Get Instance
	 * 
	 * Method returns an instance of the proper class and its variable set
	 * 
	 * @param $file string
	 */
	public static function getInstance( $file = false )
	{
		if (!$file) {
			$repo_dir = Git::getGitLocalFolder();
			$file = $repo_dir.'docker-compose.yml';
		}
		return parent::getInstance( $file );
	}
}