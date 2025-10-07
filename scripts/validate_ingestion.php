#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the CLI\n");
    exit(1);
}

$options = getopt('', [
    'document::',
    'config::',
    'user::',
    'keep',
    'timeout::',
    'verbose',
]);

$documentPath = $options['document'] ?? (__DIR__ . '/../tests/fixtures/rag_ingestion_sample.txt');
if (!is_file($documentPath)) {
    fwrite(STDERR, "Document not found: {$documentPath}\n");
    exit(1);
}
$documentPath = realpath($documentPath) ?: $documentPath;

if (!empty($options['config'])) {
    putenv('CHAT_CONFIG_PATH=' . $options['config']);
}

require_once __DIR__ . '/../get_config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../inc/rag_paths.php';
require_once __DIR__ . '/../inc/rag_cleanup.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$pdo = get_connection();
$GLOBALS['pdo'] = $pdo;
$GLOBALS['config'] = $config;

$user = $options['user'] ?? ('rag.validation.' . date('YmdHis'));
$_SESSION['user_data']['userid'] = $user;
$_SESSION['temperature'] = $_SESSION['temperature'] ?? '0.7';
$_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
$_SESSION['verbosity'] = $_SESSION['verbosity'] ?? 'medium';
$_SESSION['deployment'] = $_SESSION['deployment'] ?? ($config['azure']['default'] ?? 'azure-gpt4-turbo');

$chatTitle = 'RAG Validation ' . date('Y-m-d H:i:s');
$chatId = create_chat($user, $chatTitle, 'Automated ingestion validation', $_SESSION['deployment']);

$originalName = basename($documentPath);
$mimeType = mime_content_type($documentPath) ?: 'text/plain';
$originalSize = filesize($documentPath) ?: 0;
$parsedText = file_get_contents($documentPath);
if ($parsedText === false) {
    fwrite(STDERR, "Failed to read document contents\n");
    exit(1);
}

$tokenCount = get_token_count($parsedText);
$fileSha = hash_file('sha256', $documentPath);
$contentSha = hash('sha256', $parsedText);

$insertStmt = $pdo->prepare('INSERT INTO document (chat_id, name, file_sha256, content_sha256, version, type, content, document_token_length, source, deleted)
    VALUES (:chat_id, :name, :file_sha, :content_sha, 1, :type, :content, :tokens, :source, 0)');
$insertStmt->execute([
    ':chat_id'   => $chatId,
    ':name'      => $originalName,
    ':file_sha'  => $fileSha,
    ':content_sha' => $contentSha,
    ':type'      => $mimeType,
    ':content'   => $parsedText,
    ':tokens'    => $tokenCount,
    ':source'    => 'validation',
]);
$documentId = (int)$pdo->lastInsertId();

$paths = rag_workspace_paths($config ?? null);
foreach ($paths as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Unable to create directory: {$dir}\n");
        exit(1);
    }
}

$parsedFile = $paths['parsed'] . '/rag_validation_' . bin2hex(random_bytes(6)) . '.txt';
if (file_put_contents($parsedFile, $parsedText) === false) {
    fwrite(STDERR, "Failed to write parsed text: {$parsedFile}\n");
    exit(1);
}

$configPath = getenv('CHAT_CONFIG_PATH') ?: ($config_file ?? '/etc/apps/chatdev_config.ini');
$jobPayload = [
    'document_id'        => $documentId,
    'chat_id'            => $chatId,
    'user'               => $user,
    'embedding_model'    => 'NHLBI-Chat-workflow-text-embedding-3-large',
    'config_path'        => $configPath,
    'file_path'          => $parsedFile,
    'filename'           => $originalName,
    'mime'               => $mimeType,
    'cleanup_tmp'        => true,
    'original_size_bytes'=> $originalSize,
    'parsed_size_bytes'  => strlen($parsedText),
    'queue_timestamp'    => time(),
];

$jobFile = $paths['queue'] . '/job_validation_' . bin2hex(random_bytes(6)) . '.json';
if (file_put_contents($jobFile, json_encode($jobPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
    fwrite(STDERR, "Failed to write queue job: {$jobFile}\n");
    exit(1);
}

$workerCmd = sprintf('%s %s --max-jobs=1', escapeshellcmd(PHP_BINARY), escapeshellarg(__DIR__ . '/../rag_worker.php'));
$workerOutput = [];
$workerRc = 0;
exec($workerCmd, $workerOutput, $workerRc);

$timeout = isset($options['timeout']) ? (int)$options['timeout'] : 60;
$deadline = time() + max(5, $timeout);
$indexRow = null;

while (time() <= $deadline) {
    $stmt = $pdo->prepare('SELECT id, chunk_count, ready, updated_at FROM rag_index WHERE document_id = :doc_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':doc_id' => $documentId]);
    $indexRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($indexRow && (int)$indexRow['ready'] === 1 && (int)$indexRow['chunk_count'] > 0) {
        break;
    }
    usleep(250000);
}

$success = $indexRow && (int)$indexRow['ready'] === 1 && (int)$indexRow['chunk_count'] > 0 && $workerRc === 0;

$result = [
    'ok' => $success,
    'document_id' => $documentId,
    'chat_id' => $chatId,
    'chunk_count' => $indexRow['chunk_count'] ?? 0,
    'worker_rc' => $workerRc,
    'worker_output' => $workerOutput,
    'job_file' => $jobFile,
];

if (!$success) {
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT) . "\n");
}

try {
    ragCleanupProcessDocuments($pdo, [$documentId], $config['qdrant'] ?? []);
} catch (Throwable $cleanupError) {
    $result['cleanup_warning'] = $cleanupError->getMessage();
}

if (empty($options['keep'])) {
    $pdo->prepare('DELETE FROM document WHERE id = :id')->execute([':id' => $documentId]);
    $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute([':id' => $chatId]);
}

echo json_encode($result, JSON_PRETTY_PRINT) . "\n";

exit($success ? 0 : 1);
