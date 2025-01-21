<?php

ini_set('session.cookie_lifetime', 0); // Expires when browser is closed

// lib.required.php
require_once 'db.php';

// Determine the environment dynamically
require_once 'get_config.php';
// echo '<pre>'.print_r($config,1).'</pre>';

// Start the session, if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
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

// Verify that there is a chat with this id for this user
// If a 'chat_id' parameter was passed, store its value as a string in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_STRING);

if (!verify_user_chat($user, $chat_id)) {
    echo " -- " . htmlspecialchars($user) . "<br>\n";
    die("Error: there is no chat record for the specified user and chat id. If you need assistance, please contact " . htmlspecialchars($email_help));
}

// Parse models from configuration
$models_str = $config['azure']['deployments'];
$models_a = explode(",", $models_str);

$models = array();
foreach ($models_a as $m) {
    $a = explode(":", $m);
    $models[$a[0]] = array('label' => $a[1]) + $config[$a[0]];
}

// Define temperature options
$temperatures = [];
$i = 0;
while ($i < 1) {
    $temperatures[] = round($i, 1);
    $i += 0.1;
}

// Initialize chat_id if not set
if (empty($_GET['chat_id'])) $_GET['chat_id'] = '';

// Handle model selection
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

// Retrieve all chats for the user
$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];  // Currently active chat

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

// Set default deployment if not already set
if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];
} else {
    $deployment = $_SESSION['deployment'];
}

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

// Confirm authentication, redirect if false
if (isAuthenticated()) {
    session_regenerate_id(true);
} else {
    header('Location: auth_redirect.php');
    exit;
}

// Uncomment for debugging
// echo "<pre>". print_r($_SESSION,1) ."</pre>";
// echo "<pre>". print_r($_SERVER,1) ."</pre>";

/**
 * Retrieves the GPT response by orchestrating the API call and processing the response.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param string $deployment The deployment identifier.
 * @return array The processed API response.
 */
function get_gpt_response($message, $chat_id, $user, $deployment) {
    $active_config = load_configuration($deployment);
    if (!$active_config) {
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => 'Invalid deployment configuration.'
        ];
    }

    $msg = get_chat_thread($message, $chat_id, $user, $active_config);

    if ($active_config['host'] == "Mocha") {
        $response = call_mocha_api($active_config['base_url'], $msg);
    } else {
        $response = call_azure_api($active_config, $msg);
    }
    #echo "<pre>Step get_gpt_response()\n".print_r($response,1)."</pre>"; die();

    return process_api_response($response, $active_config, $chat_id, $message, $msg);
}

/**
 * Loads the configuration based on the deployment name.
 *
 * @param string $deployment The deployment identifier.
 * @return array|null The configuration array or null if not found.
 */
function load_configuration($deployment) {
    global $config;
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
        'context_limit' => (int)($config[$deployment]['context_limit'] * 1.5),
    ];
    if (!empty($config[$deployment]['max_tokens'])) $output['max_tokens'] = (int)$config[$deployment]['max_tokens'];
    if (!empty($config[$deployment]['max_completion_tokens'])) $output['max_completion_tokens'] = (int)$config[$deployment]['max_completion_tokens'];
    // print_r($output);
    return $output;
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

    if ($active_config['host'] === 'Dall-e') {
        return array('prompt'=>$message); // Handle DALL-E Image Generation Requests
        
    } else {
        // Handle Chat Completion Requests
        return handle_chat_request($message, $chat_id, $user, $active_config);
    }
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
 * @param mixed $msg The message payload.
 * @return string The API response.
 */
function call_azure_api($active_config, $msg) {
    $is_dalle = ($active_config['host'] === 'Dall-e');

    if ($is_dalle) {
        // DALL-E Image Generation Endpoint
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
        
        $payload = [
            'messages' => $msg,
            "temperature" => (float)$_SESSION['temperature'],
            "frequency_penalty" => 0,
            "presence_penalty" => 0,
            "top_p" => 1
        ];
        if (!empty($active_config['max_tokens'])) {
            $payload['max_tokens'] = $active_config['max_tokens'];
        }
        if (!empty($active_config['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = $active_config['max_completion_tokens'];
            $payload['temperature'] = 1;
        }
    }

    #die("2. Final payload: ".print_r($payload,1));

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $active_config['api_key']
    ];
    $response = execute_api_call($url, $payload, $headers);
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
function process_api_response($response, $active_config, $chat_id, $message, $msg) {
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

    if ($active_config['host'] === 'Dall-e') {
        $image_url = $response_data['data'][0]['url'] ?? null;

        if ($image_url) {
            preg_match('#/images/([^/]+)/generated_#', $image_url, $matches);
            $unique_dir = $matches[1] ?? uniqid();
            $image_gen_name = $chat_id . '-' . $unique_dir . '.png';

            $image_gen_dir = __DIR__ . '/image_gen';
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
                    $eid = create_exchange($chat_id, $message, '', null, null, null, $image_gen_name);

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
    $eid = create_exchange($chat_id, $message, $response_text);

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
    $document_messages = handle_document_content($message, $active_config);
    
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
function execute_api_call($url, $payload, $headers) {
    // Uncomment for debugging
    /*
    print($url."\n");
    print_r($headers);
    print_r($payload);
    die();
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
    // print($response); die();

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

    $about = 'NHLBI Chat is a secure chatbot enabling NHLBI staff to use generative AI for their day-to-day work. NHLBI Chat stores all data locally in the NIH data center and uses a secure NIH STRIDES cloud account to host AI models. This enables staff to use NHLBI Chat for sensitive data workloads including:  De-identified and anonymized clinical data Pre-decisional and draft policy Nonpublic data including scientific data and draft manuscripts Currently, PII and identifiable clinical data is not permitted in NHLBI Chat. Please use de-identified and anonymized data. We are actively working with HHS security to enable this use-case.  Please note that these use cases are specific to NHLBI Chat, which is different than public AI tooling like ChatGPT, Meta.AI, and Google Gemini. When using any public AI tooling, please follow OCIO Guidance, which prohibits these sensitive workloads. Unlike the public tooling, data entered into NHLBI Chat is not shared with Microsoft or OpenAI. This enables the sensitive workloads described above.  When using NHLBI Chat, please follow these guidelines:  Human Oversight: Always review and validate outputs generated by NHLBI Chat. Do not base decision-making or policymaking solely on generated outputs. Limitations and Biases: AI models are only as powerful as its training data. Generative AI may generate biased results and may not always generate accurate responses. Generative AI may perform poorly in certain applications. Always validate outputs generated by NHLBI Chat. Ethical use: Follow HHS and NIH policies including OCIO Guidance, HHS policy for Securing Artificial Intelligence (AI) Technology, NOT-OD-23-149 prohibiting Generative AI for NIH Peer Review, and any specific guidance related to your intended use case. No PII: PII and identifiable clinical data is currently not permitted in NHLBI Chat. Only use de-identified and anonymized data. Training Data: NHLBI Chat uses commercial models. It is not fine-tuned on NIH, NHLBI, or biomedical topics. Do not expect NHLBI Chat to have any internal knowledge of the NIH or NHLBI. You are accessing a U.S. Government information system, which includes (1) this computer, (2) this computer network, (3) all computers connected to this network, and (4) all devices and storage media attached to this network or to a computer on this network. This information system is provided for U.S. Government-authorized use only.  Unauthorized or improper use of this system may result in disciplinary action, as well as civil and criminal penalties.  By using this information system, you understand and consent to the following.  You have no reasonable expectation of privacy regarding any communications or data transiting or stored on this information system. At any time, and for any lawful Government purpose, the government may monitor, intercept, record, and search and seize any communication or data transiting or stored on this information system. Any communication or data transiting or stored on this information system may be disclosed or used for any lawful Government purpose. ';

    // Construct the system message with correct role and defined $date
    $system_message = [
        [
            'role' => 'user',
            'content' => 'You are NHLBI Chat, a helpful assistant for staff at the National Heart Lung and Blood Institute. In this timezone, ' . $date->getTimezone()->getName() . ', the current date and time of this prompt is ' . $date->format('Y-m-d H:i:s') . '. The user\'s browser has the preferred language (HTTP_ACCEPT_LANGUAGE) set to ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] . ', so please reply in that language if possible, unless directed otherwise. ' . $about
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
    $context_limit = 10000000; #(int)$active_config['context_limit'];
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
 * @param string $message The current user message.
 * @param array $active_config The active deployment configuration.
 * @return array|null The messages array if document exists, otherwise null.
 */
function handle_document_content($message, $active_config) {
    if (!empty($_SESSION['document_text'])) {
        if (strpos($_SESSION['document_type'], 'image/') === 0) {
            // Handle image content (use image_url field with base64 encoded image)
            $messages[] = [
                "role" => "user",
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

            $messages[] = ['role' => 'user','content' => $_SESSION['document_text']];
            $messages[] = ['role' => 'user','content' => $message];

        }                                                                                                                                                                      
        return $messages;
    }   
}

/**
 * Fetches a remote image, saves it temporarily, and returns a base64 data URL.
 *
 * @param string $remote_url The remote image URL from DALL-E.
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

    // Uncomment to ensure the session is started
    // if (session_status() == PHP_SESSION_NONE) {
    //     session_start();
    // }

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

