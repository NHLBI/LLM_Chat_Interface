<?php

# MOCHA FUNCTIONS

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

