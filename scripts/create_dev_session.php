#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This helper must be run from the CLI.\n");
    exit(1);
}

$userId = $argv[1] ?? ('accessibility.user.' . date('YmdHis'));
$sessionId = bin2hex(random_bytes(16));

session_id($sessionId);
require_once __DIR__ . '/../session_init.php';
require_once __DIR__ . '/../get_config.php';

if (!isset($_SESSION['user_data'])) {
    $_SESSION['user_data'] = [];
}
$_SESSION['user_data']['userid'] = $userId;

$_SESSION['authorized'] = true;
$_SESSION['splash'] = 'acknowledged';
$_SESSION['tokens']['access_token'] = $_SESSION['tokens']['access_token'] ?? 'dev-token';
$_SESSION['LAST_ACTIVITY'] = time();
$_SESSION['LAST_REGEN'] = time();
$_SESSION['deployment'] = $_SESSION['deployment'] ?? ($config['azure']['default'] ?? 'azure-gpt4-turbo');
$_SESSION['temperature'] = $_SESSION['temperature'] ?? '0.7';
$_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
$_SESSION['verbosity'] = $_SESSION['verbosity'] ?? 'medium';

session_write_close();

echo 'PHPSESSID=' . $sessionId . PHP_EOL;
