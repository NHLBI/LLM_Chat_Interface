<?php
// db.php

function get_connection() {
    global $config;
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
    return $pdo;
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
function create_chat($user, $title, $summary, $deployment) {
    global $pdo;
    $guid = createGUID();
    $guid = str_replace('-','',$guid);
    $temperature= $_SESSION['temperature'];
    $stmt = $pdo->prepare("INSERT INTO chat (id, user, title, summary, deployment, temperature, timestamp) VALUES (:id, :user, :title, :summary, :deployment, :temperature, NOW())");
    $stmt->execute(['id' => $guid, 'user' => $user, 'title' => substr($title,0,254), 'summary' => $summary, 'deployment' => $deployment, 'temperature'=>$temperature]);
    return $guid;
    #return $pdo->lastInsertId();
}

// Function to get token count using token_counter.py
function get_token_count($text, $encoding_name = "cl100k_base") {
    // Define a maximum chunk size to avoid issues with very large strings
    $max_chunk_size = 100000; // Adjust this based on your environment

    // If the text is smaller than the max chunk size, process it directly
    if (strlen($text) <= $max_chunk_size) {
        return call_token_counter($text, $encoding_name);
    }

    // Split the text into chunks and process each chunk separately
    $chunks = str_split($text, $max_chunk_size);
    $total_tokens = 0;

    foreach ($chunks as $chunk) {
        $total_tokens += call_token_counter($chunk, $encoding_name);
    }

    return $total_tokens;
}

// Helper function to call the token_counter.py script
function call_token_counter($text, $encoding_name) {
    // Escape arguments to prevent command injection
    $escaped_text = escapeshellarg($text);
    $escaped_encoding = escapeshellarg($encoding_name);

    $command = "python3 token_counter.py text $escaped_encoding $escaped_text";
    $output = shell_exec($command);

    // Remove any whitespace or newlines from the output
    $token_count = intval(trim($output));

    return $token_count;
}

/**
 * Create a new exchange in the database with the given chat ID, prompt, and reply.
 *
 * Optionally pass document metadata and image generation filename.
 */
function create_exchange(
    $chat_id,
    $prompt,
    $reply,
    $document_name = null,
    $document_type = null,
    $document_text = null,
    $image_gen_name = null
) {
    global $pdo, $config;

    $deployment   = $_SESSION['deployment'] ?? null;
    $temperature  = $_SESSION['temperature'] ?? null;
    $api_endpoint = $_SESSION['api_endpoint'] ?? null;
    $uri          = $_SERVER['HTTP_REFERER'] ?? '';
    $user         = $_SESSION['user_data']['userid'] ?? null;

    // Step 1: Calculate token lengths
    $prompt_token_length = get_token_count($prompt);
    $reply_token_length  = get_token_count($reply);

    // Step 2: Insert record into exchange table
    $stmt = $pdo->prepare("
        INSERT INTO exchange 
        (
            chat_id, user, deployment, api_endpoint, temperature, uri,
            prompt, prompt_token_length, 
            reply, reply_token_length, 
            document_name, document_type, document_text, image_gen_name,
            timestamp
        )
        VALUES 
        (
            :chat_id, :user, :deployment, :api_endpoint, :temperature, :uri,
            :prompt, :prompt_token_length,
            :reply, :reply_token_length,
            :document_name, :document_type, :document_text, :image_gen_name,
            NOW()
        )
    ");

    $stmt->execute([
        'chat_id'             => $chat_id,
        'user'                => $user,
        'deployment'          => $deployment,
        'api_endpoint'        => $api_endpoint,
        'temperature'         => $temperature,
        'uri'                 => $uri,
        'prompt'              => $prompt,
        'prompt_token_length' => $prompt_token_length,
        'reply'               => $reply,
        'reply_token_length'  => $reply_token_length,
        'document_name'       => $document_name,
        'document_type'       => $document_type,
        'document_text'       => $document_text,
        'image_gen_name'      => $image_gen_name
    ]);

    $insert_id = $pdo->lastInsertId();

    if ($config[$deployment]['handles_images']) {
        // Step 3: Check for associated documents (deleted = 0) for the chat_id
        $stmt = $pdo->prepare("SELECT id FROM document WHERE chat_id = :chat_id AND deleted = 0");
        $stmt->execute(['chat_id' => $chat_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($documents) {
            foreach ($documents as $doc) {
                $stmtJoin = $pdo->prepare("
                    INSERT INTO exchange_document (exchange_id, document_id)
                    VALUES (:exchange_id, :document_id)
                ");
                $stmtJoin->execute([
                    'exchange_id' => $insert_id,
                    'document_id' => $doc['id']
                ]);
            }
        }
    }

    // Step 4: Update chat timestamp
    $stmt = $pdo->prepare("UPDATE chat SET timestamp = NOW() WHERE id = :id");
    $stmt->execute(['id' => $chat_id]);

    return $insert_id;
}

function get_image_data($eid) {
    global $pdo;
    if (empty($eid)) return false;
    
    $stmt = $pdo->prepare("SELECT image_lg FROM exchange WHERE id = :id");
    $stmt->execute(['id' => $eid]);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $result[0]['image_lg'];
}

// Update the last_viewed field for a given chat ID
function update_last_viewed($chat_id) {
    global $pdo;
    
    $sql = "UPDATE `chat` 
            SET `last_viewed` = CURRENT_TIMESTAMP, 
                `timestamp` = `timestamp`
            WHERE `id` = :chat_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['chat_id' => $chat_id]);
    
    if ($stmt->rowCount() > 0) {
        return true; // Indicates that the update was successful
    } else {
        return false; // Indicates that no rows were updated
    }
}


// Get all exchanges for a given chat ID from the database, ordered by timestamp
function get_all_exchanges($chat_id, $user) {
    #echo "in get_all_exchanges\n";
    global $pdo;
    //*
    $sql = "SELECT 
        e.id,
        e.chat_id,
        e.user,
        e.prompt,
        e.prompt_token_length,
        e.reply,
        e.reply_token_length,
        e.image_gen_name,
        e.deployment,
        e.api_key,
        e.temperature,
        e.uri,
        e.api_endpoint,
        e.deleted,
        e.timestamp,
        d.id AS `document_id`,
        d.name AS `document_name`,
        d.type AS `document_type`,
        d.content AS `document_text`,
        d.source AS `document_source`

        FROM exchange AS e 
        JOIN chat AS c ON c.id = e.chat_id 
        LEFT JOIN exchange_document AS ed ON e.id = ed.exchange_id
        LEFT JOIN document AS d ON d.id = ed.document_id
        WHERE c.user = :user AND e.chat_id = :chat_id
        AND c.deleted = 0
        AND e.deleted = 0
        AND e.prompt IS NOT NULl
        AND e.reply IS NOT NULL
        ORDER BY e.timestamp ASC
        ";

    #echo $sql . "\n";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['chat_id' => $chat_id, 'user' => $user]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output = [];
    foreach($rows as $r) {
        // Decode HTML entities for the title
        $output[$r['id']]['id']                  = $r['id'];
        $output[$r['id']]['chat_id']             = $r['chat_id'];
        $output[$r['id']]['user']                = $r['user'];
        $output[$r['id']]['prompt']              = $r['prompt'];
        $output[$r['id']]['prompt_token_length'] = $r['prompt_token_length'];
        $output[$r['id']]['reply']               = $r['reply'];
        $output[$r['id']]['reply_token_length']  = $r['reply_token_length'];
        $output[$r['id']]['image_gen_name']      = $r['image_gen_name'];
        $output[$r['id']]['deployment']          = $r['deployment'];
        $output[$r['id']]['api_key']             = $r['api_key'];
        $output[$r['id']]['temperature']         = $r['temperature'];
        $output[$r['id']]['uri']                 = $r['uri'];
        $output[$r['id']]['api_endpoint']        = $r['api_endpoint'];
        $output[$r['id']]['deleted']             = $r['deleted'];
        $output[$r['id']]['timestamp']           = $r['timestamp'];
        
        if (empty($output[$r['id']]['document'])) $output[$r['id']]['document'] = array();

        if (empty($output[$r['id']]['document'])) $output[$r['id']]['document'] = array();

        if (strstr($r['document_type'],'image')) {
            /**/
            $output[$r['id']]['document'][$r['document_id']]['document_name'] = html_entity_decode($r['document_name'], ENT_QUOTES, 'UTF-8');
            $output[$r['id']]['document'][$r['document_id']]['document_type'] = $r['document_type'];
            $output[$r['id']]['document'][$r['document_id']]['document_text'] = $r['document_text'];
            /**/
        }
    }
    #echo '<pre>'.print_r($output,1).'<pre>'; die();
    return $output;
}

function get_all_chats($user, $search = '') {
    global $pdo;

    // Base SQL query
    $sql = "
    SELECT
        c.id, c.user, c.title, c.deployment, c.temperature,
        c.new_title, d.id AS `document_id`, d.name AS `document_name`, d.type AS `document_type`, d.deleted AS `document_deleted`, c.deleted, c.timestamp AS latest_interaction
    FROM chat c
    LEFT JOIN document d ON c.id = d.chat_id
    LEFT JOIN exchange e ON c.id = e.chat_id
    WHERE
        c.user = :user
        AND c.deleted = 0
    ";

    // If a search string is provided, add conditions for title
    if (!empty($search)) {
        $sql .= " AND (c.title LIKE :search OR e.prompt LIKE :search OR e.reply LIKE :search )";
    }

    $sql .= "GROUP BY c.id, d.id ORDER BY c.timestamp DESC, c.id, d.id";

    #echo $sql . "\n";
    $stmt = $pdo->prepare($sql);

    // Bind parameters
    $params = ['user' => $user];

    if (!empty($search)) {
        $params['search'] = '%' . $search . '%';
    }

    /*
    NEED TO GET THE IMAGES BACK TO THE EXCHANGES
    */

    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    #echo $sql . "<br>\n";
    #echo '<pre>'.print_r($rows,1).'<pre>'; die();
    $output = [];
    foreach($rows as $r) {
        // Decode HTML entities for the title
        $r['title'] = html_entity_decode($r['title'], ENT_QUOTES, 'UTF-8');
        #$output[$r['id']][] = $r;
        $output[$r['id']]['id'] = $r['id'];
        $output[$r['id']]['user'] = $r['user'];
        $output[$r['id']]['title'] = $r['title'];
        $output[$r['id']]['deployment'] = $r['deployment'];
        if (empty($output[$r['id']]['document'])) $output[$r['id']]['document'] = array();
        if ($r['document_deleted'] == 0 && !empty($r['document_name'])) {
            $output[$r['id']]['document'][$r['document_id']] = array('name'=>$r['document_name'],'type'=>$r['document_type']);
        }
    }
    #echo '<pre>'.print_r($output,1).'<pre>'; die();
    return $output;
}

/**
 * Retrieve all documents associated with a specific chat ID for a given user.
 *
 * This function joins the `chat` and `document` tables to fetch all documents
 * related to the given chat, ensuring the chat belongs to the specified user.
 * It returns an array containing document details such as name, content, and type.
 *
 * @param string $user    The user ID to verify ownership of the chat.
 * @param string $chat_id The unique ID of the chat whose documents are requested.
 *
 * @return array An array of documents, each containing:
 *               - document_id (int)    : The unique ID of the document.
 *               - document_name (string): The name of the document.
 *               - document_content (string): The full content of the document.
 *               - document_type (string) : The type of document (e.g., 'pdf', 'txt').
 */
function get_chat_documents($user, $chat_id, $images_only = false) {
    global $pdo;

    $restrict_to_images = '';
    if ($images_only) $restrict_to_images = 'AND d.type LIKE "%image%"';

    $sql = "
        SELECT 
            d.id       AS document_id,
            d.name     AS document_name,
            d.content  AS document_content,
            d.type     AS document_type
        FROM chat c
        LEFT JOIN document d 
               ON c.id = d.chat_id
        WHERE c.user    = :user
          AND c.id      = :chat_id
          AND c.deleted = 0
          AND d.deleted = 0
          {$restrict_to_images}
        ORDER BY d.id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'user' => $user,
        'chat_id' => $chat_id,
    ]);
    #echo $sql . "\n";
    #echo "User: {$user}\n";
    #echo "Chat ID: {$chat_id}\n";

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set deployment = :deployment where id = :id");
    $stmt->execute(['deployment' => $deployment, 'id' => $chat_id]);
}

// Update the chat title in the database
function update_chat_title($user, $chat_id, $updated_title) {
    global $pdo;

    // Prepare a SQL statement to update the title
    $stmt = $pdo->prepare("UPDATE chat SET title = :title, new_title = :new_title WHERE id = :id");
    $stmt->execute(['title' => substr($updated_title,0,254), 'new_title' => '0', 'id' => $chat_id]);
}

// Update the temperature in the chat table
function update_temperature($user, $chat_id, $temperature) {
    global $pdo;

    // prepare a sql statement to update the deployment of a chat where the id matches the $chat_id
    $stmt = $pdo->prepare("update chat set temperature = :temperature where id = :id");
    $stmt->execute(['temperature' => $temperature, 'id' => $chat_id]);
}

/**
 * Insert a document record into the document table.
 */
function insert_document($user, $chat_id, $document_name, $document_type, $document_text) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO document (chat_id, name, type, content) VALUES (:chat_id, :name, :type, :content)");
    $stmt->execute([
        'chat_id' => $chat_id,
        'name'    => $document_name,
        'type'    => $document_type,
        'content' => $document_text
    ]);
}

/**
 * Delete a document from the `document` table,
 * ensuring that the user owns the chat in question.
 *
 * @param string $user The user ID from session
 * @param string $chatId The chat's unique ID (32-char hex)
 * @param int    $docId The integer PK for the document
 * @return bool  True on success, False otherwise
 */
function delete_document($user, $chatId, $docId) {
    global $pdo;

    // 1) Ensure the chat belongs to this user.
    //    The `chat` table presumably has a column with the user's ID,
    //    e.g. `owner` or something. Adjust as needed:
    $checkChatSql = "SELECT COUNT(*) 
                      FROM chat
                      WHERE id = :chatId
                        AND user = :user "; // or whichever column holds user ID
    $checkChatStmt = $pdo->prepare($checkChatSql);
    $checkChatStmt->execute([':chatId' => $chatId, ':user' => $user]);
    $count = $checkChatStmt->fetchColumn();

    if ($count < 1) {
        // This means no chat belongs to user -> not allowed.
        return false;
    }

    // 2) Delete (or soft-delete) the row in `document` table
    //    either physically remove it:
    //$sql = "DELETE FROM document
    //         WHERE id = :docId
    //           AND chat_id = :chatId 
    //         LIMIT 1";
    //  or if you want soft-delete:
    $sql = "UPDATE document
              SET deleted = 1
              WHERE id = :docId
              AND chat_id = :chatId
              LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':docId', $docId, PDO::PARAM_INT);
    $stmt->bindValue(':chatId', $chatId, PDO::PARAM_STR);
    $stmt->execute();

    return ($stmt->rowCount() > 0);
}

// Delete all chats where last_viewed is 6 months old or more and deleted = 1
function delete_old_chats($months = 6) {
    global $pdo;
    
    // Calculate the date that is $months months ago
    $date_threshold = (new DateTime())->modify("-{$months} months")->format('Y-m-d H:i:s');
    
    $sql = "DELETE FROM `chat` 
            WHERE `timestamp` <= :date_threshold
            AND `user` = 'wyrickrv'
            AND `deleted` = 1";

    #echo $sql . "\n";
    #echo $date_threshold . "\n";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['date_threshold' => $date_threshold]);
        
        // Check how many rows were deleted
        $rowCount = $stmt->rowCount();
        
        if ($rowCount > 0) {
            return $rowCount; // Return the number of deleted rows
        } else {
            return 0; // No rows were deleted
        }
    } catch (PDOException $e) {
        error_log("Failed to delete old chats: " . $e->getMessage(), 0);
        return false;
    }
}

// Delete all chats where last_viewed is 6 months old or more and deleted = 1
function soft_delete_old_chats($months = 6) {
    global $pdo;
    
    // Calculate the date that is $months months ago
    $date_threshold = (new DateTime())->modify("-{$months} months")->format('Y-m-d H:i:s');
    
    $sql = "UPDATE `chat`
            SET `deleted` = 1,
                `timestamp` = `timestamp`
            WHERE `last_viewed` <= :date_threshold
            AND `user` = 'wyrickrv'
            AND `deleted` = 0";

    #echo $sql . "\n";
    #echo $date_threshold . "\n";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['date_threshold' => $date_threshold]);
        
        // Check how many rows were deleted
        $rowCount = $stmt->rowCount();
        
        if ($rowCount > 0) {
            return $rowCount; // Return the number of deleted rows
        } else {
            return 0; // No rows were deleted
        }
    } catch (PDOException $e) {
        error_log("Failed to delete old chats: " . $e->getMessage(), 0);
        return false;
    }
}

