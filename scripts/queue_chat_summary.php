#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../get_config.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../inc/chat_summary.inc.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/queue_chat_summary.php <chat_id> <user> [--force]\n");
    exit(1);
}

$chatId = $argv[1];
$user   = $argv[2];
$force  = in_array('--force', $argv, true);

$job = chat_summary_maybe_enqueue($chatId, $user, [
    'force' => $force,
]);

if ($job === null) {
    fwrite(STDERR, "Failed to enqueue summary job for chat {$chatId}\n");
    exit(1);
}

fwrite(STDOUT, "Queued summary job: {$job}\n");
