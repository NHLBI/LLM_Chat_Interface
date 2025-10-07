<?php
/**
 * get_config.php
 *
 * Chooses the correct INI file based on where the code is running:
 *   • Web request → look at $_SERVER['REQUEST_URI']
 *   • CLI / cron   → look at the script directory (__DIR__)
 *
 * Results:
 *   /etc/apps/chatdev_config.ini   (environment = dev)
 *   /etc/apps/chattest_config.ini  (environment = test)
 *   /etc/apps/chatprod_config.ini  (environment = prod, default)
 */

$explicit_path = getenv('CHAT_CONFIG_PATH');
if ($explicit_path === false || $explicit_path === '') {
    if (defined('CHAT_CONFIG_PATH') && CHAT_CONFIG_PATH !== '') {
        $explicit_path = CHAT_CONFIG_PATH;
    }
}

if ($explicit_path !== false && $explicit_path !== '') {
    $config_file = $explicit_path;
    $environment = 'custom';
} else {
    # 1.  Default to production
    $environment = '';

    # 2.  If we're in a web context, key off REQUEST_URI
    if (!empty($_SERVER['REQUEST_URI'])) {
        $uri = $_SERVER['REQUEST_URI'];
        if (strpos($uri, 'chatdev')  !== false) {
            $environment = 'dev';
        } elseif (strpos($uri, 'chattest') !== false) {
            $environment = 'test';
        }

    # 3.  Otherwise (CLI / cron), look at the directory path of this file
    } else {
        $dir = __DIR__;                                 // e.g. /var/www/ai.nhlbi.nih.gov/chatdev
        if (strpos($dir, 'chatdev')  !== false) {
            $environment = 'dev';
        } elseif (strpos($dir, 'chattest') !== false) {
            $environment = 'test';
        }
    }

    # 4.  Load the appropriate INI file
    $config_file = "/etc/apps/chat{$environment}_config.ini";
}

if (!is_readable($config_file)) {
    throw new RuntimeException("Config file not found for environment '{$environment}': {$config_file}");
}

$config = parse_ini_file($config_file, true);

