<?php
// Include the database connection file
require_once 'bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/inc/rag_cleanup.php';

// Check if the 'chat_id' is set in the POST data
if(isset($_POST['chat_id'])) {
    // Assign the 'chat_id' from the POST data to the $chat_id variable
    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);
    
    if (!$chat_id) {
        die("Invalid input");
    }

    $pdo->beginTransaction();
    $docIds = [];

    try {
        $docStmt = $pdo->prepare("SELECT id FROM document WHERE chat_id = :chat_id AND deleted = 0");
        $docStmt->execute(['chat_id' => $chat_id]);
        $docIds = array_map('intval', $docStmt->fetchAll(PDO::FETCH_COLUMN));

        $stmtDocs = $pdo->prepare("UPDATE document SET `deleted` = 1 WHERE chat_id = :chat_id");
        $stmtDocs->execute(['chat_id' => $chat_id]);

        $stmt1 = $pdo->prepare("UPDATE chat SET `deleted` = 1 WHERE id = :id");
        $stmt1->execute(['id' => $chat_id]);

        $stmt2 = $pdo->prepare("UPDATE exchange SET `deleted` = 1 WHERE chat_id = :chat_id");
        $stmt2->execute(['chat_id' => $chat_id]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (!empty($docIds)) {
        try {
            $qdrantCfg = $config['qdrant'] ?? [];
            ragCleanupProcessDocuments($pdo, $docIds, $qdrantCfg);
        } catch (Throwable $cleanupError) {
            error_log('delete_chat rag cleanup warning: ' . $cleanupError->getMessage());
        }
    }
}
