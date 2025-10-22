<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/streaming.inc.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_data']['userid']) || empty($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authorized.']);
    exit;
}

$input = file_get_contents('php://input');
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$payload = [];

if ($contentType && stripos($contentType, 'application/json') !== false) {
    $payload = json_decode($input, true);
} else {
    $payload = $_POST;
    if (!$payload && $input) {
        parse_str($input, $payload);
    }
}

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload.']);
    exit;
}

$streamId = isset($payload['stream_id']) ? trim((string)$payload['stream_id']) : '';
$action = isset($payload['action']) ? strtolower((string)$payload['action']) : 'stop';

if ($streamId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $streamId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid stream id.']);
    exit;
}

if ($action !== 'stop') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported action.']);
    exit;
}

$ok = stream_request_stop($streamId);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to signal stream stop.']);
    exit;
}

echo json_encode(['ok' => true]);
