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

Class Dir 
{
    /**
     * Creates an elipsis backup
     *
     * @param [type] $path
     * @return void
     */
    public static function dirDepthToElipsis( $path )
    {
        $count = self::countDirDepth( $path );
        return str_repeat('..'.DIRECTORY_SEPARATOR, $count);
    }

    /**
     * Counts the relative or absolute path depth
     *
     * @param [string] $path
     * @return int
     */
    public static function countDirDepth( $path )
    {
        if (is_file($path)) {
            $path = dirname($path);
        }
        $cleanpath = rtrim(ltrim(ltrim($path, '.'), '/'), '/');
        if (!$cleanpath) {
            return 0;
        }
        $dirArray = explode(DIRECTORY_SEPARATOR, $cleanpath);
        return count( $dirArray );
    }

    /**
     * Returns the real path whether it exists or not
     *
     * @param [string] $path
     * @param [bool] $default
     * @return void
     */
    public static function realpath( $path, $default = false )
    {
        if ($path && realpath($path)) {
            $path = rtrim(realpath($path), DIRECTORY_SEPARATOR);
        } elseif ($path && $dir = realpath(dirname($path))) {
            if ($dir) {
                $path = $dir.DIRECTORY_SEPARATOR.basename($path);
            }
        } else {
            $path = $default;
        }
        if (is_dir($path)) {
            $path = rtrim($path, '/').DIRECTORY_SEPARATOR;
        }
        return $path;
    }

    /**
     * Undocumented function
     *
     * @param [type] $dir
     * @return void
     */
    public static function dirToArray( $dir, $ignored = [] )
    {
        $result = [];
        $cdir = scandir($dir);
        foreach ($cdir as $key => $value)
        {
            if (!in_array($value,array(".", "..")))
            {
                if (is_dir($dir.DIRECTORY_SEPARATOR.$value))
                {
                    if (!in_array($value, $ignored)) {
                        $result[] = $dir.DIRECTORY_SEPARATOR.$value;
                        self::subdirToArray($dir.DIRECTORY_SEPARATOR.$value, $result, $dir, $ignored = []);
                    }
                }
                else
                {
                    if (!in_array($value, $ignored)) {
                        $result[] = $value;
                    }
                }
            }
        }
        
        return $result;
    }

    /**
     * Append the subdir
     *
     * @param [type] $dir
     * @param array $result
     * @return void
     */
    public static function subdirToArray( $dir, &$result = [], $webroot = '', $ignored = [] )
    {
        $cdir = scandir($dir);
        $path = str_replace($webroot.DIRECTORY_SEPARATOR, '' , $dir);
        foreach ($cdir as $key => $value)
        {
            if (!in_array($value,array(".", "..")))
            {
                if (is_dir($dir.DIRECTORY_SEPARATOR.$value))
                {
                    if (!in_array($value, $ignored)) {
                        self::subdirToArray($dir.DIRECTORY_SEPARATOR.$value, $result, $webroot, $ignored);
                    }
                }
                else
                {
                    if (!in_array($value, $ignored)) {
                        $result[] = $path.DIRECTORY_SEPARATOR.$value;
                    }
                }
            }
        }
        
        return $result;
    }

}