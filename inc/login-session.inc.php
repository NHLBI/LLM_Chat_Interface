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

