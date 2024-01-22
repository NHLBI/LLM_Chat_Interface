<?php
// Include the database connection file
require_once 'lib.required.php';
require_once 'db.php';

// Check if the 'chat_id' and 'title' variables are set in the $_POST array
if(isset($_POST['chat_id']) && isset($_POST['title'])) {
    // get the value of the 'chat_id' variable from the $_post array
    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);

    // get the value of the 'title' variable from the $_post array
    $new_title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);

    if (!$chat_id || !$new_title) {
        die("invalid input");
    }

    if (!verify_user_chat($user, $chat_id)) {
        die("unauthorized");
    }
    
    // prepare a sql statement to update the title of a chat where the id matches the $chat_id
    // using prepared statements ensures that user inputs are escaped correctly, preventing sql injection
    $stmt = $pdo->prepare("update chat set title = :title where id = :id");
    $stmt->execute(['title' => $new_title, 'id' => $chat_id]);
}

