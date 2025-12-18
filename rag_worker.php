#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI worker that processes queued RAG indexing jobs.
 *
 * Usage: php rag_worker.php [--max-jobs=N]
 *
 * High-level flow:
 * 1) Discover queue/*.json (one job per document) and process in order.
 * 2) If a job has a source_path, run the Python parser and persist parsed text
 *    into the document row; small docs are marked inline and skip indexing.
 * 3) For RAG-sized docs, run the Python indexer; record status/progress in
 *    var/rag/status/doc_<id>.json so document_status.php can poll.
 * 4) Move jobs to completed/failed and log metrics under var/rag/logs/.
 *
 * Important assumptions:
 * - Concurrency: this script expects a single active worker per app. There is
 *   no job-claim/lock; running multiple instances risks double-processing.
 * - File system: queue/completed/failed/logs/status live under var/rag/.
 * - Resilience: per-doc logging is isolated; one failed job should not block
 *   the loop because failures move to failed/ and the loop continues.
 */

require_once __DIR__ . '/get_config.php';
require_once __DIR__ . '/inc/rag_paths.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/rag_processing_status.php';

$pdo = get_connection();

$ragPaths     = rag_workspace_paths($config ?? null);
$queueDir     = $ragPaths['queue'];
$completedDir = $ragPaths['completed'];
$failedDir    = $ragPaths['failed'];
$logsDir      = $ragPaths['logs'];
$metricsLog   = $logsDir . '/processing_metrics.log';
$python       = rag_python_binary($config ?? null);
$indexer      = rag_indexer_script($config ?? null);
$parser       = rag_parser_script($config ?? null);

$maxJobs = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = (int)substr($arg, strlen('--max-jobs='));
    }
}

foreach ([$queueDir, $completedDir, $failedDir, $logsDir] as $dir) {
    ensureDirectory($dir);
}
rag_processing_status_dir($ragPaths);

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

    // Decode and validate the job payload before doing any work.
    $payload = decodeJob($jobFile);
    if ($payload === null) {
        moveJob($jobFile, $failedDir);
        continue;
    }

    $documentId = (int)($payload['document_id'] ?? 0);
    if ($documentId <= 0) {
        moveJob($jobFile, $failedDir);
        continue;
    }

    $logPath = sprintf('%s/index_%s_%s.log', $logsDir, $documentId, date('Ymd_His'));

    // If we still have a source file, parse it first; otherwise we assume we're
    // resuming at the indexing step.
    if (!empty($payload['source_path'])) {
        $parseOutcome = parseAndPersistDocument(
            $payload,
            $python,
            $parser,
            $ragPaths,
            $pdo,
            $config ?? []
        );

        if (!$parseOutcome['ok']) {
            rag_processing_status_write($documentId, $ragPaths, [
                'status'   => 'failed',
                'stage'    => 'parsing',
                'progress' => $parseOutcome['progress'] ?? 0,
                'message'  => $parseOutcome['error'] ?? 'Parsing failed',
            ]);
            moveJob($jobFile, $failedDir);
            $processed++;
            continue;
        }

        $payload = $parseOutcome['payload'];

        if ($parseOutcome['should_index'] === false) {
            rag_processing_status_write($documentId, $ragPaths, [
                'status'   => 'complete',
                'stage'    => 'ready',
                'progress' => 100,
                'message'  => 'Document ready for chat',
            ]);
            rag_processing_status_clear($documentId, $ragPaths);
            moveJob($jobFile, $completedDir);
            $processed++;
            continue;
        }

        $payload['file_path']         = $parseOutcome['file_path'];
        $payload['parsed_size_bytes'] = $parseOutcome['parsed_size_bytes'];
        $payload['cleanup_tmp']       = true;

        if (!rewriteJobPayload($jobFile, $payload)) {
            rag_processing_status_write($documentId, $ragPaths, [
                'status'   => 'failed',
                'stage'    => 'parsing',
                'progress' => $parseOutcome['progress'] ?? 0,
                'message'  => 'Unable to persist parser results to job file',
            ]);
            moveJob($jobFile, $failedDir);
            $processed++;
            continue;
        }

        rag_processing_status_write($documentId, $ragPaths, [
            'status'   => 'queued',
            'stage'    => 'indexing',
            'progress' => max(60, (int)($parseOutcome['progress'] ?? 60)),
            'message'  => 'Waiting for RAG indexing',
        ]);
    } else {
        rag_processing_status_write($documentId, $ragPaths, [
            'status'   => 'running',
            'stage'    => 'indexing',
            'progress' => 0,
            'message'  => 'Indexing document',
        ]);
    }

    $indexProgressHint = isset($parseOutcome) ? max(70, (int)($parseOutcome['progress'] ?? 70)) : 70;

    rag_processing_status_write($documentId, $ragPaths, [
        'status'   => 'running',
        'stage'    => 'indexing',
        'progress' => $indexProgressHint,
        'message'  => 'RAG indexing document',
    ]);

    [$exitCode, $stdout, $stderr] = runIndexer($python, $indexer, $jobFile);
    $combined = trim($stdout . "\n" . $stderr);
    file_put_contents($logPath, $combined);

    $result = extractResult($combined);

    logProcessingMetrics($metricsLog, $payload, $result, $exitCode, $combined);

    if ($exitCode === 0 && is_array($result) && !empty($result['ok'])) {
        echo sprintf("[%s] document_id=%s ok\n", date('c'), $documentId);
        rag_processing_status_write($documentId, $ragPaths, [
            'status'   => 'complete',
            'stage'    => 'ready',
            'progress' => 100,
            'message'  => 'Document ready for chat',
        ]);
        rag_processing_status_clear($documentId, $ragPaths);
        moveJob($jobFile, $completedDir);
        if (!empty($payload['cleanup_tmp']) && !empty($payload['file_path'])) {
            @unlink($payload['file_path']);
        }
    } else {
        echo sprintf("[%s] document_id=%s failed (rc=%d)\n", date('c'), $documentId, $exitCode);
        rag_processing_status_write($documentId, $ragPaths, [
            'status'   => 'failed',
            'stage'    => 'indexing',
            'progress' => is_array($result) && isset($result['progress']) ? (int)$result['progress'] : 0,
            'message'  => is_array($result) && isset($result['error'])
                ? (string)$result['error']
                : trim(substr($combined, -400)),
        ]);
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

function rewriteJobPayload(string $jobFile, array $payload): bool
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($jobFile, $json) !== false;
}

function parseAndPersistDocument(
    array $payload,
    string $python,
    string $parser,
    array $paths,
    PDO $pdo,
    array $config
): array {
    $documentId = (int)($payload['document_id'] ?? 0);
    $sourcePath = $payload['source_path'] ?? null;
    $workflowMode = !empty($payload['workflow_mode']);
    if ($documentId <= 0 || !$sourcePath || !is_file($sourcePath)) {
        return [
            'ok'      => false,
            'error'   => 'Source file missing for parsing',
            'payload' => $payload,
        ];
    }

    rag_processing_status_write($documentId, $paths, [
        'status'   => 'running',
        'stage'    => 'parsing',
        'progress' => 1,
        'message'  => 'Parsing document',
    ]);

    $txtPath = $paths['parsed'] . '/rag_' . uniqid('', true) . '.txt';
    $parseHandle = fopen($txtPath, 'w');
    if ($parseHandle === false) {
        return [
            'ok'      => false,
            'error'   => 'Unable to create parsed output file',
            'payload' => $payload,
        ];
    }

    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = array_merge($_ENV ?? [], [
        'PARSER_STATUS_FILE' => rag_processing_status_path($documentId, $paths),
        'PARSER_JOB_ID'      => (string)$documentId,
    ]);

    $cmd = sprintf(
        '%s %s %s %s',
        escapeshellarg($python),
        escapeshellarg($parser),
        escapeshellarg($sourcePath),
        escapeshellarg($payload['filename'] ?? basename($sourcePath))
    );

    $pipes = [];
    $process = proc_open($cmd, $descriptorSpec, $pipes, __DIR__, $env + ['PATH' => getenv('PATH') ?: '']);
    if (!is_resource($process)) {
        fclose($parseHandle);
        return [
            'ok'      => false,
            'error'   => 'Unable to start parser process',
            'payload' => $payload,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], true);

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 8192);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            continue;
        }
        fwrite($parseHandle, $chunk);
    }

    $stderr = stream_get_contents($pipes[2]) ?: '';

    fclose($pipes[1]);
    fclose($pipes[2]);
    fclose($parseHandle);

    $rc = proc_close($process);
    if ($rc !== 0) {
        @unlink($txtPath);
        $message = sprintf('Parser exited with rc=%d %s', $rc, trim(substr($stderr, 0, 400)));
        error_log($message);
        if (!empty($payload['cleanup_tmp']) && is_file($sourcePath)) {
            @unlink($sourcePath);
        }
        return [
            'ok'      => false,
            'error'   => $message,
            'payload' => $payload,
        ];
    }

    $parsedSize = @filesize($txtPath);
    if ($parsedSize === false || $parsedSize === 0) {
        @unlink($txtPath);
        if (!empty($payload['cleanup_tmp']) && is_file($sourcePath)) {
            @unlink($sourcePath);
        }
        return [
            'ok'      => false,
            'error'   => 'Parser returned no output',
            'payload' => $payload,
        ];
    }

    // Persist the full parsed text in document.content for all modes.
    $maxDbBytes = null;
    $fullTextAvailable = 1;
    $parsedText = @file_get_contents($txtPath);
    if ($parsedText === false) {
        $parsedText = '';
    }

    $tokenLength = ($parsedText !== '')
        ? get_token_count($parsedText, 'cl100k_base')
        : token_count_from_file($txtPath);

    $ragInlineThreshold = isset($config['rag']['inline_fulltext_tokens'])
        ? (int)$config['rag']['inline_fulltext_tokens']
        : 4000;

    $shouldIndex = !$workflowMode;
    if ($shouldIndex && $fullTextAvailable && $tokenLength > 0 && $tokenLength <= $ragInlineThreshold) {
        $shouldIndex = false;
    }

    try {
        $stmt = $pdo->prepare("
            UPDATE document
               SET content = :content,
                   document_token_length = :tokens,
                   full_text_available = :full_text,
                   source = :source
             WHERE id = :id
             LIMIT 1
        ");
        $stmt->execute([
            ':content'   => $parsedText,
            ':tokens'    => $tokenLength,
            ':full_text' => $fullTextAvailable ? 1 : 0,
            ':source'    => $shouldIndex ? 'rag' : 'inline',
            ':id'        => $documentId,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to persist parsed document: ' . $e->getMessage());
        @unlink($txtPath);
        if (!empty($payload['cleanup_tmp']) && is_file($sourcePath)) {
            @unlink($sourcePath);
        }
        return [
            'ok'      => false,
            'error'   => 'Unable to update document row',
            'payload' => $payload,
        ];
    }

    if (!empty($payload['source_path']) && is_file($payload['source_path'])) {
        try {
            $fileSha = hash_file('sha256', $payload['source_path']);
            if ($fileSha) {
                $stmt = $pdo->prepare('UPDATE document SET file_sha256 = :sha WHERE id = :id LIMIT 1');
                $stmt->execute([
                    ':sha' => $fileSha,
                    ':id'  => $documentId,
                ]);
            }
        } catch (Throwable $e) {
            error_log('Failed to update file sha: ' . $e->getMessage());
        }
    }

    if (!empty($payload['cleanup_tmp']) && is_file($sourcePath)) {
        @unlink($sourcePath);
    }
    unset($payload['source_path']);

    if (!$shouldIndex) {
        @unlink($txtPath);
    }

    if (empty($payload['embedding_model'])) {
        $payload['embedding_model'] = "NHLBI-Chat-workflow-text-embedding-3-large";
    }

    return [
        'ok'                 => true,
        'payload'            => $payload,
        'file_path'          => $shouldIndex ? $txtPath : null,
        'parsed_size_bytes'  => $parsedSize ?: null,
        'should_index'       => $shouldIndex,
        'progress'           => $shouldIndex ? 60 : 100,
    ];
}

function token_count_from_file(string $path, int $chunkSize = 100000): int
{
    $fh = @fopen($path, 'r');
    if ($fh === false) {
        return 0;
    }
    $total = 0;
    while (!feof($fh)) {
        $chunk = fread($fh, $chunkSize);
        if ($chunk === false) {
            break;
        }
        if ($chunk === '') {
            continue;
        }
        $total += get_token_count($chunk, 'cl100k_base');
    }
    fclose($fh);
    return $total;
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
