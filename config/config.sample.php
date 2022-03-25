<?php
return [
    // The remote git repo url for your application
    'remote' => 'git@github.com:merchantprotocol/some-repo-to-keep-up-to-date.git',

    // The path, relative or absolute to the local git repo of your application
    'localdir' => '../path-to-local-directory/where-repo-is-located',

    'docker' => [
        // Your docker.com username
        'username'  => 'docker.com-username',

        // Logging in with your password grants your terminal complete access to your account.
        // For better security, log in with a limited-privilege personal access token. 
        // Learn more at https://docs.docker.com/go/access-tokens/
        'password'  => 'docker.com-password-or-token',

        // The image tagname for the remote docker repository
        'image'     => 'byrdziak/merchantprotocol-webserver-nginx-php7.4:initial',
    ],

    // Allows you to define the banner message on login
    'banner_file' => '/path-to-banner-file/for-ssh-login-message'
];