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
    $chat_id = $new_chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment'], '', '');
}

// Check if there's a request to remove the uploaded file
if (isset($_GET['remove']) && $_GET['remove'] == '1') {
    // Clear the session variables
    unset($_SESSION['document_text']);
    unset($_SESSION['document_type']);
    unset($_SESSION['document_name']);
    update_chat_document($user, $chat_id, '', '','');

    // Redirect to the main page with chat_id
    #header('Location: index.php?chat_id=' . urlencode($chat_id));
    header('Location: ' . urlencode($chat_id));
    exit;
}

if (isset($_FILES['uploadDocument'])) {
    $file = $_FILES['uploadDocument'];
    $mimeType = mime_content_type($file['tmp_name']);

    // Check if the uploaded file is an image or a document
    if (strpos($mimeType, 'image/') === 0) {
        // Handle image uploads
        $base64Image = local_image_to_data_url($file['tmp_name'], $mimeType);

        // Save the base64 image to the session and the database
        $_SESSION['document_text'] = $base64Image;
        $_SESSION['document_type'] = $mimeType;
        $_SESSION['document_name'] = basename($file['name']);

        update_chat_document($user, $chat_id, $_SESSION['document_name'], $_SESSION['document_type'], $_SESSION['document_text']);
    } else {
        // Handle document uploads via Python script
        $command = __DIR__ . "/parser_multi.py \"" . $file['tmp_name'] . "\" \"" . basename($file['name']) . "\" 2>&1";
        $output = shell_exec($command);

        if (strpos($output, 'ValueError') === false) {
            // Store the text and the original filename in session variables
            $_SESSION['document_text'] = $output;
            $_SESSION['document_type'] = $mimeType;
            $_SESSION['document_name'] = basename($file['name']);

            update_chat_document($user, $chat_id, $_SESSION['document_name'], $_SESSION['document_type'], $_SESSION['document_text']);
        } else {
            $_SESSION['error'] = 'There was an error parsing the uploaded document. Please ensure it is the correct file type.';
        }
    }

    // Redirect back to the index page
    header('Location: ' . urlencode($chat_id));
} else {
    header('Location: ' . urlencode($chat_id));
}

// Prevent accidental output by stopping the script here
exit;

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

