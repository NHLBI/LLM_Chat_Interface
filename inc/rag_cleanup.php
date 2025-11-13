<?php
declare(strict_types=1);

require_once __DIR__ . '/rag_paths.php';

function ragCleanupProcessDocuments(PDO $pdo, array $docIds, array $qdrantConfig, ?string $workspaceRoot = null, ?string $logFile = null): array
{
    $docIds = array_values(array_unique(array_map('intval', $docIds)));
    $docIds = array_filter($docIds, static fn(int $id): bool => $id > 0);

    if (empty($docIds)) {
        return [
            'doc_count'      => 0,
            'collections'    => [],
            'qdrant_batches' => 0,
            'removed_jobs'   => [],
        ];
    }

    $defaultCollection = $qdrantConfig['collection'] ?? 'nhlbi';
    $workspaceRoot = $workspaceRoot
        ? rtrim($workspaceRoot, DIRECTORY_SEPARATOR)
        : rag_workspace_root($GLOBALS['config'] ?? null);

    $collectionMap = ragCleanupCollectCollections($pdo, $docIds, $defaultCollection);

    $qdrantBatches = 0;
    if (!empty($collectionMap)) {
        $qdrantBatches = ragCleanupDeleteQdrant($collectionMap, $qdrantConfig, $logFile);
    }

    ragCleanupDeleteRagIndex($pdo, $docIds);
    $removedJobs = ragCleanupRemoveQueueJobs($docIds, $workspaceRoot);

    return [
        'doc_count'      => count($docIds),
        'collections'    => array_map('count', $collectionMap),
        'qdrant_batches' => $qdrantBatches,
        'removed_jobs'   => $removedJobs,
    ];
}

function ragCleanupCollectCollections(PDO $pdo, array $docIds, string $defaultCollection): array
{
    if (empty($docIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $pdo->prepare("SELECT document_id, collection FROM rag_index WHERE document_id IN ($placeholders)");
    $stmt->execute($docIds);

    $collectionMap = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $docId = (int)($row['document_id'] ?? 0);
        if ($docId <= 0) {
            continue;
        }
        $collection = $row['collection'] ?? '';
        if ($collection === '' || $collection === null) {
            $collection = $defaultCollection;
        }
        $collectionMap[$collection][] = $docId;
    }

    return $collectionMap;
}

function ragCleanupDeleteRagIndex(PDO $pdo, array $docIds): void
{
    if (empty($docIds)) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($docIds), '?'));
    $stmt = $pdo->prepare("DELETE FROM rag_index WHERE document_id IN ($placeholders)");
    $stmt->execute($docIds);
}

function ragCleanupRemoveQueueJobs(array $docIds, ?string $workspaceRoot = null): array
{
    if (empty($docIds)) {
        return [];
    }

    $docIds = array_map('intval', $docIds);
    $workspaceRoot = $workspaceRoot
        ? rtrim($workspaceRoot, DIRECTORY_SEPARATOR)
        : rag_workspace_root($GLOBALS['config'] ?? null);

    $queueDir = $workspaceRoot . '/queue';
    $removed = [];

    if (!is_dir($queueDir)) {
        return $removed;
    }

    $parsedDir = $workspaceRoot . '/parsed';

    $docIdSet = array_flip($docIds);

    foreach (glob($queueDir . '/*.json') as $jobFile) {
        $contents = json_decode(@file_get_contents($jobFile), true);
        if (!is_array($contents)) {
            continue;
        }

        $jobDocId = isset($contents['document_id']) ? (int)$contents['document_id'] : 0;
        if (!isset($docIdSet[$jobDocId])) {
            continue;
        }

        if (!empty($contents['file_path']) && is_string($contents['file_path'])) {
            $parsedPath = $contents['file_path'];
            if (strpos($parsedPath, $parsedDir) === 0 && file_exists($parsedPath)) {
                @unlink($parsedPath);
            }
        }

        @unlink($jobFile);
        $removed[] = $jobFile;
    }

    return $removed;
}

function ragCleanupDeleteQdrant(array $collectionMap, array $cfg, ?string $logFile = null): int
{
    if (empty($collectionMap)) {
        return 0;
    }

    $baseUrl = rtrim((string)($cfg['url'] ?? 'http://127.0.0.1:6333'), '/');
    $apiKey  = $cfg['api_key'] ?? '';

    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'api-key: ' . $apiKey;
    }

    $batches = 0;

    foreach ($collectionMap as $collection => $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            continue;
        }

        foreach (array_chunk($ids, 256) as $chunk) {
            $payload = json_encode([
                'filter' => [
                    'must' => [
                        [
                            'key'   => 'document_id',
                            'match' => ['any' => $chunk],
                        ],
                    ],
                ],
            ]);

            if ($payload === false) {
                throw new RuntimeException('Failed to encode Qdrant delete payload');
            }

            $endpoint = $baseUrl . '/collections/' . rawurlencode($collection) . '/points/delete';
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS    => $payload,
                CURLOPT_HTTPHEADER    => $headers,
                CURLOPT_RETURNTRANSFER=> true,
                CURLOPT_TIMEOUT       => 30,
            ]);

            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno) {
                $message = 'Qdrant delete failed: ' . curl_strerror($errno);
                if ($logFile) {
                    error_log($message, 3, $logFile);
                }
                throw new RuntimeException($message);
            }

            if ($status >= 300) {
                $message = 'Qdrant delete returned HTTP ' . $status . ': ' . $response;
                if ($logFile) {
                    error_log($message, 3, $logFile);
                }
                throw new RuntimeException($message);
            }

            $batches++;
        }
    }

    return $batches;
}
