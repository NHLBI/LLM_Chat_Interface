<?php
require_once 'get_config.php';
require_once 'db.php';
require_once __DIR__ . '/inc/rag_cleanup.php';
$pdo = get_connection();

// Determine log file path based on environment
$logFile = '/var/log/chat_dev';
if (isset($config['environment'])) {
    switch ($config['environment']) {
        case 'dev':
            $logFile .= '/purge_chats.log';
            break;
        case 'test':
            $logFile .= '/purge_chats.log';
            break;
        case 'prod':
        default:
            $logFile .= '/purge_chats.log';
            break;
    }
} else {
    // Default to prod if environment is not set
    $logFile .= '/purge_chats.log';
}

date_default_timezone_set('America/New_York');  // or your TZ

// Log the database being used
error_log(
    "\n[" . date('Y-m-d H:i:s') . '] Database used: ' . $config['database']['dbname'] . "\n",
    3,
    $logFile
);

// Calculate thresholds up front
$soft_delay_days = (int)$config['app']['soft_delay'];
$hard_delay_days = (int)$config['app']['purge_delay'];

$hard_threshold = (new DateTime())
                    ->modify("-{$hard_delay_days} days")
                    ->format('Y-m-d');
$soft_threshold = (new DateTime())
                    ->modify("-{$soft_delay_days} days")
                    ->format('Y-m-d');

// 1) Hard delete
$hardDeleted = hard_delete_old_chats($logFile, $hard_threshold);
if ($hardDeleted === false) {
    error_log(
        '[' . date('Y-m-d') . "] Hard delete failed using threshold: $hard_threshold\n",
        3,
        $logFile
    );
} elseif (empty($hardDeleted)) {
    error_log(
        '[' . date('Y-m-d') . "] No chats qualified for hard delete using threshold: $hard_threshold\n",
        3,
        $logFile
    );
} else {
    $pairs = implode(', ', $hardDeleted);
    error_log(
        '[' . date('Y-m-d') . '] Hard-deleted chats (' . count($hardDeleted) .
        "): IDs [$pairs] using threshold: $hard_threshold\n",
        3,
        $logFile
    );
}

// 2) Soft delete
$softDeleted = soft_delete_old_chats($logFile, $soft_threshold);
if ($softDeleted === false) {
    error_log(
        '[' . date('Y-m-d') . "] Soft delete failed using threshold: $soft_threshold\n",
        3,
        $logFile
    );
} elseif (empty($softDeleted)) {
    error_log(
        '[' . date('Y-m-d') . "] No chats qualified for soft delete using threshold: $soft_threshold\n",
        3,
        $logFile
    );
} else {
    $pairs = implode(', ', $softDeleted);
    error_log(
        '[' . date('Y-m-d') . '] Soft-deleted chats (' . count($softDeleted) .
        "): IDs [$pairs] using threshold: $soft_threshold\n",
        3,
        $logFile
    );
}

// 3) Purge RAG index / Qdrant for deleted content
$ragOutcome = purge_rag_artifacts($logFile);
if ($ragOutcome === false) {
    error_log(
        '[' . date('Y-m-d') . "] RAG cleanup failed\n",
        3,
        $logFile
    );
} elseif (!empty($ragOutcome['doc_count'])) {
    $summary = sprintf(
        'removed %d doc(s) across %d collection(s); qdrant=%d batch(es)',
        $ragOutcome['doc_count'],
        count($ragOutcome['collections'] ?? []),
        $ragOutcome['qdrant_batches'] ?? 0
    );
    error_log(
        '[' . date('Y-m-d') . "] RAG cleanup: $summary\n",
        3,
        $logFile
    );
}

exit(0);

function purge_rag_artifacts(string $logFile)
{
    global $pdo, $config;

    $qdrantCfg = $config['qdrant'] ?? [];
    $defaultCollection = $qdrantCfg['collection'] ?? 'nhlbi';

    $sql = "
        SELECT DISTINCT
               ri.document_id,
               COALESCE(ri.collection, :default_collection) AS collection
          FROM rag_index ri
          JOIN document d ON d.id = ri.document_id
          LEFT JOIN chat c ON c.id = d.chat_id
         WHERE d.deleted = 1
            OR (c.id IS NOT NULL AND c.deleted = 1)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':default_collection' => $defaultCollection]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $docIds = [];
    foreach ($rows as $row) {
        $docId = (int)$row['document_id'];
        if ($docId > 0) {
            $docIds[] = $docId;
        }
    }

    $docIds = array_values(array_unique($docIds));
    if (empty($docIds)) {
        return [];
    }

    try {
        $summary = ragCleanupProcessDocuments($pdo, $docIds, $qdrantCfg, null, $logFile);
    } catch (Throwable $e) {
        error_log('RAG cleanup failed: ' . $e->getMessage(), 3, $logFile);
        return false;
    }

    return [
        'doc_count'       => $summary['doc_count'],
        'collections'     => $summary['collections'],
        'qdrant_batches'  => $summary['qdrant_batches'],
    ];
}
