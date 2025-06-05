<?php
// Include required files and database connection
require_once 'lib.required.php';

#print_r($_SESSION);
#print_r($_REQUEST);
#echo "USER = $user\n";

// If a 'chat_id' parameter was passed, store its value as an integer in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_STRING);

if (!$chat_id) {
    die(json_encode([]));
}

// Example usage
if (!update_last_viewed($chat_id)) {
    error_log("Failed to update last viewed time for chat ID: $chat_id", 0);
}

// Get the recent chat messages using the 'get_recent_messages()' function and encode them as a JSON object to be returned to the client
$output = json_encode(get_recent_messages($chat_id,$user));

#echo '<pre>'.print_r($output,1).'<pre>'; die();
    switch (json_last_error()) {
        case JSON_ERROR_NONE:
            //echo ' - No errors';
        break;
        case JSON_ERROR_DEPTH:
            echo ' - Maximum stack depth exceeded';
        break;
        case JSON_ERROR_STATE_MISMATCH:
            echo ' - Underflow or the modes mismatch';
        break;
        case JSON_ERROR_CTRL_CHAR:
            echo ' - Unexpected control character found';
        break;
        case JSON_ERROR_SYNTAX:
            echo ' - Syntax error, malformed JSON';
        break;
        case JSON_ERROR_UTF8:
            echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
        break;
        default:
            echo ' - Unknown JSON error';
        break;
    }

echo $output;
