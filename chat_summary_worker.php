#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/get_config.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inc/chat_summary.inc.php';
require_once __DIR__ . '/inc/utils.inc.php';
require_once __DIR__ . '/inc/azure-api.inc.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(sys_get_temp_dir());
    session_start();
}

$paths = chat_summary_paths($config ?? null);
chat_summary_ensure_directories($paths);

$logDir   = $paths['logs'];
$queueDir = $paths['queue'];
$completedDir = $paths['completed'];
$failedDir    = $paths['failed'];

$maxJobs = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-jobs=')) {
        $maxJobs = (int)substr($arg, strlen('--max-jobs='));
    }
}

$jobs = glob($queueDir . '/*.json');
sort($jobs);

if (!$jobs) {
    echo "No queued chat-summary jobs.\n";
    exit(0);
}

$processed = 0;
foreach ($jobs as $jobPath) {
    if ($maxJobs !== null && $processed >= $maxJobs) {
        break;
    }

    $raw = file_get_contents($jobPath);
    if ($raw === false) {
        echo "Unable to read job {$jobPath}\n";
        move_job($jobPath, $failedDir);
        continue;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        echo "Invalid JSON payload in {$jobPath}\n";
        move_job($jobPath, $failedDir);
        continue;
    }

    $chatId = $payload['chat_id'] ?? '[unknown]';
    $result = chat_summary_process_job($payload, $config ?? []);

    $logPayload = [
        'timestamp' => date('c'),
        'chat_id'   => $chatId,
        'status'    => !empty($result['ok']) ? 'ok' : 'failed',
        'error'     => $result['error'] ?? null,
        'metadata'  => $result['metadata'] ?? null,
    ];

    $logFile = $logDir . '/summary_' . $chatId . '_' . date('Ymd_His') . '.log';
    file_put_contents($logFile, json_encode($logPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    if (!empty($result['ok'])) {
        echo '[' . date('c') . "] chat_id={$chatId} summary updated\n";
        move_job($jobPath, $completedDir);
    } else {
        echo '[' . date('c') . "] chat_id={$chatId} summary failed: {$logPayload['error']}\n";
        move_job($jobPath, $failedDir);
    }

    $processed++;
}

exit(0);

function move_job(string $jobPath, string $targetDir): void
{
    $target = $targetDir . '/' . basename($jobPath);
    if (!@rename($jobPath, $target)) {
        @unlink($jobPath);
    }
}
