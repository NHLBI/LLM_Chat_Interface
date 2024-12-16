<?php
header('Content-Type: application/json');

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    require_once 'lib.required.php';
    require_once 'db.php';

    if (!isset($_SESSION['user_data']['userid'])) {
        throw new Exception("User not authenticated.");
    }
    $user = $_SESSION['user_data']['userid'];

    // Fetch search input
    if ($_GET['clearSearch']) {
        $_GET['search'] = '';
        $_SESSION['search_term'] = '';
    }
    $search = (!empty($_GET['search'])) ? trim($_GET['search']) : '';
    $_SESSION['search_term'] = $search; // Update session with new search term
    $chats = get_all_chats($user, $search); // Query database

    // Output the results as JSON
    echo json_encode($chats);
} catch (Exception $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => $e->getMessage()]);
}
?>
