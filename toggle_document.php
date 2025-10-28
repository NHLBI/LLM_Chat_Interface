<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_data']['userid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput ?: '[]', true);

$documentId = isset($payload['document_id']) ? (int)$payload['document_id'] : 0;
$chatId = isset($payload['chat_id']) ? trim((string)$payload['chat_id']) : '';
$requestedState = $payload['enabled'] ?? null;

if ($documentId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid document id']);
    exit;
}

$userId = $_SESSION['user_data']['userid'];

try {
    global $pdo;

    $pdo->beginTransaction();

    $query = "
        SELECT d.chat_id, d.enabled
          FROM document d
          JOIN chat c ON c.id = d.chat_id
         WHERE d.id = :doc_id
           AND c.user = :user
           AND d.deleted = 0
         FOR UPDATE
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':doc_id' => $documentId,
        ':user'   => $userId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Document not found']);
        exit;
    }

    $chatIdFromDb = (string)$row['chat_id'];
    if ($chatId !== '' && $chatId !== $chatIdFromDb) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }

    $currentEnabled = (int)$row['enabled'] === 1;

    $newEnabled = $currentEnabled;
    if (is_bool($requestedState) || $requestedState === 0 || $requestedState === 1 || $requestedState === '0' || $requestedState === '1') {
        $newEnabled = filter_var($requestedState, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($newEnabled === null) {
            $newEnabled = $currentEnabled;
        }
    } else {
        $newEnabled = !$currentEnabled;
    }

    $update = $pdo->prepare("UPDATE document SET enabled = :enabled WHERE id = :doc_id");
    $update->execute([
        ':enabled' => $newEnabled ? 1 : 0,
        ':doc_id'  => $documentId,
    ]);

    $pdo->commit();

    echo json_encode([
        'document_id' => $documentId,
        'chat_id'     => $chatIdFromDb,
        'enabled'     => $newEnabled,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('toggle_document error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to toggle document']);
}
