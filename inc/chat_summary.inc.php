<?php
declare(strict_types=1);

require_once __DIR__ . '/utils.inc.php';
require_once __DIR__ . '/../db.php';

if (!defined('CHAT_SUMMARY_DEPLOYMENT')) {
    define('CHAT_SUMMARY_DEPLOYMENT', 'azure-gpt4.1-mini');
}

/**
 * Returns the filesystem root for chat summary jobs.
 */
function chat_summary_workspace_root(?array $config = null): string
{
    $configured = null;
    if ($config) {
        if (!empty($config['chat_summary']['workspace_root'])) {
            $configured = $config['chat_summary']['workspace_root'];
        } elseif (!empty($config['app']['chat_summary_root'])) {
            $configured = $config['app']['chat_summary_root'];
        }
    }

    if (!$configured) {
        $env = getenv('CHAT_SUMMARY_ROOT');
        if ($env !== false && $env !== '') {
            $configured = $env;
        }
    }

    if ($configured) {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__) . '/var/chat_summary';
}

/**
 * Helper returning common paths used by the summary worker.
 */
function chat_summary_paths(?array $config = null): array
{
    $root = chat_summary_workspace_root($config);
    $resolvedRoot = $root;

    $parent = dirname($root);
    if (!is_dir($root) && (!is_dir($parent) || !is_writable($parent))) {
        $resolvedRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/chat_summary';
    }

    return [
        'root'      => $resolvedRoot,
        'queue'     => $resolvedRoot . '/queue',
        'logs'      => $resolvedRoot . '/logs',
        'completed' => $resolvedRoot . '/completed',
        'failed'    => $resolvedRoot . '/failed',
    ];
}

/**
 * Ensure the summary workspace directories exist.
 */
function chat_summary_ensure_directories(array $paths): bool
{
    foreach ($paths as $path) {
        if (is_dir($path)) {
            continue;
        }
        if (@mkdir($path, 0775, true) || is_dir($path)) {
            continue;
        }
        error_log('chat_summary: unable to create directory ' . $path);
        return false;
    }
    return true;
}

/**
 * Enqueue a chat summary job if one is not already pending.
 *
 * @return string|null Path to the queued job or null on failure.
 */
function chat_summary_enqueue(string $chatId, string $user, array $options = []): ?string
{
    global $config;

    $chatId = trim($chatId);
    if ($chatId === '' || $user === '') {
        return null;
    }

    $paths = chat_summary_paths($config ?? null);
    if (!chat_summary_ensure_directories($paths)) {
        return null;
    }

    $jobName = sprintf('summary_%s.json', $chatId);
    $jobPath = $paths['queue'] . '/' . $jobName;

    if (file_exists($jobPath)) {
        return $jobPath;
    }

    $payload = [
        'chat_id'         => $chatId,
        'user'            => $user,
        'deployment'      => $options['deployment'] ?? CHAT_SUMMARY_DEPLOYMENT,
        'queue_timestamp' => time(),
        'force'           => !empty($options['force']),
    ];

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return null;
    }

    $tmpPath = $jobPath . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmpPath, $encoded) === false) {
        return null;
    }

    if (!@rename($tmpPath, $jobPath)) {
        @unlink($tmpPath);
        return null;
    }

    return $jobPath;
}

function chat_summary_decode_summary(?string $payload): ?array
{
    if ($payload === null) {
        return null;
    }
    $payload = trim($payload);
    if ($payload === '') {
        return null;
    }
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function chat_summary_should_enqueue(string $chatId, string $user, int $minExchanges = 5): bool
{
    $existingPayload = get_chat_summary($chatId, $user);
    $decoded         = chat_summary_decode_summary($existingPayload);

    if ($decoded === null) {
        return true;
    }

    $metadata       = $decoded['metadata'] ?? [];
    $lastExchangeId = isset($metadata['last_exchange_id']) ? (int)$metadata['last_exchange_id'] : 0;

    if ($minExchanges <= 0) {
        $minExchanges = 1;
    }

    if ($lastExchangeId <= 0) {
        return true;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt
              FROM exchange
             WHERE chat_id = :chat_id
               AND id > :last_id
               AND deleted = 0
        ");
        $stmt->execute([
            'chat_id' => $chatId,
            'last_id' => $lastExchangeId,
        ]);
        $count = (int)$stmt->fetchColumn();
        return $count >= $minExchanges;
    } catch (PDOException $e) {
        error_log('chat_summary_should_enqueue failed: ' . $e->getMessage());
        return true;
    }
}

function chat_summary_maybe_enqueue(string $chatId, string $user, array $options = []): ?string
{
    $minExchanges = isset($options['min_exchanges']) ? (int)$options['min_exchanges'] : 5;
    $force        = !empty($options['force']);

    if (!$force && !chat_summary_should_enqueue($chatId, $user, $minExchanges)) {
        return null;
    }

    return chat_summary_enqueue($chatId, $user, $options);
}

function chat_summary_format_message(string $chatId, string $user): ?array
{
    $payload = chat_summary_decode_summary(get_chat_summary($chatId, $user));
    if ($payload === null) {
        return null;
    }

    $overall    = trim((string)($payload['overall_summary'] ?? ''));
    $entities   = $payload['key_entities'] ?? [];
    $keywords   = $payload['keywords'] ?? [];
    $tags       = $payload['subject_tags'] ?? [];
    $metadata   = $payload['metadata'] ?? [];
    $generated  = $metadata['generated_at'] ?? null;

    $parts = [];
    $parts[] = 'Chat summary (auto-generated).';
    if (!empty($generated)) {
        $parts[] = 'Generated at: ' . $generated;
    }

    if ($overall !== '') {
        $parts[] = "\nOverall summary:\n" . $overall;
    }

    if (is_array($entities) && !empty($entities)) {
        $entityLines = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type    = $entity['type'] ?? 'item';
            $name    = $entity['name'] ?? '';
            $details = $entity['details'] ?? '';
            if ($name === '' && $details === '') {
                continue;
            }
            $line = sprintf('- [%s] %s', $type, $name);
            if ($details !== '') {
                $line .= ': ' . $details;
            }
            $entityLines[] = $line;
        }
        if ($entityLines) {
            $parts[] = "\nKey entities:\n" . implode("\n", $entityLines);
        }
    }

    if (is_array($keywords) && !empty($keywords)) {
        $parts[] = "\nKeywords: " . implode(', ', $keywords);
    }

    if (is_array($tags) && !empty($tags)) {
        $parts[] = "\nSubject tags: " . implode(', ', $tags);
    }

    $content = trim(implode("\n", array_filter($parts)));
    if ($content === '') {
        return null;
    }

    return [
        'role'    => 'system',
        'content' => $content,
    ];
}

/**
 * Process a queued summary job.
 */

function chat_summary_process_pending_jobs(array $config, array $options = []): void
{
    $paths = chat_summary_paths($config);
    if (!chat_summary_ensure_directories($paths)) {
        return;
    }

    $queueDir     = $paths['queue'];
    $completedDir = $paths['completed'];
    $failedDir    = $paths['failed'];

    $jobs = glob($queueDir . '/*.json');
    if (!$jobs) {
        return;
    }

    sort($jobs);

    $maxJobs = isset($options['max_jobs']) ? (int)$options['max_jobs'] : 1;
    $log     = $paths['logs'] . '/summary_inline_' . date('Ymd') . '.log';

    $processed = 0;
    foreach ($jobs as $jobPath) {
        if ($processed >= $maxJobs) {
            break;
        }

        $raw = file_get_contents($jobPath);
        if ($raw === false) {
            @rename($jobPath, $failedDir . '/' . basename($jobPath));
            continue;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            @rename($jobPath, $failedDir . '/' . basename($jobPath));
            continue;
        }

        $result = chat_summary_process_job($payload, $config);

        $logEntry = [
            'timestamp' => date('c'),
            'chat_id'   => $payload['chat_id'] ?? 'unknown',
            'status'    => !empty($result['ok']) ? 'ok' : 'failed',
            'error'     => $result['error'] ?? null,
            'metadata'  => $result['metadata'] ?? null,
        ];

        @file_put_contents(
            $log,
            json_encode($logEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );

        $targetDir = !empty($result['ok']) ? $completedDir : $failedDir;
        @rename($jobPath, $targetDir . '/' . basename($jobPath));

        $processed++;
    }
}

function chat_summary_process_job(array $payload, array $config): array
{
    if (empty($payload['chat_id']) || empty($payload['user'])) {
        return ['ok' => false, 'error' => 'Invalid job payload'];
    }

    $result = chat_summary_generate(
        (string)$payload['chat_id'],
        (string)$payload['user'],
        (string)($payload['deployment'] ?? CHAT_SUMMARY_DEPLOYMENT),
        $config,
        $payload
    );

    if (empty($result['ok'])) {
        return $result;
    }

    $encoded = json_encode($result['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($encoded === false) {
        return ['ok' => false, 'error' => 'Failed to encode summary payload'];
    }

    update_chat_summary($payload['chat_id'], $encoded);

    return [
        'ok'       => true,
        'summary'  => $result['summary'],
        'metadata' => $result['summary']['metadata'] ?? [],
    ];
}

/**
 * Build and execute a summarization prompt for a chat.
 */
function chat_summary_generate(string $chatId, string $user, string $deploymentKey, array $config, array $options = []): array
{
    if (!function_exists('load_configuration')) {
        require_once __DIR__ . '/utils.inc.php';
    }
    if (!function_exists('execute_api_call')) {
        require_once __DIR__ . '/azure-api.inc.php';
    }

    $existingSummary = get_chat_summary($chatId, $user) ?? '';
    $exchanges       = get_all_exchanges($chatId, $user);

    if (empty($exchanges)) {
        return ['ok' => false, 'error' => 'No exchanges available for summary'];
    }

    $maxTokens      = $options['max_tokens'] ?? 4000;
    $collected      = [];
    $runningTokens  = 0;
    $consideredIds  = [];

    $exchangeList = array_values($exchanges);
    $exchangeList = array_reverse($exchangeList);

    foreach ($exchangeList as $exchange) {
        $parts = [];
        $exchangeId = isset($exchange['id']) ? (int)$exchange['id'] : null;
        $prompt = trim((string)($exchange['prompt'] ?? ''));
        $reply  = trim((string)($exchange['reply'] ?? ''));

        if ($prompt !== '') {
            $parts[] = 'User: ' . $prompt;
        }
        if ($reply !== '') {
            $parts[] = 'Assistant: ' . $reply;
        }

        if (!$parts) {
            continue;
        }

        $block       = implode("\n", $parts);
        $blockTokens = estimate_tokens($block);

        if ($exchangeId !== null && $exchangeId > 0) {
            $consideredIds[] = $exchangeId;
        }

        if ($runningTokens > 0 && ($runningTokens + $blockTokens) > $maxTokens) {
            break;
        }

        $runningTokens += $blockTokens;
        $collected[]    = $block;
    }

    if (!$collected) {
        $collected[] = 'User: [content omitted due to size constraints]';
    }

    $collected = array_reverse($collected);

    // Avoid reopening/altering the session once output has begun; summaries run in-process.
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_save_path(sys_get_temp_dir());
        session_start();
    }

    $activeConfig = load_configuration($deploymentKey, true);
    if (!$activeConfig) {
        return ['ok' => false, 'error' => 'Deployment configuration unavailable'];
    }

    $systemPrompt = <<<PROMPT
You are an NIH project assistant that maintains precise summaries of staff chat transcripts.
Produce objective, concise language. Never invent details. Honour medical and scientific terminology.
PROMPT;

    $instructions = <<<PROMPT
Update the summary using the prior summary (which may be empty) and the supplied chat excerpts.
Respond with valid JSON matching this schema:
{
  "overall_summary": string,        // up to 3 short paragraphs, full sentences.
  "key_entities": [
    {"type": "date|person|organization|document|figure|other", "name": string, "details": string}
  ],
  "keywords": [string, ...],        // 5-12 lowercase keywords without punctuation.
  "subject_tags": [string, ...]     // 3-8 broader subject tags (kebab-case or snake_case).
}
If information is unavailable for a field, return an empty array or empty string as appropriate.
Do not include markdown, commentary, or additional keys.
PROMPT;

    $promptSections = [
        "Prior summary:\n" . ($existingSummary !== '' ? $existingSummary : '[none]'),
        "Chat excerpts (latest first within budget):\n" . implode("\n\n---\n\n", $collected),
    ];

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $instructions . "\n\n" . implode("\n\n===\n\n", $promptSections)],
    ];

    $url = rtrim($activeConfig['base_url'], '/')
        . '/openai/deployments/' . $activeConfig['deployment_name']
        . '/chat/completions?api-version=' . $activeConfig['api_version'];

    $payload = [
        'messages'    => $messages,
        'temperature' => 0.2,
    ];

    if (!empty($activeConfig['max_tokens'])) {
        $payload['max_tokens'] = (int)$activeConfig['max_tokens'];
    }

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $activeConfig['api_key'],
    ];

    try {
        $response = execute_api_call($url, $payload, $headers, $chatId);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Azure API call failed: ' . $e->getMessage()];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || empty($decoded['choices'][0]['message']['content'])) {
        return ['ok' => false, 'error' => 'Unexpected response from Azure summary call'];
    }

    $content = trim($decoded['choices'][0]['message']['content']);
    $summaryJson = json_decode($content, true);

    if (!is_array($summaryJson)) {
        return ['ok' => false, 'error' => 'Summary output was not valid JSON', 'raw_content' => $content];
    }

    $metadata = [
        'generated_at'              => date('c'),
        'deployment'                => $deploymentKey,
        'last_exchange_id'          => !empty($consideredIds) ? max($consideredIds) : null,
        'considered_exchange_count' => count($consideredIds),
        'excerpt_tokens'            => $runningTokens,
        'response_tokens'           => estimate_tokens($content),
    ];

    $metadata = array_filter(
        $metadata,
        static function ($value) {
            return $value !== null;
        }
    );

    $summaryJson['metadata'] = $metadata;

    return [
        'ok'      => true,
        'summary' => $summaryJson,
    ];
}
