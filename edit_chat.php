<?php
// Include the database connection file
require_once 'lib.required.php';
require_once 'db.php';

// Check if the 'chat_id' and 'title' variables are set in the $_POST array
if(isset($_POST['chat_id']) && isset($_POST['title'])) {
    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);
    $new_title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);

    if (!$chat_id || !$new_title) {
        die("invalid input");
    }

    // Use the existing update_chat_title function
    update_chat_title($user, $chat_id, $new_title);
}

