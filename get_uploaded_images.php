<?php
// Include required files and database connection
require_once 'lib.required.php';

$chat_id     = filter_input(INPUT_GET, 'chat_id');
$images_only = filter_input(INPUT_GET, 'images_only', FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

// Default to “images only” if flag isn’t provided
if (!$chat_id) {
    echo json_encode([]);
    exit;
}

if ($images_only === null) {
    $images_only = true;
}

/*
echo "this is the chat id: '{$chat_id}'\n";
echo "this is the user: '{$user}'\n";
echo "this is the images only: '{$images_only}'";
*/

echo json_encode(get_chat_documents($user, $chat_id, $images_only));

