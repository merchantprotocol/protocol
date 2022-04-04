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

/**
 * Configurations Class
 *
 * Manages the complete xml configuration system via an api
 */
Class JsonLock extends Json
{
	/**
	 * Read a property
	 *
	 * @param string $property
	 * @param any $default
	 */
	public static function read( $property = null, $default = null ) 
	{
		//initializing
		$self = self::getInstance();
		return $self->get( $property, $default );
	}
	
	/**
	 * Write a value
	 * 
	 * Method will set a value and create the tree if it does not exist
	 *
	 * @param string $property
	 * @param any $value
	 */
	public static function write( $property, $value = null ) {
		//initializing
		$self = self::getInstance();
		$self->_set(array($property => $value), $self->data);
		return $self;
	}
	
	/**
	 * Constructor.
	 * 
	 * @param string $file
	 */
	function __construct( $file )
	{
		$raw = [];
		if (is_file($file)) {
        	$raw = file_get_contents($file);
		}
        $data = json_decode($raw, true);
	    $this->set( null, $data );
	}

	/**
	 * Delete it
	 *
	 * @return void
	 */
	public static function delete()
	{
		$file = WORKING_DIR.'protocol.lock';
		if (is_file($file)) {
			return unlink($file);
		}
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
			$file = WORKING_DIR.'protocol.lock';
		}

		//create the class if it does not exist
		if (empty(self::$instances[$file]))
		{
			//creating the instance
			$config = new Json( $file );
			self::$instances[$file] = $config;
		}
		
		//return an instance of this instantiation
		return self::$instances[$file];
	}
}