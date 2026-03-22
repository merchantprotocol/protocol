<?php
return [
    'shell' => [
        'outputfile' => (getenv('HOME') ?: ($_SERVER['HOME'] ?? '/tmp')) . '/.protocol/.node/background_process.log',
    ],
    'repo_dir' => '/opt/public_html',
    'banner_file' => 'templates/banner/motd.sh'
];
