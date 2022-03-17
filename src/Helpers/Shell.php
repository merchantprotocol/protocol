<?php

namespace Gitcd\Helpers;

Class Shell {

    /**
     * Runs the shell command and returns the result
     *
     * @param [string] $command
     * @param [int] $return_var
     * @return void
     */
    public static function run( $command, &$return_var = null )
    {
        $response = null;
        exec("$command 2>&1", $response, $return_var);

        if (is_array($response)) {
            $response = implode(PHP_EOL, $response);
        }

        return $response;
    }
}