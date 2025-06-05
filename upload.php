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
        // Skip files that encountered an upload error
        if ($_FILES['uploadDocument']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        $tmpName = $_FILES['uploadDocument']['tmp_name'][$i];
        $originalName = basename($_FILES['uploadDocument']['name'][$i]);
        $mimeType = mime_content_type($tmpName);

        // Check if the uploaded file is an image
        if (strpos($mimeType, 'image/') === 0) {
            $base64Image = local_image_to_data_url($tmpName, $mimeType);
            insert_document($user, $chat_id, $originalName, $mimeType, $base64Image);
        } else {
            // Process document uploads via the Python parser script
            $command = __DIR__ . "/parser_multi.py \"" . $tmpName . "\" \"" . $originalName . "\" 2>&1";
            $output = shell_exec($command);
    
            if (strpos($output, 'ValueError') === false) {
                insert_document($user, $chat_id, $originalName, $mimeType, $output);
            } else {
                // Optionally log the error or notify the user
                continue;
            }
        }
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

