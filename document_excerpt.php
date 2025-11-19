<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_data']['userid']) || empty($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode([
        'ok'      => false,
        'error'   => 'unauthorized',
        'message' => 'Session expired. Please refresh the page.',
    ]);
    exit;
}

$docId = filter_input(INPUT_GET, 'document_id', FILTER_VALIDATE_INT);
if (!$docId || $docId < 1) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_document_id',
        'message' => 'A valid document id is required.',
    ]);
    exit;
}

$userId = $_SESSION['user_data']['userid'];

try {
    global $pdo;

    $sql = "
        SELECT
            d.id,
            d.chat_id,
            d.name,
            d.type,
            d.content,
            d.source,
            d.document_token_length,
            d.full_text_available,
            d.deleted,
            d.timestamp,
            c.user,
            CASE
                WHEN d.type LIKE 'image/%' THEN 1
                WHEN LOWER(d.source) IN ('inline', 'image', 'paste') THEN 1
                WHEN COALESCE(ri.ready, 0) = 1 THEN 1
                WHEN ri.document_id IS NULL
                     AND (
                         d.full_text_available = 1
                         OR d.document_token_length > 0
                         OR (d.content IS NOT NULL AND d.content <> '')
                     )
                     AND (d.source IS NULL
                          OR d.source = ''
                          OR LOWER(d.source) NOT IN ('parsing', 'uploading'))
                    THEN 1
                ELSE 0
            END AS document_ready
        FROM document d
        INNER JOIN chat c
            ON c.id = d.chat_id
        LEFT JOIN (
            SELECT document_id, MAX(ready) AS ready
              FROM rag_index
             GROUP BY document_id
        ) ri
            ON ri.document_id = d.id
        WHERE d.id = :doc_id
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['doc_id' => $docId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['deleted'] === 1) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'Document not found.',
        ]);
        exit;
    }

    if (!hash_equals((string)$row['user'], (string)$userId)) {
        http_response_code(403);
        echo json_encode([
            'ok'      => false,
            'error'   => 'forbidden',
            'message' => 'You do not have access to this document.',
        ]);
        exit;
    }

    $rawContent = (string)($row['content'] ?? '');
    $isImage = strpos((string)$row['type'], 'image/') === 0 || strtolower((string)$row['source']) === 'image';
    $hasPreview = $rawContent !== '';

    $maxPreviewChars = 8000;
    $excerpt = '';
    $truncated = false;

    if ($isImage) {
        if ($rawContent !== '') {
            $excerpt = 'Image preview available.';
            $hasPreview = true;
        } else {
            $excerpt = 'This item is an image; preview is not available. Download from the chat transcript to view the original file.';
            $hasPreview = false;
        }
    } elseif ($hasPreview) {
        $excerpt = $rawContent;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($excerpt, 'UTF-8') > $maxPreviewChars) {
                $excerpt = mb_substr($excerpt, 0, $maxPreviewChars, 'UTF-8');
                $truncated = true;
            }
        } else {
            if (strlen($excerpt) > $maxPreviewChars) {
                $excerpt = substr($excerpt, 0, $maxPreviewChars);
                $truncated = true;
            }
        }
    } else {
        $excerpt = 'No preview text is available for this document yet. If the document was recently uploaded, please allow the retrieval pipeline to finish processing.';
    }

    $meta = [
        'id'                   => (int)$row['id'],
        'chat_id'              => (string)$row['chat_id'],
        'name'                 => (string)$row['name'],
        'type'                 => (string)$row['type'],
        'source'               => (string)($row['source'] ?? ''),
        'token_length'         => $row['document_token_length'] !== null ? (int)$row['document_token_length'] : null,
        'full_text_available'  => (bool)$row['full_text_available'],
        'ready'                => ((int)$row['document_ready'] === 1),
        'has_preview'          => $hasPreview,
        'excerpt_truncated'    => $truncated,
        'generated_at'         => date('c'),
        'image_src'            => ($isImage && $rawContent !== '') ? $rawContent : null,
        'document_content'     => (!$isImage && $hasPreview) ? $rawContent : null,
    ];

    echo json_encode([
        'ok'       => true,
        'document' => $meta + ['excerpt' => $excerpt],
    ]);
} catch (Throwable $e) {
    error_log('document_excerpt error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Unable to load the requested document excerpt.',
    ]);
}
exit;
