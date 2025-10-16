<?php

# LOGIN AND SESSION HANDLING

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

    try {
        session_event_log('logout_invoked', [
            'user' => $_SESSION['user_data']['userid'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'is_ajax' => isset($_SERVER['HTTP_X_REQUESTED_WITH']),
        ]);
    } catch (Throwable $e) {
        error_log('session_event_log logout_invoked failed: ' . $e->getMessage());
    }

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
 * Write structured session-related events to the session log.
 *
 * @param string $event   Short event name (e.g., session_status_active)
 * @param array  $context Additional contextual data to include in the log record
 */
function session_event_log(string $event, array $context = []): void {
    static $logPath = null;

    if ($logPath === null) {
        $envPath = getenv('SESSION_LOG_PATH');
        if ($envPath !== false && $envPath !== '') {
            $logPath = $envPath;
        } else {
            $logPath = dirname(__DIR__) . '/logs/session_events.log';
        }
    }

    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $record = [
        'timestamp' => date('c'),
        'event'     => $event,
        'context'   => $context,
    ];

    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        $line = json_encode([
            'timestamp' => date('c'),
            'event'     => $event,
            'context'   => ['encoding_error' => true]
        ], JSON_UNESCAPED_SLASHES);
    }

    $line .= PHP_EOL;

    if (@file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('[session_event_log] write_failed ' . $line);
    }
}
