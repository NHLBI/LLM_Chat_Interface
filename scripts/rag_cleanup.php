#!/usr/bin/env php
<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../inc/rag_cleanup.php';

if (empty($config['qdrant'])) {
    fwrite(STDERR, "Qdrant config missing\n");
    exit(1);
}

$docIds = $argv;
array_shift($docIds);
$docIds = array_map('intval', $docIds);
if (!$docIds) {
    fwrite(STDERR, "Usage: rag_cleanup.php <doc_id> [<doc_id> ...]\n");
    exit(1);
}

try {
    $pdo = get_connection();
    $result = ragCleanupProcessDocuments($pdo, $docIds, $config['qdrant']);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup error: " . $e->getMessage() . "\n");
    exit(1);
}
