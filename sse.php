<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/inc/streaming.inc.php';
require_once __DIR__ . '/inc/azure-api.inc.php';

// Capture fatal errors for debugging SSE 500s.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('sse.php shutdown fatal: ' . json_encode($err, JSON_UNESCAPED_UNICODE));
    }
});

if (!defined('HARDCODED_DEPLOYMENT')) {
    define('HARDCODED_DEPLOYMENT', 'azure-gpt4.1-mini');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$debugSse = !empty($_GET['debug_sse']);

$input = file_get_contents('php://input');
$payload = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if ($contentType && stripos($contentType, 'application/json') !== false) {
    $payload = json_decode($input, true);
} else {
    $payload = $_POST;
    if (!$payload && $input) {
        parse_str($input, $payload);
    }
}

if (!is_array($payload)) {
    $payload = [];
}

$sessionUserId = $_SESSION['user_data']['userid'] ?? null;
$sessionAuthorized = $_SESSION['authorized'] ?? null;

if ($debugSse) {
    header('Content-Type: application/json');
    try {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        echo json_encode([
            'debug'        => 'sse_pre_auth',
            'config_file'  => $config_file ?? 'unknown',
            'session_id'   => session_id(),
            'authorized'   => $sessionAuthorized,
            'user'         => $sessionUserId,
            'remote_addr'  => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'request_uri'  => $_SERVER['REQUEST_URI'] ?? '',
            'payload'      => $payload,
            'raw_body'     => $input,
            'headers'      => $headers,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'sse_debug_failed', 'message' => $e->getMessage()]);
    }
    exit;
}

if (empty($sessionUserId) || empty($sessionAuthorized)) {
    $logContext = [
        'uri'          => $_SERVER['REQUEST_URI'] ?? '',
        'config'       => $config_file ?? 'unknown',
        'session_id'   => session_id(),
        'authorized'   => $sessionAuthorized,
        'user'         => $sessionUserId,
        'remote_addr'  => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
    error_log('sse.php unauthorized session: ' . json_encode($logContext, JSON_UNESCAPED_UNICODE));
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Session expired. Please refresh and sign in again.'
    ]);
    exit;
}

$messageInput = $payload['message'] ?? '';
$chatIdInput = $payload['chat_id'] ?? '';
$deployment = $payload['deployment'] ?? ($_SESSION['deployment'] ?? '');
$exchangeType = $payload['exchange_type'] ?? 'chat';
$customConfigRaw = $payload['custom_config'] ?? null;
$ragModeRaw = $payload['rag_mode'] ?? ($_SESSION['rag_mode'] ?? null);
$skipRagRaw = $payload['skip_rag'] ?? $payload['skipRag'] ?? false;
$skipRag = false;
if (is_string($skipRagRaw)) {
    $skipRag = filter_var($skipRagRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $skipRag = ($skipRag === null) ? ($skipRagRaw === '1') : $skipRag;
} else {
    $skipRag = (bool)$skipRagRaw;
}

$customConfig = [];
if (is_array($customConfigRaw)) {
    $customConfig = $customConfigRaw;
} elseif (is_string($customConfigRaw) && $customConfigRaw !== '') {
    $decodedCustom = json_decode($customConfigRaw, true);
    if (is_array($decodedCustom)) {
        $customConfig = $decodedCustom;
    }
}
$customConfig['exchange_type'] = $exchangeType;
if (is_string($ragModeRaw)) {
    $customConfig['rag_mode'] = $ragModeRaw;
}

$decodedMessage = base64_decode($messageInput, true);
if ($decodedMessage === false) {
    $decodedMessage = $messageInput;
}
$userPrompt = trim((string)$decodedMessage);

if ($userPrompt === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Message is required.']);
    exit;
}

$chatTitleService = new ChatTitleService();
$userId = $_SESSION['user_data']['userid'];
$streamId = bin2hex(random_bytes(12));

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
http_response_code(200);

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);
set_time_limit(0);

$sendEvent = function (string $event, array $data = []) {
    echo 'event: ' . $event . "\n";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $json = json_encode(['error' => 'Failed to encode event payload.']);
    }
    echo 'data: ' . $json . "\n\n";
    @ob_flush();
    @flush();
};

stream_register($streamId);

try {
    $deployment = $deployment ?: ($_SESSION['deployment'] ?? '');
    if ($deployment === '') {
        throw new RuntimeException('Deployment is not configured.');
    }

    $chatId = $chatIdInput ? trim((string)$chatIdInput) : '';
    $newChatId = '';

    if ($chatId === '') {
        $needTitle = true;
        $chatId = $newChatId = create_chat($userId, 'New auto-generated Chat', '', $_SESSION['deployment'] ?? $deployment);
    } else {
        $needTitle = (bool)get_new_title_status($userId, $chatId);
    }

    $sendEvent('stream_open', [
        'stream_id'   => $streamId,
        'chat_id'     => $chatId,
        'new_chat_id' => $newChatId,
    ]);

    $streamState = [
        'accumulated'   => '',
        'tool_calls'    => [],
        'aborted'       => false,
        'finish_reason' => null,
        'last_event_at' => microtime(true),
    ];

    $streamHandler = function (string $type, array $payload) use (&$streamState, $streamId, $sendEvent) {
        if ($type === 'token') {
            $delta = (string)($payload['text'] ?? '');
            if (isset($payload['accumulated']) && is_string($payload['accumulated'])) {
                $streamState['accumulated'] = $payload['accumulated'];
            } elseif ($delta !== '') {
                $streamState['accumulated'] .= $delta;
            }

            if ($delta !== '' || $streamState['accumulated'] !== '') {
                $sendEvent('token', [
                    'stream_id'   => $streamId,
                    'delta'       => $delta,
                    'accumulated' => $streamState['accumulated'],
                ]);
            }
        } elseif ($type === 'tool_call') {
            $streamState['tool_calls'][] = $payload;
            $sendEvent('tool_call', [
                'stream_id' => $streamId,
                'tool_call' => $payload,
            ]);
        } elseif ($type === 'heartbeat') {
            $sendEvent('heartbeat', [
                'stream_id' => $streamId,
                'timestamp' => time(),
            ]);
        } elseif ($type === 'finish') {
            if (isset($payload['finish_reason'])) {
                $streamState['finish_reason'] = $payload['finish_reason'];
            }
            $finishPayload = [
                'stream_id'      => $streamId,
                'finish_reason'  => $streamState['finish_reason'],
            ];
            if (isset($payload['origin'])) {
                $finishPayload['origin'] = $payload['origin'];
            }
            $sendEvent('finish', $finishPayload);
        } elseif ($type === 'aborted') {
            $streamState['aborted'] = true;
        }

        $streamState['last_event_at'] = microtime(true);
    };

    $shouldAbort = function () use (&$streamState, $streamId) {
        if (stream_should_stop($streamId)) {
            $streamState['aborted'] = true;
            return true;
        }
        return false;
    };

    $streamOptions = [
        'state'              => &$streamState,
        'on_event'           => $streamHandler,
        'should_abort'       => $shouldAbort,
        'heartbeat_interval' => 10,
    ];

    session_write_close();

    $options = [];
    $options['stream'] =& $streamOptions;

    $response = get_gpt_response($userPrompt, $chatId, $userId, $deployment, $customConfig, $options);

    if (!is_array($response)) {
        throw new RuntimeException('Unexpected response from completion handler.');
    }

    if (!empty($response['error'])) {
        $sendEvent('error', [
            'stream_id' => $streamId,
            'message'   => $response['message'] ?? 'Unknown error',
        ]);
        stream_cleanup($streamId);
        exit;
    }

    $finalPayload = [
        'stream_id'     => $streamId,
        'chat_id'       => $chatId,
        'new_chat_id'   => $newChatId,
        'eid'           => $response['eid'] ?? null,
        'reply'         => $response['message'] ?? '',
        'deployment'    => $response['deployment'] ?? null,
        'finish_reason' => $streamState['finish_reason'] ?? null,
        'stopped'       => (bool)$streamState['aborted'],
        'prompt_documents' => $response['prompt_documents'] ?? [],
        'rag_citations'    => $response['rag_citations'] ?? [],
    ];

    if (empty($finalPayload['rag_citations']) && !empty($finalPayload['eid'])) {
        $fallbackCitations = fetch_rag_citations((int)$finalPayload['eid']);
        if (!empty($fallbackCitations)) {
            $finalPayload['rag_citations'] = $fallbackCitations;
        }
    }
    $sendEvent('final', $finalPayload);

    if ($needTitle && !empty($response['message'])) {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        $autoTitle = $chatTitleService->generate(
            $userPrompt,
            $response['message'],
            HARDCODED_DEPLOYMENT,
            $chatId
        );
        if ($autoTitle !== null) {
            update_chat_title($userId, $chatId, $autoTitle);
        }
    }
} catch (Throwable $e) {
    error_log('sse.php error: ' . $e->getMessage());
    $sendEvent('error', [
        'stream_id' => $streamId,
        'message'   => $e->getMessage(),
    ]);
} finally {
    stream_cleanup($streamId);
}
