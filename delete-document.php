<?php
// This file is typically called via an AJAX POST request from the UI:
// data: { chatId: chatId, docKey: docKey }

header('Content-Type: application/json');

try {
    require_once 'bootstrap.php';  // ensures session, db, etc. are loaded
    require_once 'db.php';           // or whichever file has delete_document()
    require_once __DIR__ . '/inc/rag_cleanup.php';

    // Check user session
    if (!isset($_SESSION['user_data']['userid'])) {
        throw new Exception("User not authenticated.");
    }
    $user = $_SESSION['user_data']['userid'];

    // Read chatId and docKey
    if (!isset($_POST['chatId'], $_POST['docKey'])) {
        throw new Exception("Missing parameters.");
    }
    $chatId = $_POST['chatId'];
    // docKey is the 'id' in the documents table
    $docId  = (int) $_POST['docKey'];

    // Attempt to delete
    $result = delete_document($user, $chatId, $docId);
    if (!$result) {
        // This means either the chat doesn't belong to user,
        // or the doc wasn't found for that chat
        throw new Exception("Unable to delete document.");
    }

    $ragSummary = null;
    try {
        $qdrantCfg = $config['qdrant'] ?? [];
        $ragSummary = ragCleanupProcessDocuments($pdo, [$docId], $qdrantCfg);
    } catch (Throwable $cleanupError) {
        error_log('delete_document cleanup warning: ' . $cleanupError->getMessage());
        $ragSummary = [
            'ok'      => false,
            'message' => $cleanupError->getMessage(),
        ];
    }

    // Return success
    echo json_encode([
        'success'     => true,
        'rag_summary' => $ragSummary,
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([ 'error' => $e->getMessage() ]);
    exit;
}
