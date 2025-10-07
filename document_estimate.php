<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_data']['userid'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$rawInput = file_get_contents('php://input');
$payload  = json_decode($rawInput ?: '[]', true);
$documents = [];

if (is_array($payload) && isset($payload['documents']) && is_array($payload['documents'])) {
    $documents = $payload['documents'];
}

if (empty($documents)) {
    echo json_encode([
        'documents'          => [],
        'total_estimate_sec' => 0,
        'estimate_source'    => 'default',
        'total_data_points'  => 0,
        'default_sec_per_mb' => 25,
    ]);
    exit;
}

$metricsDir  = rag_workspace_paths($config ?? null)['logs'];
$metricsFile = $metricsDir . '/processing_metrics.log';
$stats = [
    'global' => ['total_time' => 0.0, 'total_size' => 0.0, 'count' => 0],
    'mime'   => []
];

if (is_readable($metricsFile)) {
    $handle = @fopen($metricsFile, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $entry = json_decode($line, true);
            if (!is_array($entry) || empty($entry['status'])) {
                continue;
            }
            $time = isset($entry['processing_elapsed_sec']) ? (float)$entry['processing_elapsed_sec'] : 0.0;
            $size = $entry['parsed_size_bytes'] ?? $entry['original_size_bytes'] ?? null;
            if (!$time || !$size) {
                continue;
            }
            $size = (float)$size;
            if ($size <= 0) {
                continue;
            }
            $mime = strtolower((string)($entry['mime'] ?? ''));

            $stats['global']['total_time'] += $time;
            $stats['global']['total_size'] += $size;
            $stats['global']['count']++;

            if ($mime === '') {
                continue;
            }
            if (!isset($stats['mime'][$mime])) {
                $stats['mime'][$mime] = ['total_time' => 0.0, 'total_size' => 0.0, 'count' => 0];
            }
            $stats['mime'][$mime]['total_time'] += $time;
            $stats['mime'][$mime]['total_size'] += $size;
            $stats['mime'][$mime]['count']++;
        }
        fclose($handle);
    }
}

define('DEFAULT_SEC_PER_MB', 25.0);

$totalEstimate   = 0.0;
$resultDocuments = [];
$sourcesUsed     = [];
$totalDataPoints = 0;

foreach ($documents as $doc) {
    $docId = isset($doc['id']) ? (int)$doc['id'] : null;
    $mime  = isset($doc['mime']) ? strtolower((string)$doc['mime']) : '';
    $size  = null;

    if (isset($doc['parsed_size']) && $doc['parsed_size']) {
        $size = (float)$doc['parsed_size'];
    } elseif (isset($doc['original_size']) && $doc['original_size']) {
        $size = (float)$doc['original_size'];
    }

    $estimate   = null;
    $source     = 'default';
    $dataPoints = 0;

    if ($size && $size > 0) {
        if ($mime && isset($stats['mime'][$mime]) && $stats['mime'][$mime]['count'] >= 3 && $stats['mime'][$mime]['total_size'] > 0) {
            $ratio    = $stats['mime'][$mime]['total_time'] / $stats['mime'][$mime]['total_size'];
            $estimate = $ratio * $size;
            $source   = 'mime';
            $dataPoints = $stats['mime'][$mime]['count'];
        } elseif ($stats['global']['count'] >= 5 && $stats['global']['total_size'] > 0) {
            $ratio    = $stats['global']['total_time'] / $stats['global']['total_size'];
            $estimate = $ratio * $size;
            $source   = 'global';
            $dataPoints = $stats['global']['count'];
        } else {
            $ratio    = DEFAULT_SEC_PER_MB / (1024 * 1024);
            $estimate = $ratio * $size;
            $source   = 'default';
            $dataPoints = $stats['global']['count'];
        }

        if (!is_finite($estimate) || $estimate <= 0) {
            $estimate = (DEFAULT_SEC_PER_MB / (1024 * 1024)) * $size;
            $source   = 'default';
        }

        $estimate = max(2.0, $estimate);
        $totalEstimate += $estimate;
        $sourcesUsed[$source] = true;
        if ($dataPoints) {
            $totalDataPoints = max($totalDataPoints, $dataPoints);
        }
    }

    $confidence = 'low';
    if ($dataPoints >= 20) {
        $confidence = 'high';
    } elseif ($dataPoints >= 5) {
        $confidence = 'medium';
    }

    $resultDocuments[] = [
        'id'           => $docId,
        'mime'         => $mime,
        'size_bytes'   => $size,
        'estimate_sec' => $estimate,
        'source'       => $source,
        'data_points'  => $dataPoints,
        'confidence'   => $confidence,
    ];
}

$overallSource = 'default';
if (!empty($sourcesUsed)) {
    $keys = array_keys($sourcesUsed);
    $overallSource = count($keys) === 1 ? $keys[0] : 'mixed';
}

echo json_encode([
    'documents'          => $resultDocuments,
    'total_estimate_sec' => $totalEstimate,
    'estimate_source'    => $overallSource,
    'total_data_points'  => $totalDataPoints,
    'default_sec_per_mb' => DEFAULT_SEC_PER_MB,
]);
