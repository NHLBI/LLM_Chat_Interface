<?php
// db.php

// Parse the configuration file
$fn = '/etc/apps/chat_config.ini';
$config = parse_ini_file($fn,true);
#if (file_exists($fn)) echo "got the file: $fn\n";
#else echo "don't have the file: $fn\n";

#print_r($config);

// Get the database configuration from the config array
$host = $config['database']['host'];
$dbname = $config['database']['dbname'];
$username = $config['database']['username'];
$password = trim($config['database']['password'], '"'); // trim the quotes around the password

#die( "INFO: " . $host . "\n" . $dbname . "\n" . $username . "\n" . $password . "\n\n\n");

try {
    // Connect to the database using PDO (PHP Data Objects)
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Set the PDO error mode to exception to enable error handling
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If the database connection fails, output an error message
    error_log('Database connection failed: ' . $e->getMessage());
    die('Database connection failed. Please contact the site administrator.');
}

function createGUID() {    
    if (function_exists('com_create_guid') === true) { 
        return trim(com_create_guid(), '{}');
    }

    return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', 
        mt_rand(0, 65535), mt_rand(0, 65535), 
        mt_rand(0, 65535), mt_rand(16384, 20479), 
        mt_rand(32768, 49151), mt_rand(0, 65535), 
        mt_rand(0, 65535), mt_rand(0, 65535));
}


// Create a new chat in the database with the given user, title, and summary
function create_chat($user, $title, $summary, $deployment, $document_name, $document_text) {
    global $pdo;
    $guid = createGUID();
    $guid = str_replace('-','',$guid);
    $stmt = $pdo->prepare("INSERT INTO chat (id, user, title, summary, deployment, document_name, document_text, timestamp) VALUES (:id, :user, :title, :summary, :deployment, :document_name, :document_text, NOW())");
    $stmt->execute(['id' => $guid, 'user' => $user, 'title' => $title, 'summary' => $summary, 'deployment' => $deployment, 'document_name' => $document_name, 'document_text' => $document_text]);
    return $guid;
    #return $pdo->lastInsertId();
}

// Create a new exchange in the database with the given chat ID, prompt, and reply
function create_exchange($chat_id, $prompt, $reply) {
    global $pdo;
    $deployment = $_SESSION['deployment'];
    $stmt = $pdo->prepare("INSERT INTO exchange (chat_id, deployment, prompt, reply, timestamp) VALUES (:chat_id, :deployment, :prompt, :reply, NOW())");
    $stmt->execute(['chat_id' => $chat_id, 'deployment' => $deployment, 'prompt' => $prompt, 'reply' => $reply]);
    return $pdo->lastInsertId();
}

// Get all exchanges for a given chat ID from the database, ordered by timestamp
function get_all_exchanges($chat_id, $user) {
    #echo "in get_all_exchanges\n";
    global $pdo;
    //*
    $sql = "SELECT e.* FROM exchange AS e 
        JOIN chat AS c ON c.id = e.chat_id 
        WHERE c.user = :user AND e.chat_id = :chat_id
        AND c.deleted = 0
        AND e.deleted = 0
        AND e.prompt IS NOT NULL
        AND e.reply IS NOT NULL
        ORDER BY e.timestamp ASC
        ";

    #echo $sql . "\n";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['chat_id' => $chat_id, 'user' => $user]);
    $output = $stmt->fetchAll(PDO::FETCH_ASSOC);
    #echo "this is the output: " . print_r($output,1) . "\n";
    return $output;
}

// Get all chats for a given user from the database, ordered by timestamp
function get_all_chats($user) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM chat WHERE user = :user AND deleted = 0 ORDER BY timestamp DESC");
    $stmt->execute(['user' => $user]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output = [];
    foreach($rows as $r) {
        #echo "<pre>".print_r($r,1)."</pre>";
        $output[$r['id']] = $r;
    }
    return $output;
    #return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Verify that there is a chat at this id for this user
function verify_user_chat($user, $chat_id) {
    global $pdo;
    if (empty($chat_id)) return true; 
    
    $stmt = $pdo->prepare("SELECT COUNT(*) AS count FROM chat WHERE user = :user AND id = :chat_id");
    $stmt->execute(['chat_id' => $chat_id, 'user' => $user]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output = ($result[0]['count'] > 0) ? true : false;
    return $output;
}

// Update the deployment in the database
function update_deployment($user, $chat_id, $deployment) {
    global $pdo;

    if (!verify_user_chat($user, $chat_id)) {
        die("unauthorized");
    }
    
    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set deployment = :deployment where id = :id");
    $stmt->execute(['deployment' => $deployment, 'id' => $chat_id]);
}

// Update the document in the database
function update_chat_document($user, $chat_id, $document_name, $document_text) {
    global $pdo;

    if (!verify_user_chat($user, $chat_id)) {
        die("unauthorized");
    }
    
    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set document_name = :document_name, document_text = :document_text where id = :id");
    $stmt->execute(['document_name' => $document_name, 'document_text' => $document_text, 'id' => $chat_id]);
}


