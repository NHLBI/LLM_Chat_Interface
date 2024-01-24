<?php
// lib.required.php
require_once 'db.php';

$config = parse_ini_file('/etc/apps/chat_config.ini',true);

// Start the PHP session to enable session variables
ini_set('session.cookie_lifetime', 0); // Expires when browser is closed


// Start the session, if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (
    (!empty($_SESSION['user_data']['userid']) && $_SESSION['authorized'] !== true) || 
    $_SESSION['splash'] !== true
) {
    require_once 'splash.php';
    exit;
}

$user = $_SESSION['user_data']['userid'];

if (strstr($_SERVER['REQUEST_URI'],'chatdev')) 
    $application_path = $config['app']['path_dev'];
else $application_path = $config['app']['path_prod'];

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
    $models[$a[0]] = $a[1];
}

// Check if the form has been submitted and set the session variable
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    #echo "GOT TO THIS POINT JACK";
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];  // This is the currently active chat
    #if (empty($_SESSION['document_name'])) {
        #echo "GOT TO THIS POINT JILL";
        $_SESSION['document_name'] = $all_chats[$chat_id]['document_name'];
        $_SESSION['document_text'] = $all_chats[$chat_id]['document_text'];
    #}
}

if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];

} else {
    $deployment = $_SESSION['deployment'];

}

// confirm their authentication, redirect if false
if (!isAuthenticated()) {
    #header('Location: index.php');
    #exit;

    $clientId = $config['openid']['clientId'];
    $callback = $config['openid']['callback'];

    $scope = 'openid profile';  // Asking for identity and profile information
    $state = bin2hex(random_bytes(16));  // Generate a random state
    $_SESSION['oauth2state'] = $state;  // Store state in session for later validation

    $authorizationUrlBase = $config['openid']['authorization_url_base'];
    $authorizationUrl = $authorizationUrlBase . '?' . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $callback,
        'response_type' => 'code',
        'scope' => $scope,
        'state' => $state
    ]);

    header('Location: ' . $authorizationUrl);
    exit;
}

#echo "<pre>". print_r($_SESSION,1) ."</pre>";
#echo "<pre>". print_r($_SERVER,1) ."</pre>";

// This function will check if the user is authenticated
function isAuthenticated() {
    return isset($_SESSION['tokens']) && isset($_SESSION['tokens']['access_token']);
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


function get_gpt_response($message, $chat_id, $user) {
    // Get the configuration elements
    global $config, $deployment;

    // Set up the Azure OpenAI API parameters
    $api_key = trim($config[$deployment]['api_key'], '"'); // trim the quotes around the password
    $base_url = $config[$deployment]['url'];
    $deployment_name = $config[$deployment]['deployment_name'];
    $api_version = $config[$deployment]['api_version'];
    $max_tokens = (int)$config[$deployment]['max_tokens'];
    $context_limit = (int)($config[$deployment]['context_limit']*1.5);
    $url = $base_url . "/openai/deployments/" . $deployment_name . "/chat/completions?api-version=".$api_version;
    #$url = $base_url . "/openai/deployments/" . $deployment_name . "/chat/completions?api-version=2023-03-15-preview";

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $api_key
    ];

    $msg = get_chat_thread($message, $chat_id, $user);

    $payload = [
        'messages' => $msg,
        "max_tokens" => $max_tokens,
        "temperature" => 0.7,
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 0.95,
        "stop" => ""
    ];

    #echo $deployment . "\n";

    $len = (int)(str_word_count(print_r($payload['messages'],1)) * 1.5);
    $err_msg = "This model's maximum context length is {$context_limit} tokens. However, your messages resulted in {$len} tokens. Please reduce the length of the messages.";
    if ($len > $context_limit) {
        // Return a structured error response
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => $err_msg
        ];

    }

    // Send the data to the Azure OpenAI API using cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
    }

    curl_close($ch);

    // Parse the response from the Azure OpenAI API
    $response_data = json_decode($response, true);
    #print_r($response_data); die();

    // Check if there's an error in the response
    if (isset($response_data['error'])) {
        // Log the error message
        error_log('API error: ' . $response_data['error']['message']);
        
        // Return a structured error response
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => $response_data['error']['message']
        ];
    } else {

        $response_text = $response_data['choices'][0]['message']['content'];

        create_exchange($chat_id, $message, $response_text);

        // Return a structured error response
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

function get_chat_thread($message, $chat_id, $user)
{
    global $config,$deployment;

    $context_limit = (int)$config[$deployment]['context_limit'];
    #echo "context limit: " . $context_limit;

    if (!empty($_SESSION['document_text'])) {
        $messages = [
            [
                'role' => 'system',
                'content' => $_SESSION['document_text']
            ],
            [
                'role' => 'user',
                'content' => $message
            ]
        ];
        return $messages;
    }

    // Set up the chat messages array to send to the OpenAI API
    $messages = [
        [
            'role' => 'system',
            'content' => 'Prior exchanges were for context; please respond only to the user\'s next message.'
        ],
        [
            'role' => 'user',
            'content' => $message
        ]
    ];


    // Add the last 5 exchanges from the recent chat history to the messages array
    $recent_messages = get_recent_messages($chat_id, $user);
    #print_r($recent_messages);
    $tokenLimit = $context_limit ; // Set your token limit here
    #$currentTokens = str_word_count($message);
	$currentTokens = approximateTokenCountByChars($message);


    if (!empty($recent_messages)) {
        $formatted_messages = [];
        foreach (array_reverse($recent_messages) as $message) {
            $message['prompt'] = substringWords($message['prompt'],400);
            $message['reply'] = substringWords($message['reply'],300);

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
        $messages = array_merge(array_reverse($formatted_messages), $messages);
    }

    #print_r($messages);
    return $messages;
}

function approximateTokenCountByChars($text) {
    $charCount = strlen($text);
    return ceil($charCount / 4); // Rough approximation: 4 characters per token
}

