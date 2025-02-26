<?php

require_once __DIR__ . '/session_init.php';
require_once 'get_config.php';

header('Content-Type: application/json');

// Load session timeout value from config
$sessionTimeout = $config['session']['timeout'];

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] < $sessionTimeout)) {
    // Update session's last activity time to keep it alive
    $_SESSION['LAST_ACTIVITY'] = time();

    // Return the remaining time for client-side session management
    $timeLeft = $sessionTimeout - (time() - $_SESSION['LAST_ACTIVITY']);
    echo json_encode(['session_active' => true, 'remaining_time' => $timeLeft]);
} else {
    // If the session has expired
    echo json_encode(['session_active' => false]);
}

