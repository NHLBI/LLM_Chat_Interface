<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include necessary libraries
require_once 'lib.required.php';

// Get the chat_id if present
$chat_id = isset($_REQUEST['chat_id']) ? $_REQUEST['chat_id'] : '';

// Flag to indicate a new chat was created
$new_chat_created = false;

// Create a new chat session if no chat ID is provided
if (empty($chat_id)) {
    $chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment']);
    $new_chat_created = true;
}

// Check if there's a request to remove the uploaded document(s)
if (isset($_GET['remove']) && $_GET['remove'] == '1') {
    // Remove all documents for this chat
    remove_chat_documents($user, $chat_id);
    // Use JSON response if the request is AJAX
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'redirect' => true]);
        exit;
    }
    header('Location: ' . urlencode($chat_id));
    exit;
}


if (!empty($_REQUEST['selected_workflow'])) {
    $workflow = json_decode($_REQUEST['selected_workflow'],1);
    if (!empty($workflow['configLabel'])) {
        $configLabels = explode(',',$workflow['configLabel']);
        $configDescriptions = explode(',',$workflow['configDescription']);
        for($i=0;$i<count($configLabels);$i++) {
            $workflow_config[$configLabels[$i]] = $configDescriptions[$i];
        }
    }
    #echo '<pre>'.print_r($workflow_config,1).'</pre>'; 

    /*
    if (!empty($workflow_config['execution']) && ($workflow_config['execution'] == 'auto-prompt-submit')) {
        // Save a flag that tells index.php to auto-submit the chat
        $_SESSION['workflow_auto_prompt'] = true;
        // Optionally, store the prompt text (which might come from the workflow configuration)
        // e.g., you could use the "prompt" field that was provided with the workflow.

        $workflow_data = get_workflow_data($workflow['workflowId']);
        $_SESSION['workflow_auto_prompt'] = json_encode($workflow_data['prompt']);
        $_SESSION['selected_workflow'] = json_encode($workflow); // $workflow already contains configLabel, configDescription, etc.


        #echo '<pre>'.print_r($_SESSION,1).'</pre>'; die();
    }
    */

}

#echo '<pre>'.print_r($_REQUEST,1).'</pre>'; die();

if (isset($_FILES['uploadDocument'])) {
    // Process multiple file uploads
    $fileCount = count($_FILES['uploadDocument']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['uploadDocument']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName      = $_FILES['uploadDocument']['tmp_name'][$i];
        $originalName = basename($_FILES['uploadDocument']['name'][$i]);
        $mimeType     = mime_content_type($tmpName);
        $user         = $_SESSION['user_data']['userid'] ?? '';


        // IMAGES: store as before; do NOT index for RAG
        if (strpos($mimeType, 'image/') === 0) {
            $base64Image  = local_image_to_data_url($tmpName, $mimeType);
            $document_id  = insert_document($user, $chat_id, $originalName, $mimeType, $base64Image);
            // No vector indexing for images
            continue;
        }

        // DOCUMENTS: parse with python from your venv
        $python = __DIR__ . '/rag310/bin/python3';
        $parser = __DIR__ . '/parser_multi.py';   // assumes it reads file & prints cleaned text

        $parseCmd = sprintf('%s %s %s %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($parser),
            escapeshellarg($tmpName),
            escapeshellarg($originalName)
        );

        $parseOut = [];
        $parseRc  = 0;
        exec($parseCmd, $parseOut, $parseRc);
        $parsedText = implode("\n", $parseOut);

        if ($parseRc !== 0 || trim($parsedText) === '') {
            error_log("Parser failed for {$originalName} (rc={$parseRc}). Output:\n".$parsedText);
            continue;
        }

        // Insert the parsed text into `document` and CAPTURE the id
        $document_id = insert_document($user, $chat_id, $originalName, $mimeType, $parsedText);

        // Record file_sha256 (binary hash) for dedupe/versioning
        try {
            $file_sha256 = hash_file('sha256', $tmpName);
            if ($file_sha256) {
                $stmt = $pdo->prepare("UPDATE document SET file_sha256 = :sha WHERE id = :id");
                $stmt->execute(['sha' => $file_sha256, 'id' => $document_id]);
            }
        } catch (Throwable $e) {
            error_log("Failed to set file_sha256 for document_id {$document_id}: ".$e->getMessage());
        }

        // ---- Build the RAG index for this document ----
        $payload = [
            'document_id'     => (int)$document_id,
            'chat_id'         => $chat_id,
            'user'            => $user,
            'embedding_model' => "NHLBI-Chat-workflow-text-embedding-3-large",
            'config_path'     => $config_file,   // <<< ADD THIS
            // You can pass qdrant overrides here if you wish:
            // 'qdrant' => ['url' => 'http://127.0.0.1:6333', 'api_key' => '...']
        ];
        error_log('RAG payload: '.json_encode($payload));


        $json_file = tempnam(sys_get_temp_dir(), 'rag_').'.json';
        file_put_contents($json_file, json_encode($payload));

        $indexer = __DIR__ . '/build_index.py';
        $idxCmd  = sprintf('%s %s --json %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($indexer),
            escapeshellarg($json_file)
        );

        $out = [];
        $rc  = 0;
        exec($idxCmd, $out, $rc);
        @unlink($json_file);

        $result = json_decode(implode("\n", $out), true);
        if ($rc !== 0 || !$result || empty($result['ok'])) {
            error_log("RAG indexing failed for document_id {$document_id}: ".implode("\n", $out));
            // optional: set rag_index.ready=0 here if you want
        } else {
            // success; rag_index.ready was set by the script
            // $result['chunk_count'] is available if you want to display it
        }

        /* NEW CODEBLOCK TO HANDLER THE MULTIMODAL OPTIONS 
        $cmd = __DIR__ . "/parser_multi_mm.py \"$tmpName\" \"$originalName\"";
        $descriptors = [
            1 => ["pipe","w"],   // stdout → TSV lines (images)
            2 => ["pipe","w"]    // stderr → main text blob
        ];
        $proc   = proc_open($cmd, $descriptors, $pipes);
        $tsv    = stream_get_contents($pipes[1]);    // images
        $text   = stream_get_contents($pipes[2]);    // full doc text
        proc_close($proc);

        //* ---- (a) store the document's text exactly as before ---- 
        insert_document($user, $chat_id,
                        $originalName,           // name
                        $mimeType,               // same mime as upload
                        $text);                  // content

        //* ---- (b) one row per picture the parser found ---- 
        foreach (explode("\n", $tsv) as $line) {
            if (!trim($line)) continue;
            list($path,$vname,$mime,$ocrText) = explode("\t", $line);

            // turn the temp file into a data-URL just like user-supplied images
            $dataUrl = local_image_to_data_url($path, $mime);

            // IMPORTANT: we store mime that starts with image/ so
            //            format_document_messages() will treat it as vision
            insert_document($user, $chat_id, $vname, $mime, $dataUrl);
        }
        NEW CODEBLOCK TO HANDLER THE MULTIMODAL OPTIONS */





    }
    
    // Use JSON response for AJAX requests, else do a header redirect
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'new_chat' => $new_chat_created]);
    } else {
        header('Location: ' . urlencode($chat_id));
    }
} else {
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'new_chat' => false]);
    } else {
        header('Location: ' . urlencode($chat_id));
    }
}

exit();

/**
 * Helper function to detect AJAX requests.
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

