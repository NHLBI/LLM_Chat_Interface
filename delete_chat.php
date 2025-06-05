<?php
// Include the database connection file
require_once 'lib.required.php';
require_once 'db.php';

// Check if the 'chat_id' is set in the POST data
if(isset($_POST['chat_id'])) {
    // Assign the 'chat_id' from the POST data to the $chat_id variable
    $chat_id = filter_input(INPUT_POST, 'chat_id', FILTER_SANITIZE_STRING);
    
    if (!$chat_id) {
        die("Invalid input");
    }

    // Begin a transaction
    $pdo->beginTransaction();

    try {
        // Prepare a SQL statement to update the chat table
        $stmt1 = $pdo->prepare("UPDATE chat SET `deleted` = 1 WHERE id = :id");
        $stmt1->execute(['id' => $chat_id]);

        // Prepare a SQL statement to update the exchange table
        $stmt2 = $pdo->prepare("UPDATE exchange SET `deleted` = 1 WHERE chat_id = :chat_id");
        $stmt2->execute(['chat_id' => $chat_id]);

        // Commit the transaction if both statements executed successfully
        $pdo->commit();
    } catch (Exception $e) {
        // An error occurred, rollback any changes made during this transaction
        $pdo->rollBack();
        throw $e;
    }
}

