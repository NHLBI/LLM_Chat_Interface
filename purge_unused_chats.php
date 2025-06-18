<?php
require 'get_config.php';
require 'db.php';
$pdo = get_connection();

date_default_timezone_set('America/New_York');  // or your TZ

error_log("\n[".date('Y-m-d H:i:s')."] Database used: ".$config['database']['dbname']);

// Calculate thresholds up front
$soft_delay_days  = (int)$config['app']['soft_delay'];
$hard_delay_days  = (int)$config['app']['purge_delay'];

$hard_threshold = (new DateTime())->modify("-{$hard_delay_days} days")->format('Y-m-d');
$soft_threshold = (new DateTime())->modify("-{$soft_delay_days} days")->format('Y-m-d H:i:s');

/*
echo "CONFIG soft_delay_days = {$soft_delay_days}\n";
echo "CONFIG hard_delay_days = {$hard_delay_days}\n";
echo "Hard delete threshold: $hard_threshold\n";
echo "Soft delete threshold: $soft_threshold\n";
*/


// 1) Hard delete
$hardDeleted = hard_delete_old_chats($hard_threshold);
if ($hardDeleted === false) {
    error_log("[".date('Y-m-d H:i:s')."] Hard delete failed using threshold: $hard_threshold");
} elseif (empty($hardDeleted)) {
    error_log("[".date('Y-m-d H:i:s')."] No chats qualified for hard delete using threshold: $hard_threshold");
} else {
    $pairs = implode(', ', $hardDeleted);      // now already user:id
    error_log(
      "[".date('Y-m-d H:i:s')."] Hard-deleted chats ("
      .count($hardDeleted)."): IDs [{$pairs}] using threshold: $hard_threshold"
    );

}

// 2) Soft delete
$softDeleted = soft_delete_old_chats($soft_threshold);
if ($softDeleted === false) {
    error_log("[".date('Y-m-d H:i:s')."] Soft delete failed using threshold: $soft_threshold");
} elseif (empty($softDeleted)) {
    error_log("[".date('Y-m-d H:i:s')."] No chats qualified for soft delete using threshold: $soft_threshold");
} else {
    $pairs = implode(', ', $softDeleted);      // user:id pairs
    error_log(
      "[".date('Y-m-d H:i:s')."] Soft-deleted chats ("
      .count($softDeleted)."): IDs [{$pairs}] using threshold: $soft_threshold"
    );

}

