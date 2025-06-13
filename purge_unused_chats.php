<?php
require 'get_config.php';
require 'db.php';
$pdo = get_connection();

date_default_timezone_set('America/New_York');  // or your TZ


$soft_delay_days  = (int)$config['app']['soft_delay'];   // 90
$hard_delay_days  = (int)$config['app']['purge_delay'];  // 90

echo "CONFIG soft_delay_days = {$soft_delay_days}\n";
echo "CONFIG hard_delay_days = {$hard_delay_days}\n";


// 1) Hard delete
$hardDeleted = hard_delete_old_chats($config['app']['purge_delay']);
if ($hardDeleted === false) {
    error_log("[".date('Y-m-d H:i:s')."] Hard delete failed.");
} elseif (empty($hardDeleted)) {
    error_log("[".date('Y-m-d H:i:s')."] No chats qualified for hard delete.");
} else {
    $ids = implode(', ', $hardDeleted);
    error_log(
      "[".date('Y-m-d H:i:s')."] Hard‐deleted chats (".count($hardDeleted)."): IDs [{$ids}]."
    );
}

// 2) Soft delete
$softDeleted = soft_delete_old_chats($config['app']['soft_delay']);
if ($softDeleted === false) {
    error_log("[".date('Y-m-d H:i:s')."] Soft delete failed.");
} elseif (empty($softDeleted)) {
    error_log("[".date('Y-m-d H:i:s')."] No chats qualified for soft delete.");
} else {
    $ids = implode(', ', $softDeleted);
    error_log(
      "[".date('Y-m-d H:i:s')."] Soft‐deleted chats (".count($softDeleted)."): IDs [{$ids}]."
    );
}

