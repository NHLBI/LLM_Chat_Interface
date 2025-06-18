<?php

require_once __DIR__ . '/session_init.php';
require_once 'get_config.php'; // Determine the environment dynamically
#echo '<pre>'.print_r($config,1).'</pre>';

// lib.required.php
require_once 'db.php';

$pdo = get_connection();

// Before proceeding, check if the session has the required user data.
// If not, let’s give it a chance to appear.
if (!waitForUserSession()) {
    // If after waiting the session is still missing the user id, load the splash page.
    require_once 'splash.php';
    exit;
}

// Handle the splash screen
if (empty($_SESSION['splash'])) $_SESSION['splash'] = '';

if ((!empty($_SESSION['user_data']['userid']) && $_SESSION['authorized'] !== true) || empty($_SESSION['splash'])) {
    require_once 'splash.php';
    exit;
}

// Start the PHP session to enable session variables
$sessionTimeout = $config['session']['timeout'];  // Load session timeout from config

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $sessionTimeout)) {
    // Last request was more than timeout ago
    logout();
}
$_SESSION['LAST_ACTIVITY'] = time(); // Update last activity time stamp

// Initialize user data if not set
if (empty($_SESSION['user_data'])) $_SESSION['user_data'] = [];

$user = (empty($_SESSION['user_data']['userid'])) ? '' : $_SESSION['user_data']['userid'];

$application_path = $config['app']['application_path'];

// Confirm authentication, redirect if false
if (isAuthenticated()) {
    if (empty($_SESSION['LAST_REGEN']) || (time() - $_SESSION['LAST_REGEN'] > 900)) {
        session_regenerate_id(true);
        $_SESSION['LAST_REGEN'] = time();
    }

} else {
    header('Location: auth_redirect.php');
    exit;
}

// Verify that there is a chat with this id for this user
// If a 'chat_id' parameter was passed, store its value as a string in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!verify_user_chat($user, $chat_id)) {
    echo " -- " . htmlspecialchars($user) . "<br>\n";
    die("Error: there is no chat record for the specified user and chat id. If you need assistance, please contact " . htmlspecialchars($config['app']['emailhelp']));
}

// Parse models from configuration
$models_str = $config['azure']['deployments'];
$models_a = explode(",", $models_str);

$models = array();
foreach ($models_a as $m) {
    $a = explode(":", $m);
    $models[$a[0]] = array('label' => $a[1]) + $config[$a[0]];
}
#echo "<pre>".print_r($models,1)."</pre>";die();

// Define temperature options
$temperatures = [];
$i = 0;
while ($i < 2.1) {
    $temperatures[] = round($i, 1);
    $i += 0.1;
}

// Initialize chat_id if not set
if (empty($_GET['chat_id'])) $_GET['chat_id'] = '';

// Handle model selection
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    #print_r($_POST);
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

// Retrieve all chats for the user
$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    #echo "2 This is the deployment: {$deployment}<br>\n";
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];  // Currently active chat
    #echo "3 This is the deployment: {$deployment}<br>\n";
    $_SESSION['temperature'] = (empty($all_chats[$chat_id]['temperature'])) ? '0.7' : $all_chats[$chat_id]['temperature'];
}

// Set default deployment if not already set
if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];
    #echo "4 This is the deployment: {$deployment}<br>\n";
} else {
    $deployment = $_SESSION['deployment'];
    #echo "5 This is the deployment: {$deployment}<br>\n";
}

$context_limit = $config[$deployment]['context_limit'];

#echo "1 This is the deployment: {$deployment}<br>\n";
#echo '<pre>'.print_r($config[$deployment],1).'</pre>';

// Handle temperature selection
if (isset($_POST['temperature'])) {
    $_SESSION['temperature'] = (float)$_POST['temperature'];
    $temperature = $_SESSION['temperature'];
    if (!empty($_GET['chat_id'])) update_temperature($user, $chat_id, $temperature);
}

// Set default temperature if not set or out of bounds
if (!isset($_SESSION['temperature']) || (float)$_SESSION['temperature'] < 0 || (float)$_SESSION['temperature'] > 2) {
    $_SESSION['temperature'] = 0.7;
}



// Uncomment for debugging
// echo "<pre>". print_r($_SESSION,1) ."</pre>";
// echo "<pre>". print_r($_SERVER,1) ."</pre>";

// Helper function to wait for critical session data
function waitForUserSession($maxAttempts = 5, $delayMicroseconds = 5000) {
    $attempt = 0;
    // Check if the user_data userid is set; if not, wait and retry
    while ($attempt < $maxAttempts && empty($_SESSION['user_data']['userid'])) {
        usleep($delayMicroseconds);
        $attempt++;
        $delayMicroseconds += 5000;
    }
    return !empty($_SESSION['user_data']['userid']);
}

/**
 * Retrieves the GPT response by orchestrating the API call and processing the response.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param string $deployment The deployment identifier.
 * @return array The processed API response.
 */
function get_gpt_response($message, $chat_id, $user, $deployment, $custom_config) {
    $active_config = load_configuration($deployment);
    if (!$active_config) {
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => 'Invalid deployment configuration.'
        ];
    }

    if (false) {
        // 1. Call Python script to get augmented prompt
        $rag_result = call_rag_script($message); // New helper function

        // 2. Check for errors from the script
        if (isset($rag_result['error'])) {
            return process_error_response("FAR RAG Error: " . $rag_result['error'], $deployment, $chat_id, $message, []);
        }

        // 3. Prepare messages payload using the augmented prompt
        // We still use the 'chat' infrastructure but with the special prompt
        $message = $rag_result['augmented_prompt'];
    }

    #print("this is custom stuff: ".print_r($custom_config,1)); die();
    if ($custom_config['exchange_type'] == 'chat') $msg = get_chat_thread($message, $chat_id, $user, $active_config);
    elseif ($custom_config['exchange_type'] == 'workflow') $msg = get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config);
    else return process_api_response('There was an error processing your post. Please contact support.', $active_config, $chat_id, $message, $msg);
   

    if ($active_config['host'] == "Mocha") {
        $response = call_mocha_api($active_config['base_url'], $msg);
    } else {
        $response = call_azure_api($active_config, $chat_id, $msg);
    }
    #echo "<pre>Step get_gpt_response()\n".print_r($response,1)."</pre>"; die();

    return process_api_response($response, $active_config, $chat_id, $message, $msg, $custom_config);
}

/**
 * Loads the configuration based on the deployment name.
 *
 * @param string $deployment The deployment identifier.
 * @return array|null The configuration array or null if not found.
 */
function load_configuration($deployment, $hardcoded = false) {
    global $config;

    if ($hardcoded) {
        $config[$deployment]['enabled'] = true;
    }

    // Check if the deployment is enabled
    if (!isset($config[$deployment]['enabled']) || $config[$deployment]['enabled'] == false || $config[$deployment]['enabled'] === 'false') {
        // Reassign deployment to default if not enabled
        $_SESSION['deployment'] = $GLOBALS['deployment'] = $deployment = $config['azure']['default'];
    }

    $_SESSION['api_key'] = $config[$deployment]['api_key'];

    $output = [
        'api_key' => trim($config[$deployment]['api_key'], '"'),
        'host' => $config[$deployment]['host'],
        'base_url' => $config[$deployment]['url'],
        'deployment_name' => $config[$deployment]['deployment_name'],
        'api_version' => $config[$deployment]['api_version'],
        'context_limit' => (int)($config[$deployment]['context_limit']),
    ];
    if (!empty($config[$deployment]['max_tokens'])) $output['max_tokens'] = (int)$config[$deployment]['max_tokens'];
    if (!empty($config[$deployment]['max_completion_tokens'])) $output['max_completion_tokens'] = (int)$config[$deployment]['max_completion_tokens'];
    // print_r($output);
    return $output;
}

/**
 * Helper function to call the Python RAG script.
 *
 * @param string $user_question The question to pass to the script.
 * @return array Decoded JSON output from the script (e.g., ['augmented_prompt' => ...] or ['error' => ...]).
 */
function call_rag_script($user_question) {
    global $config; // Access global config for paths

    // --- Retrieve Paths from Config (Ensure these are set!) ---
    $python_executable = __DIR__.'/rag310/bin/python3';
    $script_path = __DIR__.'/rag_processor.py';

    if (!$script_path || !file_exists($script_path)) {
        return ['error' => 'RAG script path not configured or not found. Path: ' . $script_path];
    }
    if (!is_executable($python_executable) && !preg_match('/^python[3]?$/', $python_executable)) {
         // Basic check if it's not 'python'/'python3' and not executable directly
         // A more robust check might involve `shell_exec("command -v $python_executable")`
         // but let's keep it simple for now. Adjust if needed.
        // return ['error' => 'Python executable not found or not executable: ' . $python_executable];
        // Allow 'python3' assuming it's in PATH
    }


    // --- Prepare Command ---
    // Use escapeshellarg to safely pass the user question
    $escaped_question = escapeshellarg($user_question);
    $command = $python_executable . ' ' . escapeshellarg($script_path) . ' ' . $escaped_question . ' 2>&1'; // Redirect stderr to stdout

    // --- Execute Command ---
    // Consider adding timeout logic if the script might hang
    $output = [];
    $return_var = -1;
    exec($command, $output, $return_var);

    $raw_output = implode("\n", $output); // Combine output lines

    // --- Process Output ---
    if ($return_var !== 0) {
        // Script exited with an error code
        return ['error' => "RAG script execution failed (Exit Code: $return_var). Output: " . $raw_output];
    }

    // Attempt to decode JSON output
    $json_result = json_decode($raw_output, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Output was not valid JSON
        return ['error' => "Failed to decode JSON response from RAG script. Raw output: " . $raw_output];
    }

    // Return the decoded JSON (should contain 'augmented_prompt' or 'error')
    return $json_result;
}

/**
 * Helper function to process and return standardized error responses.
 */
function process_error_response($error_message, $deployment, $chat_id, $original_message, $msg_context) {
     // Log the error internally if needed
     error_log("Error in get_gpt_response: $error_message (Deployment: $deployment, ChatID: $chat_id)");

     // Return structure similar to process_api_response but indicating an error
     // Mimic structure of process_api_response for consistency on the frontend
     return [
         'deployment' => $deployment,
         'error' => true,
         'message' => $error_message, // Error message for the user
         'request' => [ // Include some request context for debugging if helpful
             'original_message' => $original_message,
             'context_messages' => $msg_context // The attempted message structure
         ],
         'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], // Default usage
         'finish_reason' => 'error'
     ];
}


/**
 * Constructs the chat thread based on the active configuration.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @return array The messages array to be sent to the API.
 */
function get_chat_thread($message, $chat_id, $user, $active_config) {

    if ($active_config['host'] === 'dall-e') {
        return array('prompt'=>$message); // Handle dall-E Image Generation Requests
        
    } else {
        // Handle Chat Completion Requests
        return handle_chat_request($message, $chat_id, $user, $active_config);
    }
}

function get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config) {

    #$custom_config = json_decode($custom_config,1);
    #print_r($custom_config); die();
    if (empty($custom_config['workflowId'])) $custom_config['workflowId'] = '';

    // Build the system message
    $workflow_data = get_workflow_data($custom_config['workflowId']);
    $resource = $workflow_data['content'];
    $message = $workflow_data['prompt'];

    #print_r($resource);
    
    // Check and handle any document content first
    $document_messages = handle_document_content($chat_id, $user, $message, $active_config);

    $system_message = [
        [
            'role' => 'user',
            'content' => $resource
        ]
    ];

    if ($document_messages !== null) {
        // If a document is present, use document messages exclusively
        $messages = array_merge($system_message, $document_messages);
    } else {
        $message = 'There was an error processing your post. Please contact support.';
        $messages[] = ["role" => "user", "content" => $message];
    }

    #print_r($messages); die("STOPPED IN THE GET WORKFLOW THREAD FUNCTION\n");

    return $messages;
}

/**
 * Calls the Mocha API.
 *
 * @param string $base_url The base URL for the Mocha API.
 * @param mixed $msg The message payload.
 * @return string The API response.
 */
function call_mocha_api($base_url, $msg) {
    global $config;
    
    $payload = [
        "model" => "llama3:70b",
        'messages' => $msg,
        "max_tokens" => $config['max_tokens'],
        "temperature" => (float)$_SESSION['temperature'],
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 0.95,
        "stop" => ""
    ];
    $headers = ['Content-Type: application/json'];
    $response = execute_api_call($base_url, $payload, $headers);
    return $response;
}

/**
 * Calls the Azure OpenAI API.
 *
 * @param array $active_config The active deployment configuration.
 * @param mixed $msg           The message payload.
 * @param string $chat_id
 * @param string $message      The original user message
 * @return string              The API response.
 */
function new_maybe_call_azure_api($active_config, $chat_id, $msg, $message) {
    // … existing code to choose $url …

    // --- NEW: adjust max_completion_tokens based on context_limit and doc tokens ---
    // 1) pull your documents (with token lengths) back out of the DB
    $docs = get_chat_documents($_SESSION['user_data']['userid'], $chat_id);

    // 2) total up all document_token_length
    $docTokens = array_sum(array_column($docs, 'document_token_length'));

    // 3) count tokens in your new user message as well
    $promptTokens = get_token_count($message); // your existing Python helper

    // 4) apply your context limit (from config)
    $contextLimit = $active_config['context_limit'] ?? 8192;

    // 5) compute how many remain for the completion
    $remaining = $contextLimit - ($docTokens + $promptTokens);
    if ($remaining < 0) {
        $remaining = 0;
    }

    // 6) set max_completion_tokens to the lesser of configured max or what remains
    $maxCompletion = $active_config['max_completion_tokens'] ?? 256;
    $payload['max_tokens'] = $active_config['max_tokens'] ?? null;      // if you’re using max_tokens
    $payload['max_completion_tokens'] = min($maxCompletion, $remaining);

    // --- END NEW SECTION ---

    // your existing header/payload construction…
    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $active_config['api_key']
    ];
    $response = execute_api_call($url, $payload, $headers, $chat_id);
    return $response;
}


/**
 * Calls the Azure OpenAI API.
 *
 * @param array $active_config The active deployment configuration.
 * @param mixed $msg The message payload.
 * @return string The API response.
 */
function call_azure_api($active_config, $chat_id, $msg) {

    // 1) pull your documents (with token lengths) back out of the DB
    $docs = get_chat_documents($_SESSION['user_data']['userid'], $chat_id);

    // 2) total up all document_token_length
    $doc_tokens = array_sum(array_column($docs, 'document_token_length'));

    #echo "this is the document tokens: {$doc_tokens}\n"; die(print_r($docs,1));

    $is_dalle = ($active_config['host'] === 'dall-e');

    if ($is_dalle) {
        // dall-E Image Generation Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/images/generations?api-version=" . $active_config['api_version'];
        
        $payload = [
            'model' => $active_config['model'] ?? 'dall-e',
            'prompt' => $msg['prompt'],
            'n' => 1,
            'size' => '1024x1024'
        ];

    } else {
        // Chat Completion Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/chat/completions?api-version=" . $active_config['api_version'];
        $top_p = (preg_match('/o1|o3|o4/',$_SESSION['deployment'])) ? 1 : 0.95;
        
        $payload = [
            'messages' => $msg,
            "temperature" => (float)$_SESSION['temperature'],
            "frequency_penalty" => 0,
            "presence_penalty" => 0,
            "top_p" => $top_p
        ];
        if (!empty($active_config['max_tokens'])) {
            $payload['max_tokens'] = $active_config['max_tokens'];
        }
        if (!empty($active_config['max_completion_tokens'])) {
            /*
            echo "THIS IS THE CURRENT MAX COMPLETION: " . $active_config['max_completion_tokens']."\n";
            echo "THIS IS THE CURRENT CONTEXT LIMIT: " . $active_config['context_limit']."\n";
            echo "THIS IS THE CURRENT DOC TOKENS: " . $doc_tokens."\n";
            echo "THIS IS THE CONTEXT LIMIT MINUS DOC TOKENS: " . $active_config['context_limit'] - $doc_tokens."\n";
            echo "THIS IS THE MIN OF THOSE TWO: " . min($active_config['max_completion_tokens'], $active_config['context_limit'] - $doc_tokens)."\n";
            */
            $payload['max_completion_tokens'] = min($active_config['max_completion_tokens'], $active_config['context_limit'] - $doc_tokens);
            $payload['temperature'] = 1;
        }
    }

    #die("2. Final payload: ".print_r($payload,1));

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $active_config['api_key']
    ];
    $response = execute_api_call($url, $payload, $headers, $chat_id);
    return $response;
}

/**
 * Processes the API response by handling errors and extracting relevant information.
 *
 * @param string $response The raw API response.
 * @param array $active_config The active deployment configuration.
 * @param int $chat_id The chat identifier.
 * @param string $message The user message.
 * @param mixed $msg The message context sent to the API.
 * @return array The processed API response.
 */
function process_api_response($response, $active_config, $chat_id, $message, $msg, $custom_config) {

    $response_data = json_decode($response, true);

    if (isset($response_data['error'])) {
        $api_error_message = $response_data['error']['message'];
        log_error_details($msg, $message, $api_error_message);
        return [
            'deployment' => $active_config['deployment_name'],
            'error' => true,
            'message' => $api_error_message
        ];
    }

    if($custom_config['exchange_type'] == 'workflow' && !empty($custom_config['prompt-replacement-text'])) $message = $custom_config['prompt-replacement-text'];

    if ($active_config['host'] === 'dall-e') {
        $image_url = $response_data['data'][0]['url'] ?? null;

        if ($image_url) {
            preg_match('#/images/([^/]+)/generated_#', $image_url, $matches);
            $unique_dir = $matches[1] ?? uniqid();
            $image_gen_name = $chat_id . '-' . $unique_dir . '.png';

            $image_gen_dir = dirname(__DIR__) . '/image_gen';
            $fullsize_dir = $image_gen_dir . '/fullsize';
            $small_dir = $image_gen_dir . '/small';

            if (!is_dir($fullsize_dir)) mkdir($fullsize_dir, 0755, true);
            if (!is_dir($small_dir)) mkdir($small_dir, 0755, true);

            $fullsize_path = $fullsize_dir . '/' . $image_gen_name;
            $small_path = $small_dir . '/' . $image_gen_name;

            $image_data = @file_get_contents($image_url);
            if ($image_data !== false) {
                if (file_put_contents($fullsize_path, $image_data) === false) {
                    error_log("Failed to write fullsize image: $fullsize_path");
                } else {
                    scale_image_from_path($fullsize_path, $small_path, 0.5);
                    if (empty($custom_config['workflowId'])) $custom_config['workflowId'] = '';
                    $eid = create_exchange($chat_id, $message, '', $custom_config['workflowId'], $image_gen_name);

                    return [
                        'eid' => $eid,
                        'deployment' => $active_config['deployment_name'],
                        'error' => false,
                        'message' => 'Image generated successfully.',
                        'image_gen_name' => $image_gen_name
                    ];
                }
            } else {
                error_log("Failed to download image from URL: $image_url");
            }
        }
    }

    // Handle Chat Completion response
    $response_text = $response_data['choices'][0]['message']['content'] ?? 'No response text found.';
    if (empty($custom_config['workflowId'])) $custom_config['workflowId'] = '';
    $eid = create_exchange($chat_id, $message, $response_text, $custom_config['workflowId']);

    return [
        'eid' => $eid,
        'deployment' => $active_config['deployment_name'],
        'error' => false,
        'message' => $response_text
    ];
}

/**
 * Constructs the message array for Chat Completion.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @return array The messages array for Chat Completion.
 */
function handle_chat_request($message, $chat_id, $user, $active_config) {
    // Build the system message
    $system_message = build_system_message($active_config);
    
    // Check and handle any document content first
    $document_messages = handle_document_content($chat_id, $user, $message, $active_config);
    
    if ($document_messages !== null) {
        // If a document is present, use document messages exclusively
        $messages = array_merge($system_message, $document_messages);
    } else {
        // If no document, retrieve and format context messages
        $context_messages = retrieve_context_messages($chat_id, $user, $active_config, $message);
        
        // Initialize the messages array with system and context messages
        $messages = array_merge($system_message, $context_messages);
        
        // Append the current user message
        $messages[] = ["role" => "user", "content" => $message];
    }

    return $messages;
}

/**
 * Executes an API call using cURL.
 *
 * @param string $url The API endpoint URL.
 * @param array $payload The JSON payload to send.
 * @param array $headers The HTTP headers.
 * @return string The API response.
 */
function execute_api_call($url, $payload, $headers, $chat_id = '') {
    // Logging for debugging
    /*
    $date = new DateTime();
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);
    $log = 
    $date->format('Y-m-d H:i:s')."\n". 
    "Chat ID: ".$chat_id."\n".
    "URL: ".$url."\n".
    "Headers: ".$headers[1]."\n".
    "Prompt: ".substr($payload['messages'][count($payload['messages'])-1]['content'],0,100)."\n\n";
    #die($log);
    file_put_contents("mylog.log", $log, FILE_APPEND);
    */
    
    $_SESSION['api_endpoint'] = $url;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    // Uncomment for debugging
    #print($response); die();

    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        print('Curl error: ' . curl_error($ch));
        die("DEAD BECAUSE OF AN ERROR");
    }

    curl_close($ch);
    // Uncomment for debugging
    // print("GOT TO THE GET_EXECUTE API CALL FUNCTION");
    return $response;
}

/**
 * Constructs the system message for chat completions.
 *
 * @param array $active_config The active deployment configuration.
 * @return array The system message array.
 */
function build_system_message($active_config) {
    // Initialize DateTime with the specified timezone
    $date = new DateTime();
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);

    $about = clean_disclaimer_text();

    // Construct the system message with correct role and defined $date
    $system_message = [
        [
            'role' => 'user',
            'content' => 'You are NHLBI Chat, a helpful assistant for staff at the National Heart Lung and Blood Institute. In this timezone, ' . $date->getTimezone()->getName() . ', the current date and time of this prompt is ' . $date->format('Y-m-d H:i:s') . '. The user\'s browser has the preferred language (HTTP_ACCEPT_LANGUAGE) set to ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] . ', so please reply in that language if possible, unless directed otherwise. The following is the disclaimer / instruction text we present to users: <<<' . $about . '>>>. '
        ]
    ];

    return $system_message; 
}

/**
 * Retrieves and formats recent chat messages within the context limit.
 *
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @param string $message The current user message.
 * @return array The formatted context messages.
 */
function retrieve_context_messages($chat_id, $user, $active_config, $message) {
    $context_limit = (int)$active_config['context_limit'];
    $recent_messages = get_recent_messages($chat_id, $user);
    $total_tokens = estimate_tokens($message);
    $formatted_messages = [];

    foreach (array_reverse($recent_messages) as $msg) {
        if (stristr($msg['deployment'],'dall-e')) {
            $msg['reply'] = '';
            $msg['reply_token_length'] = 0;
        }
        $prompt_tokens = $msg['prompt_token_length'];
        $reply_tokens = $msg['reply_token_length'];
        $tokens_needed = $prompt_tokens + $reply_tokens + (4 * 2); // 4 tokens per message

        if ($total_tokens + $tokens_needed > $context_limit) {
            break; // Exceeds limit
        }

        // Prepend messages to maintain chronological order
        $formatted_messages[] = ['role' => 'user', 'content' => $msg['prompt']];
        $formatted_messages[] = ['role' => 'assistant', 'content' => $msg['reply']];

        #print_r($formatted_messages);
        #echo "\n\n\n";

        $total_tokens += $tokens_needed;
    }

    $output = array_reverse($formatted_messages); // Reverse to maintain original order
    #echo "HERE IS THE OUTPUT: \n\n";
    #print_r($output);
    #die();
    return $output;
}

/**
 * Processes document content from the session.
 *
 * Retrieves all documents related to the given chat, constructs
 * an LLM-ready messages array, and appends the user prompt at the end.
 *
 * @param string $chat_id The unique chat ID.
 * @param string $user The user ID.
 * @param string $message The current user message.
 * @param array $active_config The active deployment configuration.
 * @return array|null The messages array if documents exist, otherwise null.
 */
function handle_document_content($chat_id, $user, $message, $active_config) {
    $docs = get_chat_documents($user, $chat_id);
    # print_r($docs);

    if (!empty($docs)) {
        $messages = [];

        foreach ($docs as $i => $doc) {
            // Add document metadata
            $messages[] = [
                "role" => "user",
                "content" => "This is document #" . ($i + 1) . ". Its filename is: " . $doc['document_name']
            ];

            // Handle image documents separately
            if (strpos($doc['document_type'], 'image/') === 0) {
                $messages[] = [
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => $message],
                        ["type" => "image_url", "image_url" => ["url" => $doc['document_content']]]
                    ]
                ];
            } else {
                // Append text document content
                $messages[] = [
                    "role" => "user",
                    "content" => $doc['document_content']
                ];
            }
        }

        // Append the original user message after listing all documents
        $messages[] = [
            "role" => "user",
            "content" => $message
        ];

        return $messages;
    }

    return null; // Explicitly return null if no documents exist
}

/**
 * Fetches a remote image, saves it temporarily, and returns a base64 data URL.
 *
 * @param string $remote_url The remote image URL from dall-E.
 * @return array|null Returns ['data_url' => ..., 'mime_type' => ..., 'filename' => ...]
 *                    or null on failure.
 */
function fetch_remote_image_as_base64($remote_url) {
    // Create a temporary file in the system temp directory
    $temp_file = tempnam(sys_get_temp_dir(), 'dalle_');
    if (!$temp_file) {
        return null;
    }

    // Download the remote file
    $file_data = @file_get_contents($remote_url);
    if ($file_data === false) {
        // Could not fetch the remote image
        return null;
    }

    // Write the downloaded data to the temp file
    file_put_contents($temp_file, $file_data);

    // Attempt to detect the MIME type
    $mimeType = mime_content_type($temp_file) ?: 'application/octet-stream';

    // Convert local file to data URL
    $data_url = local_image_to_data_url($temp_file, $mimeType);

    // Generate a plausible filename (optional)
    $ext = '';
    // Basic extension guess
    if (preg_match('/image\/(\w+)/', $mimeType, $m)) {
        $ext = '.' . $m[1];
    }
    $filename = 'dalle_image' . $ext;

    // Return relevant details
    return [
        'data_url' => $data_url,
        'mime_type' => $mimeType,
        'filename' => $filename
    ];
}

function scale_image_from_path($src_path, $dest_path, $scaleFactor) {
    $image_data = @file_get_contents($src_path);
    if ($image_data === false) {
        error_log("Failed to read image for scaling: $src_path");
        return false;
    }

    $source_img = @imagecreatefromstring($image_data);
    if (!$source_img) {
        error_log("Invalid image format: $src_path");
        return false;
    }

    $orig_width = imagesx($source_img);
    $orig_height = imagesy($source_img);

    $new_width = (int)($orig_width * $scaleFactor);
    $new_height = (int)($orig_height * $scaleFactor);

    $dest_img = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($dest_img, $source_img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);

    $success = imagepng($dest_img, $dest_path);
    imagedestroy($source_img);
    imagedestroy($dest_img);

    if (!$success) {
        error_log("Failed to save scaled image: $dest_path");
    }

    return $success;
}

// Function to convert a local image to a base64 data URL
function local_image_to_data_url($image_path, $mimeType)
{
    // Fallback to application/octet-stream if MIME type is not set
    if ($mimeType === null) {
        $mimeType = "application/octet-stream";
    }

    // Open the image file in binary mode and encode it to base64
    $base64_encoded_data = base64_encode(file_get_contents($image_path));

    // Return the data URL with the appropriate MIME type
    return "data:$mimeType;base64,$base64_encoded_data";
}

function scale_base64_image($base64_data_url, $scaleFactor = 0.25) {
    // 1) Parse the base64 data URL
    if (!preg_match('/^data:(.*?);base64,(.*)$/', $base64_data_url, $matches)) {
        return null; // invalid data URL
    }
    $mimeType = $matches[1] ?? 'application/octet-stream';
    $base64   = $matches[2] ?? '';
    
    // 2) Decode to binary
    $imageData = base64_decode($base64);
    if ($imageData === false) {
        return null;
    }

    // 3) Create GD image resource from string
    $sourceImg = imagecreatefromstring($imageData);
    if (!$sourceImg) {
        return null;
    }

    // 4) Get original dimensions
    $origWidth  = imagesx($sourceImg);
    $origHeight = imagesy($sourceImg);

    // 5) Compute new dims
    $newWidth  = (int)($origWidth * $scaleFactor);
    $newHeight = (int)($origHeight * $scaleFactor);

    // 6) Create a new blank image
    $destImg = imagecreatetruecolor($newWidth, $newHeight);

    // 7) Copy and resize
    imagecopyresampled($destImg, $sourceImg, 0, 0, 0, 0, 
                       $newWidth, $newHeight, $origWidth, $origHeight);

    // 8) Re-encode as PNG (or use imagejpeg if you prefer)
    ob_start();
    imagepng($destImg);
    $resizedData = ob_get_clean();

    // 9) Convert back to base64 data URL
    $resizedBase64 = base64_encode($resizedData);
    $resizedDataUrl = "data:image/png;base64," . $resizedBase64;

    // Cleanup
    imagedestroy($sourceImg);
    imagedestroy($destImg);

    return $resizedDataUrl;
}

/**
 * Retrieves the recent messages from the database for the current chat session.
 *
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @return array The recent chat messages.
 */
function get_recent_messages($chat_id, $user) {
    if (!empty($chat_id)) {
        return get_all_exchanges($chat_id, $user);
    }
    return [];
}

/**
 * Estimates the number of tokens in a given text.
 *
 * @param string $text The input text.
 * @return int The estimated token count.
 */
function estimate_tokens($text) {
    // This is a simplistic approximation. 
    return ceil(strlen($text) / 3);
}

/**
 * Checks if the user is authenticated.
 *
 * @return bool True if authenticated, false otherwise.
 */
function isAuthenticated() {
    return isset($_SESSION['tokens']) && isset($_SESSION['tokens']['access_token']);
}

/**
 * Logs the user out by destroying the session.
 */
function logout() {

    // Unset all session variables
    $_SESSION = array();

    // If it's desired to kill the session, also delete the session cookie.
    // Note: This will destroy the session, and not just the session data!
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Finally, destroy the session.
    session_destroy();
}

/**
 * Logs detailed error information using PHP's standard error logging system.
 *
 * @param array $msg The message context that triggered the API call.
 * @param string $message The user message that triggered the API call.
 * @param string $api_error The error message returned by the API.
 */
function log_error_details($msg, $message, $api_error) {
    // Prepare the log entry with detailed information
    $log_entry = "==== Error Occurred ====\n";
    $log_entry .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $log_entry .= "User Message: " . $message . "\n";
    $log_entry .= "Message Context: " . print_r($msg, true) . "\n";
    $log_entry .= "API Error: " . $api_error . "\n";
    $log_entry .= "Session Data: " . print_r($_SESSION, true) . "\n";
    $log_entry .= "Server Data: " . print_r($_SERVER, true) . "\n";
    $log_entry .= "========================\n";

    // Send the log entry to PHP's standard error log
    error_log($log_entry);
}

/**
 * Substrings the text to the specified number of words.
 *
 * @param string $text The input text.
 * @param int $numWords The number of words to keep.
 * @return string The truncated text.
 */
function substringWords($text, $numWords) {
    // Split the text into words
    $words = explode(' ', $text);
    
    // Select a subset of words based on the specified number
    $selectedWords = array_slice($words, 0, $numWords);
    
    // Join the selected words back together into a string
    $subString = implode(' ', $selectedWords);
    
    return $subString;
}

function get_tool_tips() {
    global $config;
    $output = "<ul>\n";
    $deployments = explode(',',$config['azure']['deployments']);
    foreach($deployments as $pair) {
        $a = explode(':',$pair);
        $deployment = $config[$a[0]];
        if ($deployment['enabled'] > 0) {
            #echo '<pre>'.print_r($config[$a[0]],1).'</pre>';
            $output .= '<li><strong>'.$a[1].'</strong>: '.$deployment['tooltip']."</li>\n";
        }
    }
    $output .= "</ul>\n";
    return $output;
}

function clean_disclaimer_text() {

    $html = file_get_contents('staticpages/disclaimer_text.php');

    // Step 1: Replace structural tags with line breaks
    $html = preg_replace('/<\/?(p|ul|ol)>/i', "\n\n", $html);   // Paragraphs/lists → double break
    $html = preg_replace('/<li>/i', "- ", $html);               // Bullet for list items
    //$html = preg_replace('/<\/li>/i', "\n", $html);             // Line break after list item
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);         // Single break

    // Step 2: Strip remaining tags and decode entities
    $html = strip_tags($html);
    $html = html_entity_decode($html);

    // Step 3: Normalize whitespace (preserve double line breaks)
    $html = preg_replace('/[ \t]+/', ' ', $html);               // Collapse spaces/tabs
    $html = preg_replace('/[ \t]*\n[ \t]*/', "\n", $html);       // Clean line edges
    $html = preg_replace('/\n{3,}/', "\n\n", $html);             // Collapse 3+ breaks → 2

    $text = preg_replace('/\byou\b/i', 'NHLBI staff', $html);
    $text = preg_replace('/\byour\b/i', 'the', $text);

    // Step 4: Final trim
    return trim($text);
}
