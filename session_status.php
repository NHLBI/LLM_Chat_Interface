<?php

require_once __DIR__ . '/session_init.php';
require_once 'get_config.php';
require_once __DIR__ . '/inc/login-session.inc.php';

header('Content-Type: application/json');

// Load session timeout value from config
$sessionTimeout = $config['session']['timeout'];

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] < $sessionTimeout)) {
    $now = time();
    $elapsed = $now - (int)$_SESSION['LAST_ACTIVITY'];
    $timeLeft = max(0, $sessionTimeout - $elapsed);

    // Update session's last activity time to keep it alive
    $_SESSION['LAST_ACTIVITY'] = $now;

    // Return the remaining time for client-side session management
    echo json_encode(['session_active' => true, 'remaining_time' => $timeLeft]);

    session_event_log('session_status_active', [
        'user'              => $_SESSION['user_data']['userid'] ?? null,
        'remaining_time'    => $timeLeft,
        'elapsed'           => $elapsed,
        'request_uri'       => $_SERVER['REQUEST_URI'] ?? null,
        'referer'           => $_SERVER['HTTP_REFERER'] ?? null,
        'is_ajax'           => isset($_SERVER['HTTP_X_REQUESTED_WITH']),
        'session_id'        => session_id(),
    ]);
} else {
    // If the session has expired
    echo json_encode(['session_active' => false]);

    $lastActivity = $_SESSION['LAST_ACTIVITY'] ?? null;
    $elapsed = $lastActivity !== null ? (time() - (int)$lastActivity) : null;

    session_event_log('session_status_inactive', [
        'user'           => $_SESSION['user_data']['userid'] ?? null,
        'reason'         => isset($_SESSION['LAST_ACTIVITY']) ? 'expired' : 'missing_last_activity',
        'last_activity'  => $lastActivity,
        'elapsed'        => $elapsed,
        'request_uri'    => $_SERVER['REQUEST_URI'] ?? null,
        'referer'        => $_SERVER['HTTP_REFERER'] ?? null,
        'is_ajax'        => isset($_SERVER['HTTP_X_REQUESTED_WITH']),
        'session_id'     => session_id(),
    ]);
}
