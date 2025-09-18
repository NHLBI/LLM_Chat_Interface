<?php

# ASSISTANTS

/* =========================================================== */
/*  call_assistant_api – production version (no debug dies)    */
/* =========================================================== */
function call_assistant_api(array $cfg, string $chat_id, array $messages)
{
    file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 3 - " . print_r($messages, true), FILE_APPEND);
    if (empty($cfg['assistant_id'])) {
        throw new RuntimeException('assistant_id missing from config');
    }

    $thread_id = ensure_thread_bootstrapped($cfg, $chat_id);

    /* add *all* messages of this turn (system/doc/user) */
    foreach ($messages as $m) {
        file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 4 - " . print_r($m, true), FILE_APPEND);
        if (!isset($m['content']) || trim($m['content']) === '') {
            continue;
        }

        rest_json('POST',
                  "/openai/threads/$thread_id/messages",
                  [ 'role'    => $m['role'],
                    'content' => $m['content'] ],
                  $cfg);
    }

    /* create run */
    $run = rest_json('POST', "/openai/threads/$thread_id/runs",
         ['assistant_id'=>$cfg['assistant_id']], $cfg);

    /* poll */
    while (true) {
        usleep(500_000);
        $run = rest_json('GET',
            "/openai/threads/$thread_id/runs/{$run['id']}",
            null, $cfg);

        if ($run['status'] === 'completed') break;

        if ($run['status'] === 'requires_action'
            && ($run['required_action']['type'] ?? '') === 'submit_tool_outputs') {

            $tool_outputs = [];
            foreach ($run['required_action']['tool_calls'] as $tc) {
                $tool_outputs[] = [
                    'tool_call_id' => $tc['id'],
                    'output'       => ''          // Code-Interpreter will run
                ];
            }
            rest_json('POST',
                 "/openai/threads/$thread_id/runs/{$run['id']}/submit_tool_outputs",
                 ['tool_outputs'=>$tool_outputs], $cfg);
        } elseif (!in_array($run['status'], ['queued','in_progress'])) {
            throw new RuntimeException("Run ended with status {$run['status']}");
        }
    }

    /* return newest assistant message */
    $msgs = rest_json('GET',
        "/openai/threads/$thread_id/messages?order=desc&limit=1",
        null, $cfg);

    return $msgs['data'][0];
}

function ensure_thread_bootstrapped($cfg, $chat_id) {

    // 1. Do we already have an Azure thread for this chat?
    $thread_id = get_thread_for_chat($chat_id);
    if ($thread_id) return $thread_id;

    // 2. Create a new Azure thread
    $thr = rest_json('POST', '/openai/threads', [], $cfg);
    $thread_id = $thr['id'];

    // 3. Re-play existing messages so the model has context
    $msgs = get_recent_messages($chat_id,
                                $_SESSION['user_data']['userid']);
    foreach ($msgs as $m) {
        /* user prompt */
        rest_json('POST', "/openai/threads/$thread_id/messages", [
            'role'    => 'user',
            'content' => $m['prompt']
        ], $cfg);

        /* assistant reply (skip blank ones from DALL-E turns) */
        if (!empty($m['reply'])) {
            rest_json('POST', "/openai/threads/$thread_id/messages", [
                'role'    => 'assistant',
                'content' => $m['reply']
            ], $cfg);
        }
    }

    // 4. Persist mapping
    save_thread_for_chat($chat_id, $thread_id);
    return $thread_id;
}

/**
 * rest_json() – for all normal Azure OpenAI endpoints that return JSON
 * rest_raw()  – for /files/{id}/content which returns bytes
 */
function rest_json(string $method, string $path, ?array $body, array $cfg): array
{
    $resp = _rest_core($method, $path, $body, $cfg, $status, $ctype);

    // Expect JSON; if not, throw.
    if ($ctype !== 'application/json' && !str_starts_with($ctype, 'application/json')) {
        throw new RuntimeException("Expected JSON, got $ctype from $path");
    }
    return json_decode($resp, true);
}

function rest_raw(string $method, string $path, ?array $body, array $cfg): string
{
    return _rest_core($method, $path, $body, $cfg, $status, $ctype);
}

/* ---- shared curl core --------------------------------------- */
function _rest_core(string $method, string $path, ?array $body, array $cfg,
                    &$status = 0, &$ctype = ''): string
{
    global $chat_id;
    $url  = rtrim($cfg['base_url'], '/').$path;
    $url .= (str_contains($url, '?') ? '&' : '?')
          . 'api-version='.$cfg['api_version'];

    $hdrs = ['api-key: '.$cfg['api_key']];
    if (strtoupper($method) !== 'GET') {
        $hdrs[] = 'Content-Type: application/json';
        $payload = json_encode($body ?? new stdClass());
    } else {
        $payload = null;
    }

    #print("3. The Final payload: ".print_r($payload,1));
    file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 7 - " . print_r($payload, true), FILE_APPEND);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $hdrs,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype  = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '';

    if ($status >= 400) {
        // try to surface the real error
        $detail = '';
        if ($ctype && str_starts_with($ctype, 'application/json')) {
            $j = json_decode($resp, true);
            $detail = $j['error']['code'] . ': ' . $j['error']['message'] ?? '';
        } else {
            $detail = substr($resp, 0, 300);          // plain‐text fallback
        }
        throw new RuntimeException("Chat id: {$chat_id} - Azure REST $method $path HTTP $status – $detail");
    }

    if (curl_errno($ch))  throw new RuntimeException('cURL error: '.curl_error($ch));
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Azure REST $method $path HTTP $status");
    }
    return $resp;
}
