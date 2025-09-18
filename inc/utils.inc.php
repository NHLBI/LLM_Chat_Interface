<?php

# UTILITIES

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
    if (empty($config[$deployment]['assistant_id'])) $config[$deployment]['assistant_id'] = '';

    $output = [
        'deployment' => $deployment,
        'assistant_id'   => trim($config[$deployment]['assistant_id'], '"'),
        #'assistant_id'   => 'asst_iBl1B7WpHluW0B3D39e0k9Ca',
        'api_key' => trim($config[$deployment]['api_key'], '"'),
        'host' => $config[$deployment]['host'],
        'model' => $config[$deployment]['model'] ?? '',
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

// Pick the exact string your UI expects as the key
function ui_deployment_key(array $cfg) {
    return $cfg['deployment'] ?? $cfg['deployment_name'] ?? 'n/a';
}

