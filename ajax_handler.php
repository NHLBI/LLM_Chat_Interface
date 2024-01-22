<?php
// Include required files and database connection
require_once 'lib.required.php';
require_once 'db.php';

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get the user's message from the POST data
    $user_message = base64_decode($_POST['message']); // Decode from Base64
    //$user_message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);

    #print_r($_POST);

    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);

    $new_chat_id = '';
    $document_name = (empty($_SESSION['document_name'])) ? '' : $_SESSION['document_name'];
    $document_text = (empty($_SESSION['document_text'])) ? '' : $_SESSION['document_text'];

    if (empty($chat_id)) {
        // Create new chat session
        $chat_id = $new_chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment'], $document_name, $document_text);
    }

    #echo "THIS IS THE deployment: " . $deployment . "\n";
    #echo "THIS IS THE config: " . print_r($config,1) . "\n";
    #echo "THIS IS THE session: " . print_r($_SESSION,1) . "\n";
    #echo "THIS IS THE AJAX HANDLER CHAT ID: " . $chat_id . "\n";
    #echo "THIS IS THE AJAX HANDLER NEW CHAT ID: " . $new_chat_id . "\n";

    // Get the GPT response to the user's message using the get_gpt_response() function
    $gpt_response = get_gpt_response($user_message, $chat_id, $user);
    #echo "THIS IS THE GPT Response: " . print_r($gpt_response,1); die();

    $response = [
        'deployment' => $gpt_response['deployment'], 
        'error' => $gpt_response['error'],
        'gpt_response' => $gpt_response['message'], 
        'chat_id' => $chat_id,
        'new_chat_id' => $new_chat_id
    ];

    // Send the GPT response as a JSON-encoded string and exit the script
    echo json_encode($response);
    exit();
}

