<?php
// Include required files and database connection
require_once 'lib.required.php';
require_once 'db.php';

#print_r($_SESSION);
#print_r($_REQUEST);
#echo "USER = $user\n";

// If a 'chat_id' parameter was passed, store its value as an integer in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_STRING);

if (!$chat_id) {
    die(json_encode([]));
}

if (!verify_user_chat($user, $chat_id)) {
    die("Unauthorized to get user chat history");
}

// Get the recent chat messages using the 'get_recent_messages()' function and encode them as a JSON object to be returned to the client
echo json_encode(get_recent_messages($chat_id,$user));

