<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/azure-api.inc.php';

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
if (!$exchangeId || $exchangeId < 1) {
    http_response_code(400);
    echo json_encode([
        'ok'      => false,
        'error'   => 'invalid_request',
        'message' => 'A valid exchange_id is required.',
    ]);
    exit;
}

$userId = (string)($_SESSION['user_data']['userid'] ?? '');

try {
    global $pdo;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available.');
    }

    $stmt = $pdo->prepare("
        SELECT c.user
        FROM exchange e
        INNER JOIN chat c ON c.id = e.chat_id
        WHERE e.id = :exchange_id
        LIMIT 1
    ");
    $stmt->execute(['exchange_id' => $exchangeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'No exchange found for the provided identifier.',
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

    $citations = fetch_rag_citations($exchangeId);
    if (empty($citations)) {
        http_response_code(404);
        echo json_encode([
            'ok'      => false,
            'error'   => 'not_found',
            'message' => 'No citation metadata is available for this exchange.',
        ]);
        exit;
    }

    echo json_encode([
        'ok'         => true,
        'citations'  => $citations,
    ]);
} catch (Throwable $e) {
    error_log('rag_citations error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'server_error',
        'message' => 'Unable to load citation metadata at this time.',
    ]);
}
exit;
