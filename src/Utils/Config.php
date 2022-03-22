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

/**
 * Configurations Class
 *
 * Manages the complete xml configuration system via an api
 */
Class Config 
{
	/**
	 * Complex Data Array
	 * 
	 * Holds the entire configuration set in a protected array so that 
	 * sloppy developers cannot destroy it.
	 * 
	 * @var array
	 */
	protected $data = array();
	
	/**
	 * @var    registry
	 * @since  0.1
	 */
	public static $instances = array();
	
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
	 * 
	 * @param unknown_type $property
	 * @param unknown_type $default
	 * @return any|unknown
	 */
	function get( $property = null, $default = null ) 
	{
		if (!is_string($property) && $default !== null) return $default;
		
		//initializing
		$pts = explode('.', $property);
		$recurse = $this->data;
		
		if ($property === null) 
		    return $recurse;
		
		foreach ((array)$pts as $p) {
			//if there's no property, then we return the default
			if (!isset($recurse[$p]))
				return $default;
			
			$recurse = $recurse[$p];
		}
		
		return $recurse;
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
	 * Set a complete block of values
	 * 
	 * Method will merge the given array into the configuration array
	 *
	 * @param unknown_type $block
	 */
	function set( $path, $block ) {
		$this->_set( array($path => $this->parse_args($block, $this->get($path))), $this->data );
		return $this;
	}
	
	/**
	 * Sets key/value pairs at any depth on an array.
	 * 
	 * @param $data an array of key/value pairs to be added/modified
	 * @param $array the array to operate on
	 */
	function _set( $data, &$array )
	{
		$separator = '.'; // set this to any string that won't occur in your keys
		foreach ($data as $name => $value) {
			if (strpos($name, $separator) === false && !$name) {
				$array = $value;
			} elseif (strpos($name, $separator) === false) {
				// If the array doesn't contain a special separator character, just set the key/value pair. 
				// If $value is an array, you will of course set nested key/value pairs just fine.
				$array[$name] = $value;
			} else {
				// In this case we're trying to target a specific nested node without overwriting any other siblings/ancestors. 
				// The node or its ancestors may not exist yet.
				$keys = explode($separator, $name);
				// Set the root of the tree.
				$opt_tree =& $array;
				// Start traversing the tree using the specified keys.
				while ($key = array_shift($keys)) {
					// If there are more keys after the current one...
					if ($keys) {
						if (!isset($opt_tree[$key]) || !is_array($opt_tree[$key])) {
							// Create this node if it doesn't already exist.
							$opt_tree[$key] = array();
						}
						// Redefine the "root" of the tree to this node (assign by reference) then process the next key.
						$opt_tree =& $opt_tree[$key];
					} else {
						// This is the last key to check, so assign the value.
						$opt_tree[$key] = $value;
					}
				}
			}
		}
	}
		
	/**
	 * file_put_contents
	 * 
	 * Method will create a configuration file
	 *
	 * @param unknown_type $file
	 */
	function put( $file = null ) {
		
		if (is_null($file)) {
			return false;
		}
		
		if (file_exists($file)) {
			if (fileperms($file) !== 33279) {
				if (!chmod($file, 0755)) {
					@unlink($file);
				}
			}
		}
		
		return ($file)?file_put_contents( $file, '<?php return '.var_export($this->data, true).';' ):false;
	}
	
	/**
	 * Constructor.
	 * 
	 * @param string $file
	 */
	function __construct( $file )
	{
	    $this->set( null, require $file );
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
			$file = WEBROOT_DIR.'config'.DIRECTORY_SEPARATOR.'global.php';
		}

	    //print_r(self::$instances);
		//create the class if it does not exist
		if (empty(self::$instances[$file]))
		{
			//creating the instance
			$config = new Config( $file );
			self::$instances[$file] = $config;
		}
		
		//return an instance of this instantiation
		return self::$instances[$file];
	}
	
	/**
	 * Merge user defined arguments into defaults array.
	 *
	 * This function is used throughout WordPress to allow for both string or array
	 * to be merged into another array.
	 *
	 * @param string|array $args Value to merge with $defaults
	 * @param array $defaults Array that serves as the defaults.
	 * @return array Merged user defined values with defaults.
	 */
	function parse_args( $args, $defaults = '' ) {
	    if ( is_object( $args ) )
	        $r = get_object_vars( $args );
	        elseif ( is_array( $args ) )
	        $r =& $args;
	        else
	            parse_string( $args, $r );
	            
	            if ( is_array( $defaults ) )
	                return array_merge( $defaults, $r );
	                return $r;
	}
}