<?php

# Using crontab: 

$_SERVER['REQUEST_URI'] = 'https://ai.nhlbi.nih.gov/chatdev/';

require_once 'db.php';

$soft_delay = $config['app']['soft_delay'];
$purge_delay = $config['app']['purge_delay'];

$deleted_rows = delete_old_chats($purge_delay); // You can specify a different number of months if needed

if ($deleted_rows !== false) {
    if ($deleted_rows > 0) {
        error_log("Successfully deleted $deleted_rows old chats.", 0);
    } else {
        error_log("No old chats to delete.", 0);
    }
} else {
    error_log("Failed to delete old chats.", 0);
}

$soft_deleted_rows = soft_delete_old_chats($soft_delay); // You can specify a different number of months if needed

if ($soft_deleted_rows !== false) {
    if ($soft_deleted_rows > 0) {
        error_log("Successfully soft deleted $soft_deleted_rows old chats.", 0);
    } else {
        error_log("No old chats to soft delete.", 0);
    }
} else {
    error_log("Failed to soft delete old chats.", 0);
}

