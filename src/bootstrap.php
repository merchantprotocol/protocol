<?php
/**
 * Shared bootstrap for Protocol entry points.
 * Used by both the main `protocol` CLI and the `bin/release-watcher.php` daemon.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('WORKING_DIR')) {
    define('WORKING_DIR', getcwd() . DIRECTORY_SEPARATOR);
}
if (!defined('WEBROOT_DIR')) {
    define('WEBROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', WEBROOT_DIR . 'config' . DIRECTORY_SEPARATOR);
}
if (!defined('SRC_DIR')) {
    define('SRC_DIR', WEBROOT_DIR . 'src' . DIRECTORY_SEPARATOR);
}
if (!defined('SCRIPT_DIR')) {
    define('SCRIPT_DIR', WEBROOT_DIR . 'bin' . DIRECTORY_SEPARATOR);
}
if (!defined('TEMPLATES_DIR')) {
    define('TEMPLATES_DIR', WEBROOT_DIR . 'templates' . DIRECTORY_SEPARATOR);
}
if (!defined('COMMANDS_DIR')) {
    define('COMMANDS_DIR', SRC_DIR . 'Commands' . DIRECTORY_SEPARATOR);
}
if (!defined('PROTOCOL_VERSION')) {
    define('PROTOCOL_VERSION', '2.0.0');
}
