<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/rag_processing_status.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_data']['userid'])) {
    error_log('document_status unauthorized access attempt');
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
    error_log('document_status called without document_ids');
    echo json_encode([
        'documents' => [],
        'all_ready' => true,
    ]);
    exit;
}

$ragPaths = rag_workspace_paths($config ?? null);
$docStatusLogPath = ($ragPaths['logs'] ?? __DIR__ . '/logs') . '/document_status_debug.log';
$logDir = dirname($docStatusLogPath);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

if (!function_exists('document_status_debug_log')) {
    function document_status_debug_log(?string $path, array $entry): void
    {
        if (!$path) {
            return;
        }
        $entry['ts'] = date('c');
        $entry['pid'] = getmypid();
        $payload = json_encode($entry, JSON_UNESCAPED_SLASHES);
        if ($payload !== false) {
            @file_put_contents($path, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}

$userId = $_SESSION['user_data']['userid'];
$placeholders = implode(',', array_fill(0, count($documentIds), '?'));

try {
    global $pdo;

    $sql = "
        SELECT
            d.id              AS document_id,
            d.source          AS document_source,
            MAX(COALESCE(ri.ready, 0)) AS rag_ready
        FROM document d
        INNER JOIN chat c
            ON d.chat_id = c.id
        LEFT JOIN rag_index ri
            ON ri.document_id = d.id
        WHERE d.id IN ($placeholders)
          AND c.user = ?
          AND d.deleted = 0
        GROUP BY d.id, d.source
    ";

    $stmt = $pdo->prepare($sql);
    $params = $documentIds;
    $params[] = $userId;
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    document_status_debug_log($docStatusLogPath, [
        'event'     => 'query',
        'user'      => $userId,
        'doc_count' => count($documentIds),
        'doc_ids'   => array_slice($documentIds, 0, 25),
    ]);

    $statusMap = [];
    foreach ($documentIds as $docId) {
        $statusMap[$docId] = ['ready' => false];
    }

    foreach ($rows as $row) {
        $docId = (int)($row['document_id'] ?? 0);
        if (!$docId || !array_key_exists($docId, $statusMap)) {
            continue;
        }
        $ready = ((int)($row['rag_ready'] ?? 0)) === 1;
        $source = (string)($row['document_source'] ?? '');
        if ($source === 'inline') {
            $ready = true;
        }
        $statusMap[$docId]['ready'] = $ready;
    }

    $documents = [];
    $allReady = true;
    foreach ($statusMap as $docId => $meta) {
        $ready = (bool)($meta['ready'] ?? false);
        $processing = rag_processing_status_read($docId, $ragPaths);
        if ($ready && $processing) {
            rag_processing_status_clear($docId, $ragPaths);
            $processing = null;
        }
        $documents[] = [
            'document_id' => $docId,
            'ready'       => $ready,
            'processing'  => $processing,
        ];
        if ($ready === false) {
            $allReady = false;
        }
    }

    echo json_encode([
        'documents' => $documents,
        'all_ready' => $allReady,
    ]);
    document_status_debug_log($docStatusLogPath, [
        'event'      => 'response',
        'user'       => $userId,
        'doc_count'  => count($documents),
        'all_ready'  => $allReady,
        'documents'  => array_slice($documents, 0, 20),
    ]);
} catch (Throwable $e) {
    error_log('document_status error: ' . $e->getMessage());
    document_status_debug_log($docStatusLogPath, [
        'event'     => 'error',
        'user'      => $userId,
        'doc_count' => count($documentIds),
        'message'   => $e->getMessage(),
    ]);
    http_response_code(500);
    echo json_encode(['error' => 'Unable to determine document status']);
}
