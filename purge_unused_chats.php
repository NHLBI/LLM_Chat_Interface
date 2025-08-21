<?php
require 'get_config.php';
require 'db.php';
$pdo = get_connection();

// Determine log file path based on environment
$logFile = '/var/log/chat_dev';
if (isset($config['environment'])) {
    switch ($config['environment']) {
        case 'dev':
            $logFile .= '/purge_chats.log';
            break;
        case 'test':
            $logFile .= '/purge_chats.log';
            break;
        case 'prod':
        default:
            $logFile .= '/purge_chats.log';
            break;
    }
} else {
    // Default to prod if environment is not set
    $logFile .= '/purge_chats.log';
}

date_default_timezone_set('America/New_York');  // or your TZ

// Log the database being used
error_log(
    "\n[" . date('Y-m-d H:i:s') . '] Database used: ' . $config['database']['dbname'] . "\n",
    3,
    $logFile
);

// Calculate thresholds up front
$soft_delay_days = (int)$config['app']['soft_delay'];
$hard_delay_days = (int)$config['app']['purge_delay'];

$hard_threshold = (new DateTime())
                    ->modify("-{$hard_delay_days} days")
                    ->format('Y-m-d');
$soft_threshold = (new DateTime())
                    ->modify("-{$soft_delay_days} days")
                    ->format('Y-m-d');

// 1) Hard delete
$hardDeleted = hard_delete_old_chats($logFile, $hard_threshold);
if ($hardDeleted === false) {
    error_log(
        '[' . date('Y-m-d') . "] Hard delete failed using threshold: $hard_threshold\n",
        3,
        $logFile
    );
} elseif (empty($hardDeleted)) {
    error_log(
        '[' . date('Y-m-d') . "] No chats qualified for hard delete using threshold: $hard_threshold\n",
        3,
        $logFile
    );
} else {
    $pairs = implode(', ', $hardDeleted);
    error_log(
        '[' . date('Y-m-d') . '] Hard-deleted chats (' . count($hardDeleted) .
        "): IDs [$pairs] using threshold: $hard_threshold\n",
        3,
        $logFile
    );
}

// 2) Soft delete
$softDeleted = soft_delete_old_chats($logFile, $soft_threshold);
if ($softDeleted === false) {
    error_log(
        '[' . date('Y-m-d') . "] Soft delete failed using threshold: $soft_threshold\n",
        3,
        $logFile
    );
} elseif (empty($softDeleted)) {
    error_log(
        '[' . date('Y-m-d') . "] No chats qualified for soft delete using threshold: $soft_threshold\n",
        3,
        $logFile
    );
} else {
    $pairs = implode(', ', $softDeleted);
    error_log(
        '[' . date('Y-m-d') . '] Soft-deleted chats (' . count($softDeleted) .
        "): IDs [$pairs] using threshold: $soft_threshold\n",
        3,
        $logFile
    );
}

