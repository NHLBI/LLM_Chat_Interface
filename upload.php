<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include necessary libraries
require_once 'lib.required.php';

// Get the chat_id if present
$chat_id = isset($_REQUEST['chat_id']) ? $_REQUEST['chat_id'] : '';

// Create a new chat session if no chat ID is provided
if (empty($chat_id)) {
    $chat_id = $new_chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment']);
}

// Check if there's a request to remove the uploaded document(s)
if (isset($_GET['remove']) && $_GET['remove'] == '1') {
    // Remove all documents for this chat
    remove_chat_documents($user, $chat_id);
    header('Location: ' . urlencode($chat_id));
    exit;
}

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
                // Optionally log the error or notify the user (error handling code could go here)
                continue;
            }
        }
    }
    
    // Redirect back to the main page with the chat ID
    header('Location: ' . urlencode($chat_id));
} else {
    header('Location: ' . urlencode($chat_id));
}

exit();

