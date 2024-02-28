<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Include necessary libraries
require_once 'lib.required.php';



// Get the chat_id if present
$chat_id = isset($_REQUEST['chat_id']) ? $_REQUEST['chat_id'] : '';

// Check if there's a request to remove the uploaded file
if (isset($_GET['remove']) && $_GET['remove'] == '1') {

    // Clear the session variables
    unset($_SESSION['document_text']);
    unset($_SESSION['document_name']);
    update_chat_document($user,$chat_id,'','');

    // Redirect to the main page with chat_id
    header('Location: index.php?chat_id='.urlencode($chat_id));
    exit;

}


if (isset($_FILES['pdfDocument'])) {
    $file = $_FILES['pdfDocument'];
    #echo "<pre>".print_r($_FILES,1)."</pre>"; die("got here");

    // Ensure that your script is executable and has the correct shebang line
    #$command = __DIR__."/pdf_parser.py \"".$file['tmp_name']."\"";
    $command = __DIR__."/parser_multi.py \"".$file['tmp_name']."\" \"".basename($file['name'])."\"";

    $output = shell_exec($command);
    #echo "<pre>".print_r($output,1)."</pre>"; die("got here");

    // Store the text and the original filename in session variables
    $_SESSION['document_text'] = $output;
    $_SESSION['document_name'] = basename($file['name']);

    #echo "<pre>".print_r($_SESSION,1)."</pre>"; die("got here");

    update_chat_document($user, $chat_id, $_SESSION['document_name'], $_SESSION['document_text']);

    // Redirect back to the index page
    header('Location: index.php?chat_id='.urlencode($chat_id));

} else {
    header('Location: index.php?chat_id='.urlencode($chat_id));
    // Handle no file uploaded scenario
}

// Prevent accidental output by stopping the script here
exit;

