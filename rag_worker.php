#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI worker that processes queued RAG indexing jobs.
 *
 * Usage: php rag_worker.php [--max-jobs=N]
 */

require_once __DIR__ . '/get_config.php';
require_once __DIR__ . '/inc/rag_paths.php';

$ragPaths     = rag_workspace_paths($config ?? null);
$queueDir     = $ragPaths['queue'];
$completedDir = $ragPaths['completed'];
$failedDir    = $ragPaths['failed'];
$logsDir      = $ragPaths['logs'];
$metricsLog   = $logsDir . '/processing_metrics.log';
$python       = rag_python_binary($config ?? null);
$indexer      = rag_indexer_script($config ?? null);

$maxJobs = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = (int)substr($arg, strlen('--max-jobs='));
    }
}

foreach ([$queueDir, $completedDir, $failedDir, $logsDir] as $dir) {
    ensureDirectory($dir);
}

$jobs = glob($queueDir . '/*.json');
sort($jobs);

if (!$jobs) {
    echo "No queued jobs.\n";
    exit(0);
}

$processed = 0;
foreach ($jobs as $jobFile) {
    if ($maxJobs !== null && $processed >= $maxJobs) {
        break;
    }

    $payload = decodeJob($jobFile);
    if ($payload === null) {
        moveJob($jobFile, $failedDir);
        continue;
    }

    $documentId = $payload['document_id'] ?? 'unknown';
    $logPath    = sprintf('%s/index_%s_%s.log', $logsDir, $documentId, date('Ymd_His'));

    [$exitCode, $stdout, $stderr] = runIndexer($python, $indexer, $jobFile);
    $combined = trim($stdout . "\n" . $stderr);
    file_put_contents($logPath, $combined);

    $result = extractResult($combined);

    logProcessingMetrics($metricsLog, $payload, $result, $exitCode, $combined);

    if ($exitCode === 0 && is_array($result) && !empty($result['ok'])) {
        echo sprintf("[%s] document_id=%s ok\n", date('c'), $documentId);
        moveJob($jobFile, $completedDir);
        if (!empty($payload['cleanup_tmp']) && !empty($payload['file_path'])) {
            @unlink($payload['file_path']);
        }
    } else {
        echo sprintf("[%s] document_id=%s failed (rc=%d)\n", date('c'), $documentId, $exitCode);
        moveJob($jobFile, $failedDir);
    }

    $processed++;
}

exit(0);

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function decodeJob(string $jobFile): ?array
{
    $raw = file_get_contents($jobFile);
    if ($raw === false) {
        echo sprintf("Failed to read job file: %s\n", $jobFile);
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        echo sprintf("Invalid JSON in job file: %s\n", $jobFile);
        return null;
    }

    return $decoded;
}

function runIndexer(string $python, string $indexer, string $jobFile): array
{
    $cmd = sprintf(
        '%s -u %s --json %s',
        escapeshellarg($python),
        escapeshellarg($indexer),
        escapeshellarg($jobFile)
    );

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptorSpec, $pipes, dirname($indexer), [
        'PYTHONPATH' => dirname($indexer),
        'PATH'       => getenv('PATH') ?: '',
    ]);

    if (!is_resource($process)) {
        return [1, '', 'proc_open failed'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

function extractResult(string $combined): ?array
{
    $lines = preg_split("/\r\n|\n|\r/", trim($combined));
    if (!$lines) {
        return null;
    }

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim($lines[$i]);
        if ($line === '') {
            continue;
        }

        $candidate = json_decode($line, true);
        if (is_array($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function moveJob(string $jobFile, string $targetDir): void
{
    $target = $targetDir . '/' . basename($jobFile);
    if (!@rename($jobFile, $target)) {
        echo sprintf("Failed to move job %s to %s\n", $jobFile, $targetDir);
    }
}

function logProcessingMetrics(string $metricsPath, array $payload, ?array $result, int $exitCode, string $rawLog): void
{
    $entry = [
        'timestamp'            => date('c'),
        'status'               => ($exitCode === 0 && !empty($result['ok'])),
        'document_id'          => $payload['document_id'] ?? null,
        'chat_id'              => $payload['chat_id'] ?? null,
        'user'                 => $payload['user'] ?? null,
        'filename'             => $payload['filename'] ?? null,
        'mime'                 => $payload['mime'] ?? null,
        'original_size_bytes'  => $payload['original_size_bytes'] ?? null,
        'parsed_size_bytes'    => $payload['parsed_size_bytes'] ?? null,
        'queue_wait_sec'       => isset($payload['queue_timestamp']) ? max(0, time() - (int)$payload['queue_timestamp']) : null,
        'processing_elapsed_sec'=> $result['elapsed_sec'] ?? null,
        'chunk_count'          => $result['chunk_count'] ?? null,
    ];

    if ($entry['status'] === false) {
        $entry['error'] = $result['error'] ?? trim(substr($rawLog, -400));
    }

    $encoded = json_encode($entry, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }

    @file_put_contents($metricsPath, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
}
