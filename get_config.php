<?php

// Determine the environment dynamically
$environment = '';

if (!empty($_SERVER['REQUEST_URI'])) {
    if (strpos($_SERVER['REQUEST_URI'], 'chatdev')) {
        $environment = 'dev';
    } elseif (strpos($_SERVER['REQUEST_URI'], 'chattest')) {
        $environment = 'test';
    }
}

$config_file = '/etc/apps/chat' . $environment . '_config.ini';
$config = parse_ini_file($config_file,true);


