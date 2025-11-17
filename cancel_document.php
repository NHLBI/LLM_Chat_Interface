<?php
declare(strict_types=1);

header('Content-Type: application/json');

try {
require_once 'bootstrap.php';
require_once 'db.php';
require_once __DIR__ . '/inc/rag_cleanup.php';
require_once __DIR__ . '/inc/rag_processing_status.php';

    if (empty($_SESSION['user_data']['userid'])) {
        throw new RuntimeException('User not authenticated.');
    }
    $user = $_SESSION['user_data']['userid'];

    $rawInput = file_get_contents('php://input');
    $payload  = $rawInput ? json_decode($rawInput, true) : null;
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $chatId = isset($payload['chat_id']) ? trim((string)$payload['chat_id']) : '';
    if ($chatId === '') {
        throw new RuntimeException('Missing chat_id.');
    }

    $docIds = [];
    if (isset($payload['document_ids']) && is_array($payload['document_ids'])) {
        foreach ($payload['document_ids'] as $docId) {
            $docId = (int)$docId;
            if ($docId > 0) {
                $docIds[] = $docId;
            }
        }
    }

    $docIds = array_values(array_unique($docIds));
    if (empty($docIds)) {
        throw new RuntimeException('No document IDs supplied.');
    }

    $pdo = get_connection();
    $ragPaths = rag_workspace_paths($config ?? null);

    $cancelledIds = [];
    foreach ($docIds as $docId) {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.deleted
               FROM document d
               JOIN chat c ON c.id = d.chat_id
              WHERE d.id = :doc_id
                AND d.chat_id = :chat_id
                AND c.user = :user'
        );
        $stmt->execute([
            ':doc_id'  => $docId,
            ':chat_id' => $chatId,
            ':user'    => $user,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        if ((int)$row['deleted'] === 0) {
            $update = $pdo->prepare('UPDATE document SET deleted = 1 WHERE id = :doc_id LIMIT 1');
            $update->execute([':doc_id' => $docId]);
        }

        $cancelledIds[] = $docId;
        rag_processing_status_clear($docId, $ragPaths);
    }

    if (empty($cancelledIds)) {
        throw new RuntimeException('No documents were cancelled.');
    }

    $qdrantCfg = $config['qdrant'] ?? [];
    $summary   = null;

    try {
        $summary = ragCleanupProcessDocuments($pdo, $cancelledIds, $qdrantCfg);
    } catch (Throwable $cleanupError) {
        error_log('cancel_document cleanup warning: ' . $cleanupError->getMessage());
        $summary = [
            'ok'      => false,
            'message' => $cleanupError->getMessage(),
        ];
    }

    echo json_encode([
        'success'       => true,
        'canceled_ids'  => $cancelledIds,
        'rag_summary'   => $summary,
    ]);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
