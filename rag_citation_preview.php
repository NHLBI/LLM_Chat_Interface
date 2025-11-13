<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_data']['userid']) || empty($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode([
        'ok'      => false,
        'error'   => 'unauthorized',
        'message' => 'Session expired. Please refresh and sign in again.',
    ]);
    exit;
}

$exchangeId = filter_input(INPUT_GET, 'exchange_id', FILTER_VALIDATE_INT);
$docId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT);
$chunkIndex = filter_input(INPUT_GET, 'chunk_index', FILTER_VALIDATE_INT);

if (!$exchangeId || $exchangeId < 1 || !$docId || $docId < 1) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_request',
        'message' => 'Valid exchange_id and document_id are required.',
    ]);
    exit;
}

$chunkIndex = $chunkIndex !== false && $chunkIndex !== null ? (int)$chunkIndex : null;
$userId = (string)($_SESSION['user_data']['userid'] ?? '');

try {
    global $pdo;
    $sql = "
        SELECT
            e.chat_id,
            c.user,
            rul.citations
        FROM rag_usage_log AS rul
        INNER JOIN exchange AS e ON e.id = rul.exchange_id
        INNER JOIN chat AS c ON c.id = e.chat_id
        WHERE rul.exchange_id = :exchange_id
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['exchange_id' => $exchangeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'No citations were logged for this exchange.',
        ]);
        exit;
    }

    if (!hash_equals((string)$row['user'], $userId)) {
        http_response_code(403);
        echo json_encode([
            'ok'      => false,
            'error'   => 'forbidden',
            'message' => 'You do not have access to this exchange.',
        ]);
        exit;
    }

    $citations = json_decode((string)$row['citations'], true);
    if (!is_array($citations)) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'Citation metadata is not available.',
        ]);
        exit;
    }

    $docStmt = $pdo->prepare("
        SELECT COUNT(*) AS matches
        FROM document d
        WHERE d.id = :doc_id
          AND d.chat_id = :chat_id
    ");
    $docStmt->execute([
        'doc_id'  => $docId,
        'chat_id' => $row['chat_id'],
    ]);
    $docRow = $docStmt->fetch(PDO::FETCH_ASSOC);
    if (empty($docRow['matches'])) {
        http_response_code(403);
        echo json_encode([
            'ok'      => false,
            'error'   => 'forbidden',
            'message' => 'You do not have access to this document.',
        ]);
        exit;
    }

    $match = null;
    foreach ($citations as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $entryDoc = isset($entry['document_id']) ? (int)$entry['document_id'] : null;
        $entryChunk = isset($entry['chunk_index']) ? (int)$entry['chunk_index'] : null;

        $chunkMatches = $chunkIndex === null || $entryChunk === $chunkIndex;
        if ($entryDoc === $docId && $chunkMatches) {
            $match = $entry;
            break;
        }
    }

    if (!$match) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'No matching citation was found for that chunk.',
        ]);
        exit;
    }

    $excerpt = (string)($match['excerpt'] ?? '');
    if ($excerpt === '') {
        $excerpt = 'Preview is not available for this retrieved chunk.';
    }

    $payload = [
        'name'                => (string)($match['filename'] ?? 'Document excerpt'),
        'source'              => 'RAG',
        'type'                => 'text/plain',
        'token_length'        => null,
        'full_text_available' => true,
        'ready'               => true,
        'has_preview'         => true,
        'excerpt_truncated'   => false,
        'excerpt'             => $excerpt,
        'section'             => $match['section'] ?? null,
        'page'                => $match['page'] ?? null,
        'chunk_index'         => $match['chunk_index'] ?? null,
    ];

    echo json_encode([
        'ok'       => true,
        'citation' => $payload,
    ]);
} catch (Throwable $e) {
    error_log('rag_citation_preview error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Unable to load the requested citation excerpt.',
    ]);
}
exit;
