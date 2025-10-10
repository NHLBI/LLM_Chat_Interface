<?php
// Include required files and establish the database connection
require_once 'bootstrap.php';

if (empty($_SESSION['user_data']['userid']) || empty($_SESSION['authorized'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => 'Session expired. Please refresh the page.',
        'code' => 'session_expired'
    ]);
    exit;
}
require_once 'db.php';

define('HARDCODED_DEPLOYMENT','azure-gpt4.1-mini');

$chatTitleService = new ChatTitleService();

$user = $_SESSION['user_data']['userid'] ?? null; // Assuming you have a session variable for username

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Get the user's message from the POST data
    $user_message = base64_decode($_POST['message']); // Decode from Base64

    // Provide a default if needed
    $deployment = isset($_POST['deployment']) ? $_POST['deployment'] : 'default_deployment'; 

    // Provide a default exchange_type if needed
    $exchange_type = isset($_POST['exchange_type']) ? $_POST['exchange_type'] : 'chat'; 

    if (isset($_POST['custom_config'])) {
        $custom_config = json_decode($_POST['custom_config'],true);
    } else {
        $custom_config = array();
    }

    $custom_config['exchange_type'] = $exchange_type;
    //$custom_config = json_encode($custom_config);
    

    // Retrieve the chat ID from the POST data
    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);

    // Initialize variables for new chat creation
    $new_chat_id = '';

    // Create a new chat session if no chat ID is provided
    if (empty($chat_id)) {
        $need_title = true;

        // The $new_chat_id will tell Javascript to reload the page to show the new title. 
        $id = $new_chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment']);
    } else {
        $need_title = (get_new_title_status($user, $chat_id)) ? true : false;
  
        $id = $chat_id;
    }

    /*
    echo "THIS IS THE custom_config: " . print_r($custom_config,1) . "\n";
    echo "THIS IS THE exchange_type: " . $exchange_type . "\n";
    echo "THIS IS THE deployment: " . $deployment . "\n";
    #echo "THIS IS THE config: " . print_r($config,1) . "\n";
    #echo "THIS IS THE session: " . print_r($_SESSION,1) . "\n";
    echo "THIS IS THE AJAX HANDLER CHAT ID: " . $chat_id . "\n";
    echo "THIS IS THE AJAX HANDLER NEW CHAT ID: " . $new_chat_id . "\n";
    echo "THIS IS THE USER MESSAGE: " . $user_message . "\n";
    die();
    */

    // Get the GPT response to the user's message using the get_gpt_response() function
    $gpt_response = get_gpt_response($user_message, $id, $user, $deployment, $custom_config);
    #echo "THIS IS THE GPT Response: <pre>" . print_r($gpt_response,1)."</pre>"; die();

    if (!empty($gpt_response['error']) && $gpt_response['error'] == 1) {
        #$gpt_response['message'] = preg_replace('/Please go here.*/','',$gpt_response['message']);
    }

    // Generate a concise chat title if a new chat was created and there were no errors in the GPT response
    if ($need_title && empty($gpt_response['error'])) {

        if ($custom_config['exchange_type'] == 'workflow' && !empty($custom_config['config']['chat-title-replacement'])) {
            $chat_title = $custom_config['config']['chat-title-replacement'];
        } else {
            $chat_title = $chatTitleService->generate(
                $user_message,
                $gpt_response['message'] ?? '',
                HARDCODED_DEPLOYMENT,
                $id
            );
        }
        // Update the chat title in the database if the title was successfully generated
        if ($chat_title !== null) {
            update_chat_title($user, $id, $chat_title);
        }
    }

    if (!empty($gpt_response['deployment'])) {
        $deployment = $gpt_response['deployment'];
    }

    // Prepare the response data to send back to the client
    $response = [
        'eid' => $gpt_response['eid'] ?? null,
        'deployment' => $deployment ?? null, 
        'error' => $gpt_response['error'] ?? null,
        'gpt_response' => $gpt_response['message'] ?? null,
        'chat_id' => $chat_id,
        'new_chat_id' => $new_chat_id,
        'image_gen_name' => $gpt_response['image_gen_name'] ?? null // Include image filename
    ];

    #echo "THIS IS THE GPT Response: " . print_r($response,1); die();

    // Send the JSON-encoded response and exit the script
    echo json_encode($response);
    exit();
}
