<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_data']['userid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload  = json_decode($rawInput ?: '[]', true);

$documentIds = [];
if (is_array($payload) && isset($payload['document_ids']) && is_array($payload['document_ids'])) {
    foreach ($payload['document_ids'] as $docId) {
        $docId = (int)$docId;
        if ($docId > 0) {
            $documentIds[] = $docId;
        }
    }
}

$documentIds = array_values(array_unique($documentIds));

if (empty($documentIds)) {
    echo json_encode([
        'documents' => [],
        'all_ready' => true,
    ]);
    exit;
}

$userId = $_SESSION['user_data']['userid'];
$placeholders = implode(',', array_fill(0, count($documentIds), '?'));

try {
    global $pdo;

    $sql = "
        SELECT
            d.id              AS document_id,
            MAX(COALESCE(ri.ready, 0)) AS ready
        FROM document d
        INNER JOIN chat c
            ON d.chat_id = c.id
        LEFT JOIN rag_index ri
            ON ri.document_id = d.id
        WHERE d.id IN ($placeholders)
          AND c.user = ?
          AND d.deleted = 0
        GROUP BY d.id
    ";

    $stmt = $pdo->prepare($sql);
    $params = $documentIds;
    $params[] = $userId;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statusMap = [];
    foreach ($documentIds as $docId) {
        $statusMap[$docId] = false;
    }

    foreach ($rows as $row) {
        $docId = (int)($row['document_id'] ?? 0);
        if (!$docId || !array_key_exists($docId, $statusMap)) {
            continue;
        }
        $statusMap[$docId] = ((int)($row['ready'] ?? 0)) === 1;
    }

    $documents = [];
    $allReady = true;
    foreach ($statusMap as $docId => $ready) {
        $documents[] = [
            'document_id' => $docId,
            'ready'       => $ready,
        ];
        if ($ready === false) {
            $allReady = false;
        }
    }

    echo json_encode([
        'documents' => $documents,
        'all_ready' => $allReady,
    ]);
} catch (Throwable $e) {
    error_log('document_status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to determine document status']);
}
