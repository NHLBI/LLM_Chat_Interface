<?php

require_once __DIR__ . '/session_init.php';
require_once 'get_config.php'; // Determine the environment dynamically
#echo '<pre>'.print_r($config,1).'</pre>';

// lib.required.php
require_once 'db.php';

$pdo = get_connection();

define('DOC_GEN_DIR',dirname(__DIR__) . '/doc_gen');

// Before proceeding, check if the session has the required user data.
// If not, let’s give it a chance to appear.
if (!waitForUserSession()) {
    // If after waiting the session is still missing the user id, load the splash page.
    require_once 'splash.php';
    exit;
}

// Handle the splash screen
if (empty($_SESSION['splash'])) $_SESSION['splash'] = '';

if ((!empty($_SESSION['user_data']['userid']) && $_SESSION['authorized'] !== true) || empty($_SESSION['splash'])) {
    require_once 'splash.php';
    exit;
}

// Start the PHP session to enable session variables
$sessionTimeout = $config['session']['timeout'];  // Load session timeout from config

if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $sessionTimeout)) {
    // Last request was more than timeout ago
    logout();
}
$_SESSION['LAST_ACTIVITY'] = time(); // Update last activity time stamp

// Initialize user data if not set
if (empty($_SESSION['user_data'])) $_SESSION['user_data'] = [];

$user = (empty($_SESSION['user_data']['userid'])) ? '' : $_SESSION['user_data']['userid'];

$application_path = $config['app']['application_path'];

// Confirm authentication, redirect if false
if (isAuthenticated()) {
    if (empty($_SESSION['LAST_REGEN']) || (time() - $_SESSION['LAST_REGEN'] > 900)) {
        session_regenerate_id(true);
        $_SESSION['LAST_REGEN'] = time();
    }

} else {
    header('Location: auth_redirect.php');
    exit;
}

// Verify that there is a chat with this id for this user
// If a 'chat_id' parameter was passed, store its value as a string in the session variable 'chat_id'
$chat_id = filter_input(INPUT_GET, 'chat_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!verify_user_chat($user, $chat_id)) {
    echo " -- " . htmlspecialchars($user) . "<br>\n";
    die("Error: there is no chat record for the specified user and chat id. If you need assistance, please contact " . htmlspecialchars($config['app']['emailhelp']));
}

// Parse models from configuration
$models_str = $config['azure']['deployments'];
$models_a = explode(",", $models_str);

$models = array();
foreach ($models_a as $m) {
    $a = explode(":", $m);
    $models[$a[0]] = array('label' => $a[1]) + $config[$a[0]];
}
#echo "<pre>".print_r($models,1)."</pre>";die();

// Define temperature options
$temperatures = [];
$i = 0;
while ($i < 2.1) {
    $temperatures[] = round($i, 1);
    $i += 0.1;
}

// Initialize chat_id if not set
if (empty($_GET['chat_id'])) $_GET['chat_id'] = '';

// Handle model selection
if (isset($_POST['model']) && array_key_exists($_POST['model'], $models)) {
    #print_r($_POST);
    $deployment = $_SESSION['deployment'] = $_POST['model'];
    if (!empty($_GET['chat_id'])) update_deployment($user, $chat_id, $deployment);
}

// Retrieve all chats for the user
$all_chats = get_all_chats($user);
if (!empty($chat_id) && !empty($all_chats[$chat_id])) {
    #echo "2 This is the deployment: {$deployment}<br>\n";
    $deployment = $_SESSION['deployment'] = $all_chats[$chat_id]['deployment'];  // Currently active chat
    #echo "3 This is the deployment: {$deployment}<br>\n";
    $_SESSION['temperature'] = (empty($all_chats[$chat_id]['temperature'])) ? '0.7' : $all_chats[$chat_id]['temperature'];
}

// Set default deployment if not already set
if (empty($_SESSION['deployment'])) {
    $deployment = $_SESSION['deployment'] = $config['azure']['default'];
    #echo "4 This is the deployment: {$deployment}<br>\n";
} else {
    $deployment = $_SESSION['deployment'];
    #echo "5 This is the deployment: {$deployment}<br>\n";
}

$context_limit = $config[$deployment]['context_limit'];

#echo "1 This is the deployment: {$deployment}<br>\n";
#echo '<pre>'.print_r($config[$deployment],1).'</pre>';

// Handle temperature selection
if (isset($_POST['temperature'])) {
    $_SESSION['temperature'] = (float)$_POST['temperature'];
    $temperature = $_SESSION['temperature'];
    if (!empty($_GET['chat_id'])) update_temperature($user, $chat_id, $temperature);
}

// Set default temperature if not set or out of bounds
if (!isset($_SESSION['temperature']) || (float)$_SESSION['temperature'] < 0 || (float)$_SESSION['temperature'] > 2) {
    $_SESSION['temperature'] = 0.7;
}

// Uncomment for debugging
// echo "<pre>". print_r($_SESSION,1) ."</pre>";
// echo "<pre>". print_r($_SERVER,1) ."</pre>";

/**
 * Retrieves the GPT response by orchestrating the API call and processing the response.
 *
 * @param string $message The current user message.
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @param string $deployment The deployment identifier.
 * @return array The processed API response.
 */
function get_gpt_response($message, $chat_id, $user, $deployment, $custom_config) {
    global $config, $config_file;
    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 1 - " . print_r($message, true));

   $active_config = load_configuration($deployment);
    if (!$active_config) {
        return [
            'deployment' => $deployment,
            'error' => true,
            'message' => 'Invalid deployment configuration.'
        ];
    }







    // BEFORE building $msg / get_chat_thread:
    $rag_enabled = true;
    if ($rag_enabled) {
        $userForIndex = $_SESSION['user_data']['userid'] ?? '';
        $r = run_rag($message, $chat_id, $userForIndex, $config_file, 20);

        // Always log the outcome so “spinners” never hide problems
        error_log("RAG CMD: {$r['cmd']}");
        error_log("RAG RC: {$r['rc']}");
        error_log("RAG STDOUT (first 800): ".substr($r['stdout'], 0, 800));
        error_log("RAG STDERR (first 800): ".substr($r['stderr'] ?? '', 0, 800));

        if ($r['rc'] === 0 && is_array($r['json']) && !empty($r['json']['ok']) && !empty($r['json']['augmented_prompt'])) {
            $message = $r['json']['augmented_prompt'];
            $_SESSION['last_rag_citations'] = $r['json']['citations'] ?? [];
        } else {
            // Soft-fail: keep original $message and carry on
            error_log("RAG retrieve failed; falling back to raw message");
        }
    }










    $isAssistant = ($active_config['host'] === 'assistant');

    #print("this is message: ".print_r($message,1)); die();
    #print("this is custom stuff: ".print_r($custom_config,1)); die();
    if ($custom_config['exchange_type'] == 'chat') {
        $msg = get_chat_thread($message, $chat_id, $user, $active_config);
    
    } elseif ($custom_config['exchange_type'] == 'workflow') {
        $active_config = load_configuration($config['azure']['workflow_default']);
        $msg = get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config);
        
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
 * Loads the configuration based on the deployment name.
 *
 * @param string $deployment The deployment identifier.
 * @return array|null The configuration array or null if not found.
 */
function load_configuration($deployment, $hardcoded = false) {
    global $config;

    if ($hardcoded) {
        $config[$deployment]['enabled'] = true;
    }

    // Check if the deployment is enabled
    if (!isset($config[$deployment]['enabled']) || $config[$deployment]['enabled'] == false || $config[$deployment]['enabled'] === 'false') {
        // Reassign deployment to default if not enabled
        $_SESSION['deployment'] = $GLOBALS['deployment'] = $deployment = $config['azure']['default'];
    }

    $_SESSION['api_key'] = $config[$deployment]['api_key'];
    if (empty($config[$deployment]['assistant_id'])) $config[$deployment]['assistant_id'] = '';

    $output = [
        'deployment' => $deployment,
        'assistant_id'   => trim($config[$deployment]['assistant_id'], '"'),
        #'assistant_id'   => 'asst_iBl1B7WpHluW0B3D39e0k9Ca',
        'api_key' => trim($config[$deployment]['api_key'], '"'),
        'host' => $config[$deployment]['host'],
        'model' => $config[$deployment]['model'] ?? '',
        'base_url' => $config[$deployment]['url'],
        'deployment_name' => $config[$deployment]['deployment_name'],
        'api_version' => $config[$deployment]['api_version'],
        'context_limit' => (int)($config[$deployment]['context_limit']),
    ];
    if (!empty($config[$deployment]['max_tokens'])) $output['max_tokens'] = (int)$config[$deployment]['max_tokens'];
    if (!empty($config[$deployment]['max_completion_tokens'])) $output['max_completion_tokens'] = (int)$config[$deployment]['max_completion_tokens'];
    // print_r($output);
    return $output;
}

function run_rag($question, $chat_id, $user, $config_path, $timeoutSec = 20) {
    $payload = [
        'question' => $question,
        'chat_id'  => $chat_id,
        'user'     => $user,
        'top_k'    => 12,
        'max_context_tokens' => 2000,
        'config_path' => $config_path,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'ragq_').'.json';
    file_put_contents($tmp, json_encode($payload));

    $python  = __DIR__.'/rag310/bin/python3';
    $script  = __DIR__.'/rag_retrieve.py';
    $timeout = '/usr/bin/timeout';
    $errFile = sys_get_temp_dir().'/rag_retrieve_'.getmypid().'.err';

    $cmd = escapeshellarg($timeout).' '.((int)$timeoutSec).' '
         . escapeshellarg($python).' '.escapeshellarg($script)
         .' --json '.escapeshellarg($tmp)
         .' 2>'.escapeshellarg($errFile);

    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    @unlink($tmp);

    $raw = implode("\n", $out);
    $jr  = json_decode($raw, true);

    return [
        'rc'     => $rc,
        'cmd'    => $cmd,
        'stdout' => $raw,
        'stderr' => (is_file($errFile) ? substr(@file_get_contents($errFile), 0, 4000) : ''),
        'json'   => $jr,
    ];
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

    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 2 - " . print_r($message, true), FILE_APPEND);

    if ($active_config['host'] === 'dall-e' || $active_config['host'] === 'gpt-image-1') {
        return array('prompt'=>$message); // Handle dall-E Image Generation Requests
        
    } else {
        // Handle Chat Completion Requests
        return handle_chat_request($message, $chat_id, $user, $active_config);
    }
}

function get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config) {

    #$custom_config = json_decode($custom_config,1);
    #print_r($custom_config); die();
    if (empty($custom_config['workflowId'])) $custom_config['workflowId'] = '';

    // Build the system message
    $workflow_data = get_workflow_data($custom_config['workflowId']);
    $resource = $workflow_data['content'];
    $message = $workflow_data['prompt'];

    #print_r($resource);
    
    // Check and handle any document content first
    $document_messages = handle_document_content($chat_id, $user, $message, $active_config);

    $system_message = [
        [
            'role' => 'user',
            'content' => $resource
        ]
    ];

    if ($document_messages !== null) {
        // If a document is present, use document messages exclusively
        $messages = array_merge($system_message, $document_messages);
    } else {
        $message = 'There was an error processing your post. Please contact support.';
        $messages[] = ["role" => "user", "content" => $message];
    }

    #print_r($messages); die("STOPPED IN THE GET WORKFLOW THREAD FUNCTION\n");

    return $messages;
}

/* =========================================================== */
/*  call_assistant_api – production version (no debug dies)    */
/* =========================================================== */
function call_assistant_api(array $cfg, string $chat_id, array $messages)
{
    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 3 - " . print_r($messages, true), FILE_APPEND);
    if (empty($cfg['assistant_id'])) {
        throw new RuntimeException('assistant_id missing from config');
    }

    $thread_id = ensure_thread_bootstrapped($cfg, $chat_id);

    /* add *all* messages of this turn (system/doc/user) */
    foreach ($messages as $m) {
        file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 4 - " . print_r($m, true), FILE_APPEND);
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

    #echo "this is the document tokens: {$doc_tokens}\n"; die(print_r($docs,1));

    $is_dalle = ($active_config['host'] === 'dall-e' || $active_config['host'] === 'gpt-image-1');

    if ($is_dalle) {
        // dall-E Image Generation Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/images/generations?api-version=" . $active_config['api_version'];
        
        $payload = [
            'model' => $active_config['model'],
            'prompt' => $msg['prompt'],
            'n' => 1,
            'size' => '1024x1024'
        ];
        #print_r($payload); die();
    } else {
        #print_r($active_config); die();
        // Chat Completion Endpoint
        $url = $active_config['base_url'] . "/openai/deployments/" . $active_config['deployment_name'] . "/chat/completions?api-version=" . $active_config['api_version'];
        $top_p = (preg_match('/o1|o3|o4|5/',$_SESSION['deployment'])) ? 1 : 0.95;
        
        $payload = [
            'messages' => $msg,
            "temperature" => (float)$_SESSION['temperature'],
            "frequency_penalty" => 0,
            "presence_penalty" => 0,
            "top_p" => $top_p
        ];
        if (!empty($active_config['max_tokens'])) {
            $payload['max_tokens'] = $active_config['max_tokens'];
        }
        if (!empty($active_config['max_completion_tokens'])) {
            /*
            echo "THIS IS THE CURRENT MAX COMPLETION: " . $active_config['max_completion_tokens']."\n";
            echo "THIS IS THE CURRENT CONTEXT LIMIT: " . $active_config['context_limit']."\n";
            echo "THIS IS THE CURRENT DOC TOKENS: " . $doc_tokens."\n";
            echo "THIS IS THE CONTEXT LIMIT MINUS DOC TOKENS: " . $active_config['context_limit'] - $doc_tokens."\n";
            echo "THIS IS THE MIN OF THOSE TWO: " . min($active_config['max_completion_tokens'], $active_config['context_limit'] - $doc_tokens)."\n";
            */
            $payload['max_completion_tokens'] = min($active_config['max_completion_tokens'], $active_config['context_limit'] - $doc_tokens);
            $payload['temperature'] = 1;
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

// Pick the exact string your UI expects as the key
function ui_deployment_key(array $cfg) {
    return $cfg['deployment'] ?? $cfg['deployment_name'] ?? 'n/a';
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

    /* ===================== 1) ASSISTANT ========================= */
    if ($host === 'assistant') {
        /* ---- your existing assistant logic remains untouched --- */
        /* ...                                                      */
        /* return [...];                                            */
    }

    /* ================= 2)  GPT-IMAGE-1 (base64) ================= */
    if ($host === 'gpt-image-1') {

        /* Decode JSON returned by Azure/OpenAI */
        $data = json_decode($response, true);

        if (!is_array($data) || empty($data['data'][0]['b64_json'])) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Image API did not return valid base64 JSON.'
            ];
        }

        /* Decode image bytes ------------------------------------------------ */
        $b64 = $data['data'][0]['b64_json'];
        $bin = base64_decode($b64, true);          // strict = true

        if ($bin === false) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Failed to base64-decode image data.'
            ];
        }

        /* Build filenames / directories ------------------------------------- */
        $unique      = uniqid();
        $imageName   = "{$chat_id}-{$unique}.png";         // gpt-image-1 outputs PNG
        $image_dir   = dirname(__DIR__) . '/image_gen';
        $fullsizeDir = $image_dir . '/fullsize';
        $smallDir    = $image_dir . '/small';

        if (!is_dir($fullsizeDir)) mkdir($fullsizeDir, 0755, true);
        if (!is_dir($smallDir))    mkdir($smallDir,   0755, true);

        $full  = "$fullsizeDir/$imageName";
        $small = "$smallDir/$imageName";

        /* Write full-resolution file ---------------------------------------- */
        if (@file_put_contents($full, $bin) === false) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Cannot write full-size image to disk.'
            ];
        }

        /* Create 50 % thumbnail --------------------------------------------- */
        if (!scale_image_from_path($full, $small, 0.5)) {
            // not fatal, but log it
            error_log("Thumbnail generation failed for $full");
        }

        /* Record the exchange in DB ----------------------------------------- */
        $eid = create_exchange(
            $uiDeployment,
            $chat_id,
            $user_prompt,
            '',          // reply text – none for image generation
            $wfId,
            $imageName   // store image name so UI can fetch /image_gen/…
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

    /* ================ 3) Normal chat-completions ========================== */
    $data = json_decode($response, true);

    /* 3a) JSON or API error */
    if (!is_array($data)) {
        return [
            'deployment' => $uiDeployment,
            'error'      => true,
            'message'    => 'Invalid JSON from completions API.'
        ];
    }
    if (isset($data['error'])) {
        log_error_details(
            $msg_ctx,
            $user_prompt,
            $data['error']['message'] ?? 'Unknown error'
        );
        return [
            'deployment' => $uiDeployment,
            'error'      => true,
            'message'    => $data['error']['message'] ?? 'Unknown error'
        ];
    }

    /* 3b) Success */
    $answer_text = $data['choices'][0]['message']['content'] ?? 'No response text found.';
    $eid = create_exchange($uiDeployment, $chat_id, $user_prompt, $answer_text, $wfId);

    return [
        'eid'        => $eid,
        'deployment' => $uiDeployment,
        'error'      => false,
        'message'    => $answer_text
    ];
}





function old_process_api_response($response,
                              $active_config,
                              $chat_id,
                              $user_prompt,
                              $msg_ctx,
                              $custom_config)
{
    $wfId = $custom_config['workflowId'] ?? '';
    $uiDeployment = ui_deployment_key($active_config);

    /* ========================= 1) ASSISTANT ========================= */
    if (($active_config['host'] ?? '') === 'assistant') {
        // $response is already the assistant *message object* (array)
        $assistantMsg = $response;
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
                            $links[]   = fetch_and_save_file($chat_id, $fid, $active_config, $ann['text'] ?? '');
                            $seen[$fid] = true;
                        }
                    }
                }
            } elseif ($part['type'] === 'file_path') {
                $fid = $part['file_path']['file_id'];
                if (!isset($seen[$fid])) {
                    $links[]   = fetch_and_save_file($chat_id, $fid, $active_config);
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

    /* =========================== 2) DALL·E ========================== */
    if ($active_config['host'] === 'dall-e' || $active_config['host'] === 'gpt-image-1') {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'deployment' => $uiDeployment,
                'error'      => true,
                'message'    => 'Invalid JSON from image API'
            ];
        }
        file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    GPT-IMAGE-1 LOG - r16 - " . print_r($data, true), FILE_APPEND);
        #print_r($data); 
        die("\n\nGOT TO THIS POINT dude\n");
        $image_url = $data['data'][0]['url'] ?? null;
        if (!$image_url) {
            return [ 'deployment'=>$uiDeployment, 'error'=>true, 'message'=>'No image URL in response' ];
        }

        preg_match('#/images/([^/]+)/generated_#', $image_url, $m);
        $unique_dir    = $m[1] ?? uniqid();
        $imageName     = $chat_id . '-' . $unique_dir . '.png';
        $image_dir     = dirname(__DIR__) . '/image_gen';
        $fullsize_dir  = $image_dir . '/fullsize';
        $small_dir     = $image_dir . '/small';
        if (!is_dir($fullsize_dir)) mkdir($fullsize_dir, 0755, true);
        if (!is_dir($small_dir))   mkdir($small_dir,   0755, true);

        $full = "$fullsize_dir/$imageName";
        $small= "$small_dir/$imageName";

        $img = @file_get_contents($image_url);
        if ($img === false || file_put_contents($full, $img) === false) {
            return [ 'deployment'=>$uiDeployment, 'error'=>true, 'message'=>'Failed to fetch/save image' ];
        }
        scale_image_from_path($full, $small, 0.5);

        $eid = create_exchange($uiDeployment, $chat_id, $user_prompt, '', $wfId, $imageName);

        return [
            'eid'            => $eid,
            'deployment'     => $uiDeployment,
            'error'          => false,
            'message'        => 'Image generated successfully.',
            'image_gen_name' => $imageName
        ];
    }

    /* ========================= 3) COMPLETIONS ======================= */
    // (OpenAI/Azure chat-completions JSON string)
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

function fetch_and_save_file(string $chat_id,
                             string $file_id,
                             array  $cfg,
                             ?string $suggested = null): array
{
    $bytes = rest_raw('GET', "/openai/files/$file_id/content", null, $cfg);

    /* extension guessing */
    $ext = pathinfo($suggested ?? '', PATHINFO_EXTENSION) ?: 'bin';

    $basename = "{$chat_id}-{$file_id}.{$ext}";
    $fullpath = DOC_GEN_DIR . '/full/' . $basename;
    file_put_contents($fullpath, $bytes);

    /* strip “sandbox:/mnt/data/” if it’s there */
    $pretty = $suggested
              ? preg_replace('#^sandbox:/mnt/data/#i', '', $suggested)
              : $basename;

    return [
        'filename'      => $basename,                         // opaque
        'display_name'  => $pretty,                           // nice label
        'url'           => "download.php?f=$basename&name=" .
                           urlencode($pretty)                 // pass to PHP
    ];
}

/**
 * Save arbitrary bytes to the doc_gen/full folder and return the filename.
 * Keeps the original extension if the Assistant gave one, else defaults to .bin.
 */
function save_to_blob(string $chat_id,
                      string $file_id,
                      string $bytes,
                      ?string $hint = null): string
{
    $ext = pathinfo($hint ?? '', PATHINFO_EXTENSION) ?: 'bin';
    $basename = "{$chat_id}-{$file_id}.{$ext}";
    $path     = DOC_GEN_DIR . '/full/' . $basename;

    file_put_contents($path, $bytes);
    return $basename;
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
    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 5 - " . print_r($message, true), FILE_APPEND);

    // 1) build system message
    $system_message = build_system_message($active_config);

    // 2) pull docs and compute their total tokens
    $docs       = get_chat_documents($user, $chat_id);
    $doc_tokens = array_sum(array_column($docs, 'document_token_length'));

    // 3) format docs into messages
    # AT THE MOMENT WE HAVE DISABLED PASSING DOCUMENTS TO THE CONTEXT SINCE WE NOW USE RAG
    $document_messages = []; #format_document_messages($docs, $message);

    // 4) pull chat history, *reserving* doc_tokens
    $context_messages = retrieve_context_messages(
        $chat_id,
        $user,
        $active_config,
        $message,
        $reserved_tokens = $doc_tokens
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
    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 6 - " . print_r($messages, true), FILE_APPEND);


    #die("THESE ARE THE FINAL MESSAGES\n" . print_r($messages,1));

    return $messages;
}


/**
 * Given an array of doc rows and the current user message,
 * return an array of “messages” that inject each document,
 * splitting any long text docs into parts under the Azure limit.
 */
function format_document_messages(array $docs, string $userMessage): array {
    $messages = [];
    $maxLength = 256000;

    foreach ($docs as $i => $doc) {
        $baseName = $doc['document_name'];
        $content  = $doc['document_content'];

        // image files: no splitting
        if (strpos($doc['document_type'], 'image/') === 0) {
            $messages[] = [
                "role"    => "user",
                "content" => "This is document #" . ($i + 1) . ". Filename: " . $baseName
            ];
            $messages[] = [
                "role"    => "user",
                "content" => [
                    ["type" => "text",      "text"      => $userMessage],
                    ["type" => "image_url", "image_url" => ["url" => $content]]
                ]
            ];
            continue;
        }

        // text docs: possibly split into parts
        $length = mb_strlen($content, 'UTF-8');
        if ($length <= $maxLength) {
            // under limit: send as one message
            $messages[] = [
                "role"    => "user",
                "content" => "This is document #" . ($i + 1) . ". Filename: " . $baseName
            ];
            $messages[] = [
                "role"    => "user",
                "content" => $content
            ];
        } else {
            // split into parts
            $parts = [];
            $offset = 0;
            while ($offset < $length) {
                $parts[] = mb_substr($content, $offset, $maxLength, 'UTF-8');
                $offset += $maxLength;
            }
            $totalParts = count($parts);

            foreach ($parts as $k => $partContent) {
                $partNum = $k + 1;
                $messages[] = [
                    "role"    => "user",
                    "content" => sprintf(
                        "This is document #%d. Filename: %s (part %d of %d)",
                        $i + 1,
                        $baseName,
                        $partNum,
                        $totalParts
                    )
                ];
                $messages[] = [
                    "role"    => "user",
                    "content" => $partContent
                ];
            }
        }
    }

    return $messages;
}











/**
 * Given an array of doc rows and the current user message,
 * return an array of “messages” that inject each document.
 */
function old_format_document_messages(array $docs, string $userMessage): array {
    $messages = [];
    foreach ($docs as $i => $doc) {
        // metadata
        $messages[] = [
            "role"    => "user",
            "content" => "This is document #" . ($i + 1) . ". Filename: " . $doc['document_name']
        ];

        if (strpos($doc['document_type'], 'image/') === 0) {
            $messages[] = [
                "role"    => "user",
                "content" => [
                    ["type" => "text",  "text"           => $userMessage],
                    ["type" => "image_url", "image_url"  => ["url" => $doc['document_content']]]
                ]
            ];
        } else {
            $messages[] = [
                "role"    => "user",
                "content" => $doc['document_content']
            ];
        }
    }

    return $messages;
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
    file_put_contents(__DIR__.'/assistant_msgs.log', "\n\n    -    ASSISTANTLOG - 7 - " . print_r($payload, true), FILE_APPEND);
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

/**
 * Executes an API call using cURL.
 *
 * @param string $url The API endpoint URL.
 * @param array $payload The JSON payload to send.
 * @param array $headers The HTTP headers.
 * @return string The API response.
 */
function execute_api_call($url, $payload, $headers, $chat_id = '') {
    // Logging for debugging
    /*
    $date = new DateTime();
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);
    $log = 
    $date->format('Y-m-d H:i:s')."\n". 
    "Chat ID: ".$chat_id."\n".
    "URL: ".$url."\n".
    "Headers: ".$headers[1]."\n".
    "Prompt: ".substr($payload['messages'][count($payload['messages'])-1]['content'],0,100)."\n\n";
    #die($log);
    file_put_contents("mylog.log", $log, FILE_APPEND);
    */
    
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
 * Constructs the system message for chat completions.
 *
 * @param array $active_config The active deployment configuration.
 * @return array The system message array.
 */
function build_system_message($active_config) {
    // Initialize DateTime with the specified timezone
    $date = new DateTime();
    $timezone = new DateTimeZone('America/New_York');
    $date->setTimezone($timezone);

    $about = clean_disclaimer_text();

    // Construct the system message with correct role and defined $date
    $system_message = [
        [
            'role' => 'user',
            'content' => 'You are NHLBI Chat, a helpful assistant for staff at the National Heart Lung and Blood Institute. In this timezone, ' . $date->getTimezone()->getName() . ', the current date and time of this prompt is ' . $date->format('Y-m-d H:i:s') . '. The user\'s browser has the preferred language (HTTP_ACCEPT_LANGUAGE) set to ' . $_SERVER['HTTP_ACCEPT_LANGUAGE'] . ', so please reply in that language if possible, unless directed otherwise. If you return code, be sure to use the tic-mark (```) notation so that it renders properly in the Chat interface. The following is the disclaimer / instruction text we present to users: <<<' . $about . '>>>. '
        ]
    ];

    return $system_message; 
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

/**
 * Processes document content from the session.
 *
 * Retrieves all documents related to the given chat, constructs
 * an LLM-ready messages array, and appends the user prompt at the end.
 *
 * @param string $chat_id The unique chat ID.
 * @param string $user The user ID.
 * @param string $message The current user message.
 * @param array $active_config The active deployment configuration.
 * @return array|null The messages array if documents exist, otherwise null.
 */
function handle_document_content($chat_id, $user, $message, $active_config) {
    $docs = get_chat_documents($user, $chat_id);
    # print_r($docs);

    if (!empty($docs)) {
        $messages = [];

        foreach ($docs as $i => $doc) {
            // Add document metadata
            $messages[] = [
                "role" => "user",
                "content" => "This is document #" . ($i + 1) . ". Its filename is: " . $doc['document_name']
            ];

            // Handle image documents separately
            if (strpos($doc['document_type'], 'image/') === 0) {
                $messages[] = [
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => $message],
                        ["type" => "image_url", "image_url" => ["url" => $doc['document_content']]]
                    ]
                ];
            } else {
                // Append text document content
                $messages[] = [
                    "role" => "user",
                    "content" => $doc['document_content']
                ];
            }
        }

        // Append the original user message after listing all documents
        $messages[] = [
            "role" => "user",
            "content" => $message
        ];

        return $messages;
    }

    return null; // Explicitly return null if no documents exist
}

function scale_image_from_path($src_path, $dest_path, $scaleFactor) {
    $image_data = @file_get_contents($src_path);
    if ($image_data === false) {
        error_log("Failed to read image for scaling: $src_path");
        return false;
    }

    $source_img = @imagecreatefromstring($image_data);
    if (!$source_img) {
        error_log("Invalid image format: $src_path");
        return false;
    }

    $orig_width = imagesx($source_img);
    $orig_height = imagesy($source_img);

    $new_width = (int)($orig_width * $scaleFactor);
    $new_height = (int)($orig_height * $scaleFactor);

    $dest_img = imagecreatetruecolor($new_width, $new_height);
    imagecopyresampled($dest_img, $source_img, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);

    $success = imagepng($dest_img, $dest_path);
    imagedestroy($source_img);
    imagedestroy($dest_img);

    if (!$success) {
        error_log("Failed to save scaled image: $dest_path");
    }

    return $success;
}

// Function to convert a local image to a base64 data URL
function local_image_to_data_url($image_path, $mimeType)
{
    // Fallback to application/octet-stream if MIME type is not set
    if ($mimeType === null) {
        $mimeType = "application/octet-stream";
    }

    // Open the image file in binary mode and encode it to base64
    $base64_encoded_data = base64_encode(file_get_contents($image_path));

    // Return the data URL with the appropriate MIME type
    return "data:$mimeType;base64,$base64_encoded_data";
}

/**
 * Retrieves the recent messages from the database for the current chat session.
 *
 * @param int $chat_id The chat identifier.
 * @param string $user The user identifier.
 * @return array The recent chat messages.
 */
function get_recent_messages($chat_id, $user) {
    if (!empty($chat_id)) {
        return get_all_exchanges($chat_id, $user);
    }
    return [];
}

/**
 * Estimates the number of tokens in a given text.
 *
 * @param string $text The input text.
 * @return int The estimated token count.
 */
function estimate_tokens($text) {
    // This is a simplistic approximation. 
    return ceil(strlen($text) / 3);
}

/**
 * Checks if the user is authenticated.
 *
 * @return bool True if authenticated, false otherwise.
 */
function isAuthenticated() {
    return isset($_SESSION['tokens']) && isset($_SESSION['tokens']['access_token']);
}

/**
 * Logs the user out by destroying the session.
 */
function logout() {

    // Unset all session variables
    $_SESSION = array();

    // If it's desired to kill the session, also delete the session cookie.
    // Note: This will destroy the session, and not just the session data!
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    // Finally, destroy the session.
    session_destroy();
}

/**
 * Logs detailed error information using PHP's standard error logging system.
 *
 * @param array $msg The message context that triggered the API call.
 * @param string $message The user message that triggered the API call.
 * @param string $api_error The error message returned by the API.
 */
function log_error_details($msg, $message, $api_error) {
    // Prepare the log entry with detailed information
    $log_entry = "==== Error Occurred ====\n";
    $log_entry .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
    $log_entry .= "User Message: " . $message . "\n";
    $log_entry .= "Message Context: " . print_r($msg, true) . "\n";
    $log_entry .= "API Error: " . $api_error . "\n";
    $log_entry .= "Session Data: " . print_r($_SESSION, true) . "\n";
    $log_entry .= "Server Data: " . print_r($_SERVER, true) . "\n";
    $log_entry .= "========================\n";

    // Send the log entry to PHP's standard error log
    error_log($log_entry);
}

// Helper function to wait for critical session data
function waitForUserSession($maxAttempts = 5, $delayMicroseconds = 5000) {
    $attempt = 0;
    // Check if the user_data userid is set; if not, wait and retry
    while ($attempt < $maxAttempts && empty($_SESSION['user_data']['userid'])) {
        usleep($delayMicroseconds);
        $attempt++;
        $delayMicroseconds += 5000;
    }
    return !empty($_SESSION['user_data']['userid']);
}

/**
 * Helper function to call the Python RAG script.
 *
 * @param string $user_question The question to pass to the script.
 * @return array Decoded JSON output from the script (e.g., ['augmented_prompt' => ...] or ['error' => ...]).
 */
function call_rag_script($user_question) {
    global $config; // Access global config for paths

    // --- Retrieve Paths from Config (Ensure these are set!) ---
    $python_executable = __DIR__.'/rag310/bin/python3';
    $script_path = __DIR__.'/rag_processor.py';

    if (!$script_path || !file_exists($script_path)) {
        return ['error' => 'RAG script path not configured or not found. Path: ' . $script_path];
    }
    if (!is_executable($python_executable) && !preg_match('/^python[3]?$/', $python_executable)) {
         // Basic check if it's not 'python'/'python3' and not executable directly
         // A more robust check might involve `shell_exec("command -v $python_executable")`
         // but let's keep it simple for now. Adjust if needed.
        // return ['error' => 'Python executable not found or not executable: ' . $python_executable];
        // Allow 'python3' assuming it's in PATH
    }


    // --- Prepare Command ---
    // Use escapeshellarg to safely pass the user question
    $escaped_question = escapeshellarg($user_question);
    $command = $python_executable . ' ' . escapeshellarg($script_path) . ' ' . $escaped_question . ' 2>&1'; // Redirect stderr to stdout

    // --- Execute Command ---
    // Consider adding timeout logic if the script might hang
    $output = [];
    $return_var = -1;
    exec($command, $output, $return_var);

    $raw_output = implode("\n", $output); // Combine output lines

    // --- Process Output ---
    if ($return_var !== 0) {
        // Script exited with an error code
        return ['error' => "RAG script execution failed (Exit Code: $return_var). Output: " . $raw_output];
    }

    // Attempt to decode JSON output
    $json_result = json_decode($raw_output, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Output was not valid JSON
        return ['error' => "Failed to decode JSON response from RAG script. Raw output: " . $raw_output];
    }

    // Return the decoded JSON (should contain 'augmented_prompt' or 'error')
    return $json_result;
}

/**
 * Helper function to process and return standardized error responses.
 */
function process_error_response($error_message, $deployment, $chat_id, $original_message, $msg_context) {
     // Log the error internally if needed
     error_log("Error in get_gpt_response: $error_message (Deployment: $deployment, ChatID: $chat_id)");

     // Return structure similar to process_api_response but indicating an error
     // Mimic structure of process_api_response for consistency on the frontend
     return [
         'deployment' => $deployment,
         'error' => true,
         'message' => $error_message, // Error message for the user
         'request' => [ // Include some request context for debugging if helpful
             'original_message' => $original_message,
             'context_messages' => $msg_context // The attempted message structure
         ],
         'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], // Default usage
         'finish_reason' => 'error'
     ];
}

/**
 * Calls the Mocha API.
 *
 * @param string $base_url The base URL for the Mocha API.
 * @param mixed $msg The message payload.
 * @return string The API response.
 */
function call_mocha_api($base_url, $msg) {
    global $config;
    
    $payload = [
        "model" => "llama3:70b",
        'messages' => $msg,
        "max_tokens" => $config['max_tokens'],
        "temperature" => (float)$_SESSION['temperature'],
        "frequency_penalty" => 0,
        "presence_penalty" => 0,
        "top_p" => 0.95,
        "stop" => ""
    ];
    $headers = ['Content-Type: application/json'];
    $response = execute_api_call($base_url, $payload, $headers);
    return $response;
}

function clean_disclaimer_text() {
    global $config;
    require_once 'staticpages/disclaimer_text.php';

    // Step 1: Replace structural tags with line breaks
    $html = preg_replace('/<\/?(p|ul|ol)>/i', "\n\n", $maintext);   // Paragraphs/lists → double break
    $html = preg_replace('/<li>/i', "- ", $html);               // Bullet for list items
    //$html = preg_replace('/<\/li>/i', "\n", $html);             // Line break after list item
    $html = preg_replace('/<br\s*\/?>/i', "\n", $html);         // Single break

    // Step 2: Strip remaining tags and decode entities
    $html = strip_tags($html);
    $html = html_entity_decode($html);

    // Step 3: Normalize whitespace (preserve double line breaks)
    $html = preg_replace('/[ \t]+/', ' ', $html);               // Collapse spaces/tabs
    $html = preg_replace('/[ \t]*\n[ \t]*/', "\n", $html);       // Clean line edges
    $html = preg_replace('/\n{3,}/', "\n\n", $html);             // Collapse 3+ breaks → 2

    $text = preg_replace('/\byou\b/i', 'NHLBI staff', $html);
    $text = preg_replace('/\byour\b/i', 'the', $text);

    // Step 4: Final trim
    return trim($text);
}
