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

/**
 * Configurations Class
 *
 * Manages the complete xml configuration system via an api
 */
Class JsonLock extends Json
{
	/**
	 * Delete it
	 *
	 * @return void
	 */
	public static function delete( $repo_dir = false )
	{
		if (!$repo_dir) {
			$repo_dir = WORKING_DIR;
		}
		$file = $repo_dir.'protocol.lock';
		if (is_file($file)) {
			return unlink($file);
		}
	}
	
	/**
	 * Constructor.
	 * 
	 * @param string $file // is the lock file location
	 * @param string $repo_dir // is the directory of the repo
	 */
	function __construct( $file, $repo_dir = false )
	{
		// set the lock file
		$this->configfile = $file;

		// if the lock file does not exist
		if (!is_file($file)) {
			// we need to find the json file to populate our initial data
			if (is_file($repo_dir.'protocol.json')) {
				$file = $repo_dir.'protocol.json';
			}
		}
		$raw = file_get_contents($file);

		$this->data = json_decode($raw, true);
	}

	/**
	 * Get Instance
	 * 
	 * Method returns an instance of the proper class and its variable set
	 * 
	 * @param $file string
	 */
	public static function getInstance( $file = false, $repo_dir = false )
	{
		if (!$repo_dir) {
			$repo_dir = Git::getGitLocalFolder();
		}
		$file = $repo_dir.'protocol.lock';
		return parent::getInstance( $file, $repo_dir );
	}
}