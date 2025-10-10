<?php
# inc/azure-api.inc.php 
# AZURE API

/**
 * Retrieves the GPT response by orchestrating the API call and processing the response.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param string $deployment The deployment identifier.
 * @return array The processed API response.
 */
function using_mock_completion_backend(): bool {
    $flag = getenv('PLAYWRIGHT_FAKE_COMPLETIONS');
    if ($flag === false || $flag === '') {
        $flag = getenv('FAKE_CHAT_COMPLETIONS');
    }
    if ($flag === false || $flag === null) {
        return false;
    }

    $flag = strtolower(trim((string)$flag));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Returns a document snippet that fits within the provided token budget.
 *
 * @param string   $content          The document body.
 * @param int      $tokenBudget      Maximum tokens allowed for the snippet.
 * @param int|null $knownTokenLength Optional precomputed token length for the full content.
 *
 * @return array{text:string,tokens:int,truncated:bool}
 */
function truncate_document_for_budget(string $content, int $tokenBudget, ?int $knownTokenLength = null): array {
    $tokenBudget = max(0, (int)$tokenBudget);
    $totalTokens = $knownTokenLength ?? estimate_tokens($content);

    if ($tokenBudget <= 0) {
        return ['text' => '', 'tokens' => 0, 'truncated' => $totalTokens > 0];
    }
    if ($totalTokens <= $tokenBudget) {
        return ['text' => $content, 'tokens' => $totalTokens, 'truncated' => false];
    }

    $length = mb_strlen($content, 'UTF-8');
    if ($length === 0) {
        return ['text' => '', 'tokens' => 0, 'truncated' => false];
    }

    $low = 0;
    $high = $length;
    $best = '';
    $bestTokens = 0;

    while ($low <= $high) {
        $mid = (int)floor(($low + $high) / 2);
        if ($mid <= 0) {
            $candidate = '';
            $tokens = 0;
        } else {
            $candidate = mb_substr($content, 0, $mid, 'UTF-8');
            $tokens = estimate_tokens($candidate);
        }

        if ($tokens <= $tokenBudget) {
            $best = $candidate;
            $bestTokens = $tokens;
            $low = $mid + 1;
        } else {
            $high = $mid - 1;
        }
    }

    if ($best === '') {
        $best = mb_substr($content, 0, min(160, $length), 'UTF-8');
        $bestTokens = estimate_tokens($best);
    }

    $best = rtrim($best);
    $note = "\n\n[Content truncated to fit available context.]";
    $noteTokens = estimate_tokens($note);
    if ($best !== '' && ($bestTokens + $noteTokens) <= $tokenBudget) {
        $best .= $note;
        $bestTokens += $noteTokens;
    }

    return [
        'text'      => $best,
        'tokens'    => min($bestTokens, $tokenBudget),
        'truncated' => true,
    ];
}

function get_gpt_response($message, $chat_id, $user, $deployment, $custom_config) {
    global $config, $config_file;
    file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 1 - " . print_r($message, true));

   $active_config = load_configuration($deployment);
    if (!$active_config) {
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => 'Invalid deployment configuration.'
        ];
    }

    if (using_mock_completion_backend()) {
        $uiDeployment = ui_deployment_key($active_config);
        $workflowId   = $custom_config['workflowId'] ?? null;
        if (function_exists('mb_substr')) {
            $preview = trim(mb_substr($message, 0, 160));
        } else {
            $preview = trim(substr($message, 0, 160));
        }
        $replyText    = "Automated test reply\n\nPrompt preview: {$preview}";

        $eid = create_exchange(
            $uiDeployment,
            $chat_id,
            $message,
            $replyText,
            $workflowId,
            null
        );

        return [
            'eid'        => $eid,
            'deployment' => $uiDeployment,
            'error'      => false,
            'message'    => $replyText,
        ];
    }

    $isAssistant = ($active_config['host'] === 'assistant');

    #print("this is message: ".print_r($message,1)); die();
    #print("this is custom stuff: ".print_r($custom_config,1)); die();
    if ($custom_config['exchange_type'] == 'chat') {
        $msg = get_chat_thread($message, $chat_id, $user, $active_config);
    
    } elseif ($custom_config['exchange_type'] == 'workflow') {
        $active_config = load_configuration($config['azure']['workflow_default']);
        $arr = get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config);
        $active_config = $arr[0];
        $msg = $arr[1];
        #die("THIS IS THE WORKFLOW OUTPUT - + +++++++ + " . print_r($arr,1)." ---- \n");
        
    } else {
        return process_api_response('There was an error processing your post. Please contact support.', $active_config, $chat_id, $message, $msg);
   
    }

    if ($active_config['host'] == "Mocha") {
        $response = call_mocha_api($active_config['base_url'], $msg);
    } else {
        if ($isAssistant) $response = call_assistant_api($active_config, $chat_id, $msg);
        else $response = call_azure_api($active_config, $chat_id, $msg);
    }
    #echo "<pre>Step get_gpt_response()\n".print_r($response,1)."</pre>"; die();

    return process_api_response($response, $active_config, $chat_id, $message, $msg, $custom_config);
}


/**
 * Constructs the chat thread based on the active configuration.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @return array The messages array to be sent to the API.
 */
function get_chat_thread($message, $chat_id, $user, $active_config) {

    file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 2 - " . print_r($message, true), FILE_APPEND);

    if ($active_config['host'] === 'dall-e' || $active_config['host'] === 'gpt-image-1') {
        return array('prompt'=>$message); // Handle dall-E Image Generation Requests
        
    } else {
        // Handle Chat Completion Requests
        return handle_chat_request($message, $chat_id, $user, $active_config);
    }
}

/**
 * Calls the Azure OpenAI API.
 *
 * @param array $active_config The active deployment configuration.
 * @param mixed $msg The message payload.
 * @return string The API response.
 */
function call_azure_api($active_config, $chat_id, $msg) {
    // 1) pull your documents (with token lengths) back out of the DB
    $docs = get_chat_documents($_SESSION['user_data']['userid'], $chat_id);

    // 2) total up all document_token_length
    $doc_tokens = 0; #array_sum(array_column($docs, 'document_token_length'));

    $is_dalle = ($active_config['host'] === 'dall-e' || $active_config['host'] === 'gpt-image-1');

    if ($is_dalle) {
        // dall-E Image Generation Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/images/generations?api-version=" . $active_config['api_version'];
        $payload = [
            'model'  => $active_config['model'] ?? 'dall-e',
            'prompt' => $msg['prompt'],
            'n'      => 1,
            'size'   => '1024x1024'
        ];

    } else {
        // Chat Completion Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/chat/completions?api-version=" . $active_config['api_version'];

        // Detect GPT-5 family (gpt-5, gpt-5-mini, gpt-5-nano) in the selected deployment/model
        $deployment_str = ($_SESSION['deployment'] ?? '') . ' ' . ($active_config['deployment_name'] ?? '') . ' ' . ($active_config['model'] ?? '');
        $is_gpt5 = (bool)preg_match('/\bgpt-5\b/i', $deployment_str);

        // Common base payload
        $payload = [
            'messages' => $msg
        ];

        if ($is_gpt5) {
            // === GPT-5 reasoning models ===
            // Do NOT send temperature/top_p/penalties/max_tokens for reasoning models. (Azure docs)
            // Add GPT-5 specific knobs:
            $payload['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'low';  // minimal|low|medium|high
            $payload['verbosity']        = $_SESSION['verbosity']        ?? 'low';  // low|medium|high

            #$payload['reasoning_effort'] = $_SESSION['reasoning_effort'] = 'high';  // minimal|low|medium|high
            #$payload['verbosity']        = $_SESSION['verbosity']        = 'high';  // low|medium|high

            if (!empty($active_config['max_completion_tokens'])) {
                $payload['max_completion_tokens'] = min(
                    (int)$active_config['max_completion_tokens'],
                    max(1, (int)$active_config['context_limit'] - (int)$doc_tokens)
                );
            }

        } else {
            // === Non-reasoning models === (your existing behavior)
            $top_p = (preg_match('/o1|o3|o4|5/', $_SESSION['deployment'] ?? '')) ? 1 : 0.95;

            $payload += [
                "temperature"       => (float)($_SESSION['temperature'] ?? 1),
                "frequency_penalty" => 0,
                "presence_penalty"  => 0,
                "top_p"             => $top_p
            ];

            if (!empty($active_config['max_tokens'])) {
                $payload['max_tokens'] = (int)$active_config['max_tokens'];
            }
            if (!empty($active_config['max_completion_tokens'])) {
                $payload['max_completion_tokens'] = min(
                    (int)$active_config['max_completion_tokens'],
                    max(1, (int)$active_config['context_limit'] - (int)$doc_tokens)
                );
                // preserve your previous behavior of bumping temp when using max_completion_tokens
                $payload['temperature'] = 1;
            }
        }
    }

    #die("2. Final payload: ".print_r($payload,1));

    $headers = [
        'Content-Type: application/json',
        'api-key: ' . $active_config['api_key']
    ];
    $response = execute_api_call($url, $payload, $headers, $chat_id);
    return $response;
}

/**
 * Unified handler for Assistant, GPT-IMAGE-1 (base-64) and chat completions.
 *
 * @param string $response      Raw JSON returned by Azure/OpenAI
 * @param array  $active_config Deployment configuration that was used
 * @param int    $chat_id
 * @param string $user_prompt   The user-supplied prompt
 * @param array  $msg_ctx       Messages that were sent to the API
 * @param array  $custom_config Client-side extras (workflow etc.)
 *
 * @return array Canonical structure consumed by your front-end
 */
function process_api_response(
    $response,
    $active_config,
    $chat_id,
    $user_prompt,
    $msg_ctx,
    $custom_config
) {
    /* --------------------------------------------------------------
       Common pre-amble
    -------------------------------------------------------------- */
    $wfId         = $custom_config['workflowId'] ?? '';
    $uiDeployment = ui_deployment_key($active_config);
    $host         = $active_config['host'] ?? '';

    /* small helper: robust URL fetch (uses cURL if available) */
    if (!function_exists('http_fetch_bytes')) {
        function http_fetch_bytes($url, $timeout = 30) {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $timeout,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_HTTPHEADER     => ['Accept: */*'],
                ]);
                $data = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                if ($data === false || $code >= 400) {
                    error_log("http_fetch_bytes failed code=$code err=$err url=$url");
                    return false;
                }
                return $data;
            }
            $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
            return @file_get_contents($url, false, $ctx);
        }
    }

    /* ========================= 1) ASSISTANT ========================= */
    if ($host === 'assistant') {
        // keep your existing assistant handling with file annotations
        $assistantMsg = $response; // already an assistant message object (array)
        $answer_text  = '';
        $links        = [];
        $seen         = [];

        foreach ($assistantMsg['content'] as $part) {
            if ($part['type'] === 'text') {
                $answer_text .= $part['text']['value'];
                foreach ($part['text']['annotations'] as $ann) {
                    if ($ann['type'] === 'file_path') {
                        $fid = $ann['file_path']['file_id'];
                        if (!isset($seen[$fid])) {
                            $links[]    = fetch_and_save_file($chat_id, $fid, $active_config, $ann['text'] ?? '');
                            $seen[$fid] = true;
                        }
                    }
                }
            } elseif ($part['type'] === 'file_path') {
                $fid = $part['file_path']['file_id'];
                if (!isset($seen[$fid])) {
                    $links[]    = fetch_and_save_file($chat_id, $fid, $active_config);
                    $seen[$fid] = true;
                }
            }
        }

        // strip sandbox links and append our links
        $answer_text = preg_replace('/\[[^\]]+\]\(sandbox:[^)]+\)/i', '', $answer_text);
        if ($links) {
            $answer_text .= "\n\n---\n**Download:**\n";
            foreach ($links as $l) {
                $label = $l['display_name'] ?? $l['filename'];
                $answer_text .= "- [{$label}]({$l['url']})\n";
            }
        }

        $eid = create_exchange($uiDeployment, $chat_id, $user_prompt, $answer_text, $wfId, null, json_encode($links));

        return [
            'eid'        => $eid,
            'deployment' => $uiDeployment,
            'error'      => false,
            'message'    => $answer_text,
            'links'      => $links
        ];
    }

    /* ================== 2) GPT-IMAGE-1 / DALL·E ==================== */
    if ($host === 'gpt-image-1' || $host === 'dall-e') {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Invalid JSON from image API'
            ];
        }

        // support both shapes: base64 (gpt-image-1 if response_format=b64_json) or URL
        $b64 = null;
        $url = null;
        if (!empty($data['data']) && is_array($data['data'])) {
            foreach ($data['data'] as $item) {
                if (isset($item['b64_json'])) { $b64 = $item['b64_json']; break; }
                if (isset($item['url']))      { $url = $item['url'];      break; }
            }
        }

        // load bytes
        $bin = null;
        if ($b64 !== null) {
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                return [
                    'deployment' => $uiDeployment,
                    'error'      => true,
                    'message'    => 'Failed to base64-decode image data.'
                ];
            }
        } elseif ($url !== null) {
            $bin = http_fetch_bytes($url);
            if ($bin === false) {
                return [
                    'deployment' => $uiDeployment,
                    'error'      => true,
                    'message'    => 'Failed to fetch image URL from image API.'
                ];
            }
        } else {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Image API response missing b64_json/url.'
            ];
        }

        // figure out where to write (keep your test path; fallback for dev layout)
        $projectRoot = dirname(__DIR__); // matches your test working code
        $image_dir   = $projectRoot . '/image_gen';
        if (!is_dir($image_dir)) {
            // fallback one level up if needed
            $alt = dirname($projectRoot) . '/image_gen';
            if (is_dir($alt) || @mkdir($alt, 0755, true)) { $image_dir = $alt; }
        }

        $fullsize_dir = $image_dir . '/fullsize';
        $small_dir    = $image_dir . '/small';
        if (!is_dir($fullsize_dir)) @mkdir($fullsize_dir, 0755, true);
        if (!is_dir($small_dir))    @mkdir($small_dir,   0755, true);

        // choose extension; gpt-image-1 typically returns PNG
        $ext       = 'png';
        $unique    = uniqid();
        $imageName = "{$chat_id}-{$unique}.{$ext}";
        $full      = "$fullsize_dir/$imageName";
        $small     = "$small_dir/$imageName";

        if (@file_put_contents($full, $bin) === false) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Cannot write full-size image to disk.'
            ];
        }

        // thumbnail (best-effort)
        if (!scale_image_from_path($full, $small, 0.5)) {
            error_log("Thumbnail generation failed for $full");
        }

        $eid = create_exchange(
            $uiDeployment,
            $chat_id,
            $user_prompt,
            '',          // no text body for image gen
            $wfId,
            $imageName
        );

        /* Return canonical success object ----------------------------------- */
        return [
            'eid'            => $eid,
            'deployment'     => $uiDeployment,
            'error'          => false,
            'message'        => 'Image generated successfully.',
            'image_gen_name' => $imageName
        ];
    }

    /* ========================= 3) COMPLETIONS ======================= */
    $data = json_decode($response, true);

    // 3a) ERROR branch if JSON invalid or API reported an error
    if (!is_array($data)) {
        return [
            'deployment' => $uiDeployment,
            'error'      => true,
            'message'    => 'Invalid JSON from completions API'
        ];
    }
    if (isset($data['error'])) {
        log_error_details($msg_ctx, $user_prompt, $data['error']['message'] ?? 'Unknown error');
        return [
            'deployment' => $uiDeployment,
            'error'      => true,
            'message'    => $data['error']['message'] ?? 'Unknown error'
        ];
    }

    // 3b) Normal success
    $answer_text = $data['choices'][0]['message']['content'] ?? 'No response text found.';
    $eid = create_exchange($uiDeployment, $chat_id, $user_prompt, $answer_text, $wfId);

    return [
        'eid'        => $eid,
        'deployment' => $uiDeployment,
        'error'      => false,
        'message'    => $answer_text
    ];
}

/**
 * Constructs the message array for Chat Completion.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @return array The messages array for Chat Completion.
 */
function handle_chat_request($message, $chat_id, $user, $active_config) {
    global $config_file;
    file_put_contents(dirname(__DIR__).'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 5 - " . print_r($message, true), FILE_APPEND);

    // 1) build system message
    $system_message = build_system_message($active_config);

    // 2) gather document inventory
    $docs = get_chat_documents($user, $chat_id);

    // 3) decide whether to inject full documents or rely on RAG
    $document_messages = [];
    $initialDocBudget = max(0, (int)$active_config['context_limit'] - estimate_tokens($message) - 1024); // leave buffer
    $docTokenBudget = $initialDocBudget;
    $docsNeedingRag = [];
    $decisions = [];

    $inlineCandidates = [];
    $remainingDocs = [];
    foreach ($docs as $doc) {
        $isImage = isset($doc['document_type']) && strpos($doc['document_type'], 'image/') === 0;
        $fullAvailable = !empty($doc['full_text_available']) && !$isImage && !empty($doc['document_content']);
        if ($fullAvailable) {
            $inlineCandidates[] = $doc;
        } else {
            $remainingDocs[] = $doc;
        }
    }

    usort($inlineCandidates, function ($a, $b) {
        $aTokens = (int)($a['document_token_length'] ?? 0);
        $bTokens = (int)($b['document_token_length'] ?? 0);
        if ($aTokens === $bTokens) {
            return ((int)($a['document_id'] ?? 0)) <=> ((int)($b['document_id'] ?? 0));
        }
        return $aTokens <=> $bTokens;
    });

    $orderedDocs = array_merge($inlineCandidates, $remainingDocs);

    foreach ($orderedDocs as $doc) {
        $docId = (int)($doc['document_id'] ?? 0);
        if ($docId <= 0) {
            continue;
        }

        $docName   = (string)($doc['document_name'] ?? ('Document ' . $docId));
        $docType   = (string)($doc['document_type'] ?? '');
        $docReady  = !empty($doc['document_ready']);
        $docContent = (string)($doc['document_content'] ?? '');
        $docTokens  = (int)($doc['document_token_length'] ?? 0);

        $fullAvailable = !empty($doc['full_text_available']) && strpos($docType, 'image/') !== 0 && $docContent !== '';
        if ($fullAvailable && $docTokens <= 0 && $docContent !== '') {
            $docTokens = estimate_tokens($docContent);
        }

        $decision = [
            'id'                    => $docId,
            'name'                  => $docName,
            'type'                  => $docType,
            'full_text_available'   => (bool)$fullAvailable,
            'document_ready'        => (bool)$docReady,
            'document_token_length' => $docTokens,
            'mode'                  => 'pending',
        ];

        if ($fullAvailable) {
            $header = "Document: {$docName}";
            $headerTokens = estimate_tokens($header . "\n");

            if ($docTokenBudget >= ($headerTokens + $docTokens)) {
                $content = $header . "\n" . $docContent;
                $tokensSpent = $headerTokens + $docTokens;
                $document_messages[] = ['role' => 'system', 'content' => $content];
                $docTokenBudget = max(0, $docTokenBudget - $tokensSpent);

                $decision['mode'] = 'inline';
                $decision['tokens_used'] = $tokensSpent;
                $decision['remaining_budget'] = $docTokenBudget;
                $decisions[] = $decision;
                continue;
            }

            $availableForBody = max(0, $docTokenBudget - $headerTokens);
            if ($availableForBody > 0 && !$docReady) {
                $clip = truncate_document_for_budget($docContent, $availableForBody, $docTokens);
                if ($clip['text'] !== '') {
                    $content = $header . "\n" . $clip['text'];
                    $tokensSpent = $headerTokens + $clip['tokens'];
                    $document_messages[] = ['role' => 'system', 'content' => $content];
                    $docTokenBudget = max(0, $docTokenBudget - $tokensSpent);

                    $decision['mode'] = 'inline_truncated';
                    $decision['tokens_used'] = $tokensSpent;
                    $decision['remaining_budget'] = $docTokenBudget;
                    $decision['truncated'] = true;
                    $decisions[] = $decision;
                    continue;
                }
            }

            if ($docReady) {
                $docsNeedingRag[] = $docId;
                $decision['mode'] = 'rag';
                $decision['reason'] = 'token_budget';
            } else {
                $decision['mode'] = 'pending_index';
                $decision['reason'] = ($availableForBody <= 0) ? 'no_budget' : 'awaiting_index';
            }

            $decisions[] = $decision;
            continue;
        }

        if ($docReady) {
            $docsNeedingRag[] = $docId;
            $decision['mode'] = 'rag';
            $decision['reason'] = 'no_fulltext';
        } else {
            $decision['mode'] = 'pending_index';
            $decision['reason'] = 'awaiting_index';
        }

        $decisions[] = $decision;
    }

    $docsNeedingRag = array_values(array_unique(array_filter($docsNeedingRag)));

    $_SESSION['last_document_strategy'] = [
        'initial_budget'  => $initialDocBudget,
        'remaining_budget'=> $docTokenBudget,
        'documents'       => $decisions,
        'rag_document_ids'=> $docsNeedingRag,
    ];

    $shouldRunRag = !empty($docsNeedingRag);
    if ($shouldRunRag) {
        $userForIndex = $_SESSION['user_data']['userid'] ?? $user;
        $ragResponse = run_rag($message, $chat_id, $userForIndex, $config_file, 20, $docsNeedingRag);

        error_log("RAG CMD: {$ragResponse['cmd']}");
        error_log("RAG RC: {$ragResponse['rc']}");
        error_log("RAG STDOUT (first 800): " . substr($ragResponse['stdout'], 0, 800));
        error_log("RAG STDERR (first 800): " . substr($ragResponse['stderr'] ?? '', 0, 800));

        if ($ragResponse['rc'] === 0 && is_array($ragResponse['json']) && !empty($ragResponse['json']['ok']) && !empty($ragResponse['json']['augmented_prompt'])) {
            $message = $ragResponse['json']['augmented_prompt'];
            $_SESSION['last_rag_citations'] = $ragResponse['json']['citations'] ?? [];
            $_SESSION['last_rag_meta'] = [
                'top_k'           => $ragResponse['payload']['top_k'] ?? null,
                'latency_ms'      => $ragResponse['json']['latency_ms'] ?? null,
                'embedding_model' => $ragResponse['json']['embedding_model_used'] ?? ($ragResponse['json']['embedding_model'] ?? null),
            ];
        } else {
            error_log("RAG retrieve failed; falling back to raw message");
            unset($_SESSION['last_rag_citations'], $_SESSION['last_rag_meta']);
        }
    } else {
        unset($_SESSION['last_rag_citations'], $_SESSION['last_rag_meta']);
    }

    $tokensUsedByDocs = max(0, $initialDocBudget - $docTokenBudget);

    // 4) pull chat history, *reserving* doc_tokens still available
    $context_messages = retrieve_context_messages(
        $chat_id,
        $user,
        $active_config,
        $message,
        $reserved_tokens = $tokensUsedByDocs
    );

    // 5) stitch everything together
    $messages = array_merge(
        $system_message,
        $document_messages,
        $context_messages,
        [
            ["role" => "user", "content" => $message]
        ]
    );
    return $messages;
}

/**
 * Executes an API call using cURL.
 *
 * @param string $url The API endpoint URL.
 * @param array $payload The JSON payload to send.
 * @param array $headers The HTTP headers.
 * @return string The API response.
 */
function execute_api_call($url, $payload, $headers, $chat_id = '') {
    
    $_SESSION['api_endpoint'] = $url;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    // Uncomment for debugging
    #print($response); die();

    if (curl_errno($ch)) {
        error_log('Curl error: ' . curl_error($ch));
        print('Curl error: ' . curl_error($ch));
        die("DEAD BECAUSE OF AN ERROR");
    }

    curl_close($ch);
    // Uncomment for debugging
    // print("GOT TO THE GET_EXECUTE API CALL FUNCTION");
    return $response;
}

/**
 * Retrieves and formats recent chat messages within the context limit.
 *
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param array $active_config The active deployment configuration.
 * @param string $message The current user message.
 * @return array The formatted context messages.
 */
function retrieve_context_messages(
    $chat_id,
    $user,
    $active_config,
    $message,
    $reserved_tokens = 0           // ← new parameter
) {
    $context_limit   = (int)$active_config['context_limit'];
    $token_budget    = $context_limit - $reserved_tokens;
    $recent_messages = get_recent_messages($chat_id, $user);
    $total_tokens    = estimate_tokens($message);
    $formatted       = [];

    foreach (array_reverse($recent_messages) as $msg) {
        if (stristr($msg['deployment'],'dall-e')) {
            $msg['reply_token_length'] = 0;
        }
        $needed = $msg['prompt_token_length']
                + $msg['reply_token_length']
                + 8; // 4 tokens × 2 messages

        if ($total_tokens + $needed > $token_budget) {
            break;
        }
        $formatted[] = ['role' => 'user',      'content' => $msg['prompt']];
        $formatted[] = ['role' => 'assistant', 'content' => $msg['reply']];
        $total_tokens += $needed;
    }

    return array_reverse($formatted);
}
