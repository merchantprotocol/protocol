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

use Gitcd\Helpers\GitHub;

Class Release
{
    /**
     * Normalize a version string to v-prefixed semver.
     *
     * @param string $version
     * @return string|null  Returns null if the input is not valid semver.
     */
    public static function normalizeVersion(string $version): ?string
    {
        $version = ltrim($version, 'v');
        if (!preg_match('/^\d+\.\d+\.\d+(-[\w.]+)?$/', $version)) {
            return null;
        }
        return 'v' . $version;
    }

    /**
     * Increment a semver version string by the given bump type.
     *
     * @param string $version  Current version (with or without v prefix), e.g. "v1.2.3"
     * @param string $bump     One of 'major', 'minor', 'patch'
     * @return string|null     The new v-prefixed version, or null on invalid input.
     */
    public static function incrementVersion(string $version, string $bump = 'patch'): ?string
    {
        $version = ltrim($version, 'v');
        $parts = explode('.', $version);
        if (count($parts) !== 3) {
            return null;
        }

        $major = (int) $parts[0];
        $minor = (int) $parts[1];
        $patch = (int) $parts[2];

        switch ($bump) {
            case 'major':
                $major++;
                $minor = 0;
                $patch = 0;
                break;
            case 'minor':
                $minor++;
                $patch = 0;
                break;
            case 'patch':
            default:
                $patch++;
                break;
        }

        return "v{$major}.{$minor}.{$patch}";
    }

    /**
     * Auto-generate the next version from the latest git tag in a repo.
     *
     * @param string $repo_dir
     * @param string $bump  One of 'major', 'minor', 'patch'
     * @return string|null  The next v-prefixed version, or null if no tags exist.
     */
    public static function autoVersion(string $repo_dir, string $bump = 'patch'): ?string
    {
        $tags = GitHub::getTags($repo_dir);
        if (empty($tags)) {
            return null;
        }

        return self::incrementVersion($tags[0], $bump);
    }

    /**
     * Parse a CHANGELOG.md file into an array of release sections.
     *
     * Each entry is an associative array with:
     *   'heading' => The first line of the section (version + date)
     *   'body'    => The remaining content of the section
     *
     * @param string $path  Path to the CHANGELOG.md file.
     * @return array|false  Array of release sections, or false on failure.
     */
    public static function parseChangelog( $path )
    {
        $contents = file_get_contents($path);
        if (!$contents) {
            return false;
        }

        $releases = [];
        $parts = explode('## ', $contents);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }
            $lines = explode("\n", $part, 2);
            $releases[] = [
                'heading' => trim($lines[0]),
                'body'    => isset($lines[1]) ? trim($lines[1]) : '',
            ];
        }

        return $releases;
    }
}