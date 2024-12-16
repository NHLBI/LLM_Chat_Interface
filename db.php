<?php
// db.php

// Parse the configuration file
require_once 'get_config.php';

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
    $temperature= $_SESSION['temperature'];
    $stmt = $pdo->prepare("INSERT INTO chat (id, user, title, summary, deployment, temperature, document_name, document_text, timestamp) VALUES (:id, :user, :title, :summary, :deployment, :temperature, :document_name, :document_text, NOW())");
    $stmt->execute(['id' => $guid, 'user' => $user, 'title' => substr($title,0,254), 'summary' => $summary, 'deployment' => $deployment, 'temperature'=>$temperature, 'document_name' => $document_name, 'document_text' => $document_text]);
    return $guid;
    #return $pdo->lastInsertId();
}

// Create a new exchange in the database with the given chat ID, prompt, and reply
function create_exchange($chat_id, $prompt, $reply) {
    global $pdo;
    $deployment = $_SESSION['deployment'] ?? null;
    $temperature = $_SESSION['temperature'] ?? null;
    $api_endpoint = $_SESSION['api_endpoint'] ?? null;
    $uri = $_SERVER['HTTP_REFERER'];
    
    // Retrieve document information from the chat table
    $document_status = get_uploaded_image_status($chat_id);
    #error_log('Database test: ' . print_r($document_status,1));
    
    // Initialize document details
    $document_name = null;
    $document_type = null;
    $document_text = null;

    // Check if the document is an image
    if (is_array($document_status) && isset($document_status['document_type'])) {
        $mime_type = $document_status['document_type'];
        $allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff'];

        if (in_array($mime_type, $allowed_mime_types)) {
            $document_name = $document_status['document_name'] ?? null;
            $document_type = $document_status['document_type'] ?? null;
            $document_text = $document_status['document_text'] ?? null;
        }
    }

    // Prepare the INSERT statement with placeholders for document details
    $stmt = $pdo->prepare("
        INSERT INTO exchange (chat_id, deployment, api_endpoint, temperature, uri, prompt, reply, document_name, document_type, document_text, timestamp)
        VALUES (:chat_id, :deployment, :api_endpoint, :temperature, :uri, :prompt, :reply, :document_name, :document_type, :document_text, NOW())
    ");
    
    // Bind the parameters
    $stmt->execute([
        'chat_id' => $chat_id,
        'deployment' => $deployment,
        'api_endpoint' => $api_endpoint,
        'temperature' => $temperature,
        'uri' => $uri,
        'prompt' => $prompt,
        'reply' => $reply,
        'document_name' => $document_name,
        'document_type' => $document_type,
        'document_text' => $document_text
    ]);
    
    $insert_id = $pdo->lastInsertId();

    $stmt = $pdo->prepare("UPDATE chat SET timestamp = NOW() WHERE id = :id");
    $stmt->execute(['id' => $chat_id]);

    return $insert_id;

}

function get_uploaded_image_status($chat_id) {
    global $pdo;
    #echo "working here in get_uploaded<br>\n";
    if (empty($chat_id)) return false;
    
    $sql = "SELECT document_name, document_type, document_text FROM chat WHERE id = :chat_id LIMIT 1";
    #echo $sql . "\n";
    $stmt = $pdo->prepare($sql);
    #$stmt = $pdo->prepare("SELECT document_name, document_type, document_text FROM chat WHERE id = :chat_id LIMIT 1");
    $stmt->execute(['chat_id' => $chat_id]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result[0];
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

function get_all_chats($user, $search = '') {
    global $pdo;

    // Base SQL query
    $sql = "
    SELECT
        c.id, c.user, c.title, c.deployment, c.temperature,
        c.new_title, c.document_name, c.deleted, c.timestamp AS latest_interaction
    FROM chat c
    LEFT JOIN exchange e ON c.id = e.chat_id
    WHERE
        c.user = :user
        AND c.deleted = 0
    ";

    // If a search string is provided, add conditions for title
    if (!empty($search)) {
        $sql .= " AND (c.title LIKE :search OR e.prompt LIKE :search OR e.reply LIKE :search )";
    }

    $sql .= " ORDER BY c.timestamp DESC";

    $stmt = $pdo->prepare($sql);

    // Bind parameters
    $params = ['user' => $user];

    if (!empty($search)) {
        $params['search'] = '%' . $search . '%';
    }

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output = [];
    foreach($rows as $r) {
        // Decode HTML entities for the title
        $r['title'] = html_entity_decode($r['title'], ENT_QUOTES, 'UTF-8');
        $output[$r['id']] = $r;
    }
    return $output;
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

// 
function get_new_title_status($user, $chat_id) {
    global $pdo;
    if (empty($chat_id)) return false;
    
    $stmt = $pdo->prepare("SELECT new_title FROM chat WHERE user = :user AND id = :chat_id");
    $stmt->execute(['chat_id' => $chat_id, 'user' => $user]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result[0]['new_title'];
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

// Update the chat title in the database
function update_chat_title($user, $chat_id, $updated_title) {
    global $pdo;

    if (!verify_user_chat($user, $chat_id)) {
        die("Unauthorized access.");
    }

    // Prepare a SQL statement to update the title
    $stmt = $pdo->prepare("UPDATE chat SET title = :title, new_title = :new_title WHERE id = :id");
    $stmt->execute(['title' => substr($updated_title,0,254), 'new_title' => '0', 'id' => $chat_id]);
}

// Update the temperature in the chat table
function update_temperature($user, $chat_id, $temperature) {
    global $pdo;

    if (!verify_user_chat($user, $chat_id)) {
        die("unauthorized");
    }
    
    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set temperature = :temperature where id = :id");
    $stmt->execute(['temperature' => $temperature, 'id' => $chat_id]);
}

// Update the document in the database
function update_chat_document($user, $chat_id, $document_name, $document_type, $document_text) {
    global $pdo;

    if (!verify_user_chat($user, $chat_id)) {
        die("unauthorized");
    }
    
    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set document_name = :document_name, document_type = :document_type, document_text = :document_text where id = :id");
    $stmt->execute(['document_name' => $document_name, 'document_type' => $document_type, 'document_text' => $document_text, 'id' => $chat_id]);
}


