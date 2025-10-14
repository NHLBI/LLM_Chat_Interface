<?php
declare(strict_types=1);

require_once __DIR__ . '/session_init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    $payload = $_POST ?? [];
} else {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    } else {
        $payload = ['raw' => $raw];
    }
}

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    $logDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/chat_logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        error_log('log_client_event: unable to create log directory');
        echo json_encode(['ok' => false]);
        exit;
    }
}

$entry = [
    'timestamp'   => date('c'),
    'user'        => $_SESSION['user_data']['userid'] ?? null,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'event'       => $payload['event'] ?? null,
    'chat_id'     => $payload['chat_id'] ?? null,
    'details'     => $payload,
];

$logLine = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;
@file_put_contents($logDir . '/client_events.log', $logLine, FILE_APPEND | LOCK_EX);

echo json_encode(['ok' => true]);
