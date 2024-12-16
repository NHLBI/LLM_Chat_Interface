<?php

ini_set('session.cookie_lifetime', 0); // Expires when browser is closed

// lib.required.php
require_once 'db.php';

// Determine the environment dynamically
require_once 'get_config.php';
#echo '<pre>'.print_r($config,1).'</pre>';

// Start the session, if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Handle the splash screen
if (empty($_SESSION['splash'])) $_SESSION['splash'] = '';

if ( (!empty($_SESSION['user_data']['userid']) && $_SESSION['authorized'] !== true) || empty($_SESSION['splash']) ) {
    require_once 'splash.php';
    exit;
}

// Start the PHP session to enable session variables
$sessionTimeout = $config['session']['timeout'];  // Load session timeout from config

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $sessionTimeout)) {
    // last request was more than 30 minutes ago
    logout();
}
$_SESSION['LAST_ACTIVITY'] = time(); // update last activity time stamp

#logout();
#echo '<pre>'.print_r($_SESSION,1).'</pre>'; #die();

if (empty($_SESSION['user_data'])) $_SESSION['user_data'] = [];

$user = (empty($_SESSION['user_data']['userid'])) ? '' : $_SESSION['user_data']['userid'];

$application_path = $config['app']['application_path'];

// Verify that there is a chat with this id for this user
// If a 'chat_id' parameter was passed, store its value as an integer in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_STRING);

if (!verify_user_chat($user, $chat_id)){
    echo " -- " . $user . "<br>\n";
    die("Error: there is no chat record for the specified user and chat id. If you need assistance, please contact ".$email_help);
}

$models_str = $config['azure']['deployments'];
$models_a = explode(",",$models_str);

$models = array();
foreach($models_a as $m) {
    $a = explode(":",$m);
    $models[$a[0]] = array('label'=>$a[1])+$config[$a[0]];
}

$temperatures = [];
$i=0;
# due to the way PHP evaluates floating-point numbers
# the loop will exit before reaching exactly 2.0 
while ($i<2.1) {
    $temperatures[] = round($i,1);
    $i += 0.1;

}

if (empty($_GET['chat_id'])) $_GET['chat_id'] = '';
// Check if the form has been submitted and set the session variable
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];  // This is the currently active chat

    $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];
    $_SESSION['temperature'] = $all_chats[$chat_id]['temperature'];
    if (!empty($all_chats[$chat_id]['document_name'])) {
        $doc = get_uploaded_image_status($chat_id);
        $_SESSION['document_name'] = $doc['document_name'];
        $_SESSION['document_type'] = $doc['document_type'];
        $_SESSION['document_text'] = $doc['document_text'];
    } else {
        $_SESSION['document_name'] = '';
        $_SESSION['document_type'] = '';
        $_SESSION['document_text'] = '';
    }
}

if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];

} else {
    $deployment = $_SESSION['deployment'];
}

#echo "THIS IS THE DEPLOYMENT: {$deployment}\n";

// Check if the temperature form has been submitted and set the session variable
if (isset($_POST['temperature'])) {
    $_SESSION['temperature'] = (float)$_POST['temperature'];
    $temperature = $_SESSION['temperature'] = $_POST['temperature'];
    if (!empty($_GET['chat_id'])) update_temperature($user, $chat_id, $temperature);
}
if (!isset($_SESSION['temperature']) || (float)$_SESSION['temperature'] < 0 || (float)$_SESSION['temperature'] > 2) {
    $_SESSION['temperature'] = 0.7;

}

// confirm their authentication, redirect if false
if (isAuthenticated()) {
    session_regenerate_id(true);


} else {
    header('Location: auth_redirect.php');
    exit;
}

#echo "<pre>". print_r($_SESSION,1) ."</pre>";
#echo "<pre>". print_r($_SERVER,1) ."</pre>";

function approximateTokenCountByChars($text) {
    $charCount = strlen($text);
    return ceil($charCount / 4); // Rough approximation: 4 characters per token
}

// Call Azure OpenAI API
function call_azure_api($active_config, $msg) {
    $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/chat/completions?api-version=".$active_config['api_version'];
    #print_r($msg);

    $payload = [
        'messages' => $msg,
        "max_tokens" => $active_config['max_tokens'],
        "temperature" => (float)$_SESSION['temperature'],
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 0.95,
        "stop" => ""
    ];
    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $active_config['api_key']
    ];
    $response = execute_api_call($url, $payload, $headers);
    return $response;
}

// Call Mocha API
function call_mocha_api($base_url, $msg) {
    #$payload = $msg;
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

// Execute API Call
function execute_api_call($url, $payload, $headers) {
    #print($url."\n");
    #print_r($headers);
    #print_r($payload);
    $_SESSION['api_endpoint'] = $url;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    #print($response); die();

    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        print('Curl error: ' . curl_error($ch));
        die("DEAD BECAUSE OF AN ERROR");
    }

    curl_close($ch);
    #print("GOT TO THE GET_EXECUTE API CALL FUNCTION");
    return $response;
}

function get_chat_thread($message, $chat_id, $user, $active_config)
{
    $context_limit = (int)$active_config['context_limit'];
    $messages = [];
    #echo "context limit: " . $context_limit;

    if (!empty($_SESSION['document_text'])) {
        if (strpos($_SESSION['document_type'], 'image/') === 0) {
            // Handle image content (use image_url field with base64 encoded image)
            $messages[] = [
                "role" => "system",
                "content" => "You are a helpful assistant to analyze images."
            ];
            $messages[] = [
                "role" => "user",
                "content" => [
                    ["type" => "text", "text" => $message],
                    ["type" => "image_url", "image_url" => ["url" => $_SESSION['document_text']]]
                ]
            ];

        } else {

            $messages[] = ['role' => 'system','content' => $_SESSION['document_text']];
            $messages[] = ['role' => 'user','content' => $message];

        }
        return $messages;
    }

    // Add the user's prompt at the end
    $messages[] = ["role" => "user", "content" => $message];


    // Add the last 5 exchanges from the recent chat history to the messages array
    $recent_messages = get_recent_messages($chat_id, $user);
    #print_r($recent_messages);
    $tokenLimit = $context_limit ; // Set your token limit here
    #$currentTokens = str_word_count($message);
	$currentTokens = approximateTokenCountByChars($message);

    // Create a DateTime object
    $date = new DateTime();

    // Set a specific timezone
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);

    $about = 'NHLBI Chat is a secure chatbot enabling NHLBI staff to use generative AI for their day-to-day work. NHLBI Chat stores all data locally in the NIH data center and uses a secure NIH STRIDES cloud account to host AI models. This enables staff to use NHLBI Chat for sensitive data workloads including:  De-identified and anonymized clinical data Pre-decisional and draft policy Nonpublic data including scientific data and draft manuscripts Currently, PII and identifiable clinical data is not permitted in NHLBI Chat. Please us de-identified and anonymized data. We are actively working with HHS security to enable this use-case.  Please note that these use cases are specific to NHLBI Chat, which is different than public AI tooling like ChatGPT, Meta.AI, and Google Gemini. When using any public AI tooling, please follow OCIO Guidance, which prohibits these sensitive workloads. Unlike the public tooling, data entered into NHLBI Chat is not shared with Microsoft or OpenAI. This enables the sensitive workloads described above.  When using NHLBI Chat, please follow these guidelines:  Human Oversight: Always review and validate outputs generated by NHLBI Chat. Do not base decision-making or policymaking solely on generated outputs. Limitations and Biases: AI models are only as powerful as its training data. Generative AI may generate biased results and may not always generate accurate responses. Generative AI may perform poorly in certain applications. Always validate outputs generated by NHLBI Chat. Ethical use: Follow HHS and NIH policies including OCIO Guidance, HHS policy for Securing Artificial Intelligence (AI) Technology, NOT-OD-23-149 prohibiting Generative AI for NIH Peer Review, and any specific guidance related to your intended use case., and any specific guidance related to your intended use case. No PII: PII and identifiable clinical data is currently not permitted in NHLBI Chat. Only use de-identified and anonymized data. Training Data: NHLBI Chat uses commercial models. It is not fine-tuned on NIH, NHLBI, or biomedical topics. Do not expect NHLBI Chat to have any internal knowledge of the NIH or NHLBI. You are accessing a U.S. Government information system, which includes (1) this computer, (2) this computer network, (3) all computers connected to this network, and (4) all devices and storage media attached to this network or to a computer on this network. This information system is provided for U.S. Government-authorized use only.  Unauthorized or improper use of this system may result in disciplinary action, as well as civil and criminal penalties.  By using this information system, you understand and consent to the following.  You have no reasonable expectation of privacy regarding any communications or data transiting or stored on this information system. At any time, and for any lawful Government purpose, the government may monitor, intercept, record, and search and seize any communication or data transiting or stored on this information system. Any communication or data transiting or stored on this information system may be disclosed or used for any lawful Government purpose. ';


    $system_message[] = [
        'role' => 'system', 
        'content' => 'You are NHLBI Chat, a helpful assistant for staff at the National Heart Lung and Blood Institute. In this timezone, '.$date->getTimezone()->getName().', the current date and time of this prompt is '. $date->format('Y-m-d H:i:s'). ' The user\'s browser has the preferred language (HTTP_ACCEPT_LANGUAGE) set to ' . $_SERVER['HTTP_ACCEPT_LANGUAGE']. ', so please reply with that if possible, unless directed otherwise. ' . $about
    ];


    if (!empty($recent_messages)) {
        $formatted_messages = [];
        foreach (array_reverse($recent_messages) as $message) {
            $message['prompt'] = substringWords($message['prompt'],1000);
            $message['reply'] = substringWords($message['reply'],1000);

            #print_r($message);
            $messageContent = $message['prompt'] . $message['reply'];
			$tokens = approximateTokenCountByChars($messageContent);
            #$tokens = str_word_count($str) + 2; // +2 for role and content keys // old version
            if ($currentTokens + $tokens <= $tokenLimit) {
                $formatted_messages[] = [
                    'role' => 'assistant', 
                    'content' => $message['reply']
                ];
                $formatted_messages[] = [
                    'role' => 'user', 
                    'content' => $message['prompt']
                ];
                $currentTokens += $tokens;
            } else {
                break;
            }
            #echo $tokenLimit . " - " . $currentTokens . " - STARTING HERE =----- " . print_r($formatted_messages,1) . " - THIS IS THE CURRENT TOKENS: {$currentTokens}\n";
        }
        $messages = array_merge($system_message, array_reverse($formatted_messages), $messages);
    }

    #print_r($messages);
    return $messages;
}

function get_gpt_response($message, $chat_id, $user) {
    $active_config = load_configuration($GLOBALS['deployment']);
    $msg = get_chat_thread($message, $chat_id, $user, $active_config);

    if ($active_config['host'] == "Mocha") {
        $response = call_mocha_api($active_config['base_url'], $msg);
    } else {
        $response = call_azure_api($active_config, $msg);
    }

    return process_api_response($response, $GLOBALS['deployment'], $chat_id, $message, $msg);
}

function get_path() {
    $path = strstr($_SERVER['PHP_SELF'],'chatdev') ? 'chatdev' : 'chat';
    return $path;
}

// Get the recent messages from the database for the current chat session
function get_recent_messages($chat_id, $user) {
    if (!empty($chat_id)) {
        return get_all_exchanges($chat_id, $user);
    }
    return [];
}

// This function will check if the user is authenticated
function isAuthenticated() {
    return isset($_SESSION['tokens']) && isset($_SESSION['tokens']['access_token']);
}

// Load configuration
function load_configuration($deployment) {
    global $config;
    #print($deployment."\n");
    
    // Check if the deployment is enabled
    if (!isset($config[$deployment]['enabled']) || $config[$deployment]['enabled'] == false || $config[$deployment]['enabled'] === 'false') {
        // Reassign deployment to default if not enabled
        $_SESSION['deployment'] = $GLOBALS['deployment'] = $deployment = $config['azure']['default'];
    }

    $_SESSION['api_key'] = $config[$deployment]['api_key'];

    return [
        'api_key' => trim($config[$deployment]['api_key'], '"'),
        'host' => $config[$deployment]['host'],
        'base_url' => $config[$deployment]['url'],
        'deployment_name' => $config[$deployment]['deployment_name'],
        'api_version' => $config[$deployment]['api_version'],
        'max_tokens' => (int)$config[$deployment]['max_tokens'],
        'context_limit' => (int)($config[$deployment]['context_limit']*1.5),
    ];
}

// Log the user out
function logout() {

    // start the session if not already started
    #session_start();

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
 * @param string $message    The user message that triggered the API call.
 * @param string $api_error  The error message returned by the API.
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
 * Processes the API response and handles errors.
 *
 * @param string $response    The raw API response.
 * @param string $deployment  Deployment identifier.
 * @param int    $chat_id     Chat identifier.
 * @param string $message     The original user message.
 * @param mixed  $msg         Additional message data.
 *
 * @return array An associative array containing the processing result.
 */
function process_api_response($response, $deployment, $chat_id, $message, $msg) {
    $response_data = json_decode($response, true);
    if (isset($response_data['error'])) {
        $api_error_message = $response_data['error']['message'];
        
        // Log detailed error information using PHP's standard error log
        log_error_details($msg, $message, $api_error_message);
        
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => $api_error_message
        ];
    } else {
        // Get the response text, process it for any special handling (code blocks, etc.)
        $response_text = $response_data['response'] ?? $response_data['choices'][0]['message']['content'];

        // Save to the database
        create_exchange($chat_id, $message, $response_text);

        return [
            'deployment' => $deployment,
            'error' => false,
            'message' => $response_text
        ];
    }
}

function substringWords($text, $numWords) {
    // Split the text into words
    $words = explode(' ', $text);
    
    // Select a subset of words based on the specified number
    $selectedWords = array_slice($words, 0, $numWords);
    
    // Join the selected words back together into a string
    $subString = implode(' ', $selectedWords);
    
    return $subString;
}


