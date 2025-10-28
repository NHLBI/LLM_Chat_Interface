<?php

# WORKFLOWS

function get_workflow_thread($message, $chat_id, $user, $active_config, $custom_config) {

    #$custom_config = json_decode($custom_config,1);
    #print_r($custom_config); die();
    if (empty($custom_config['workflowId'])) $custom_config['workflowId'] = '';

    // Build the system message
    $workflow_data = get_workflow_data($custom_config['workflowId']);
    $resource = $workflow_data['content'];
    $message = $workflow_data['prompt'];

    if (!empty($workflow_data['deployment'])) {
        $deployment = $workflow_data['deployment'];
        $active_config = load_configuration($deployment);
    }

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

    return array($active_config, $messages);
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
    $documentStates = [];
    $promptDocumentsForClient = [];

    if (!empty($docs)) {
        $messages = [];
        $index = 0;

        foreach ($docs as $doc) {
            $docId = (int)($doc['document_id'] ?? 0);
            $docEnabled = !(isset($doc['document_enabled']) && (int)$doc['document_enabled'] === 0);
            $documentStates[$docId] = $docEnabled;

            $docType = (string)($doc['document_type'] ?? '');
            $docSource = strtolower((string)($doc['document_source'] ?? $doc['source'] ?? ''));
            $docContent = (string)($doc['document_content'] ?? '');
            $docReady = !empty($doc['document_ready']);
            $docTokens = (int)($doc['document_token_length'] ?? 0);

            if ($docSource === '') {
                if (strpos($docType, 'image/') === 0) {
                    $docSource = 'image';
                } elseif (!empty($doc['full_text_available'])) {
                    $docSource = 'inline';
                } elseif ($docReady) {
                    $docSource = 'rag';
                }
            }

            $promptDoc = [
                'document_id'                 => $docId,
                'document_name'               => (string)($doc['document_name'] ?? ('Document ' . $docId)),
                'document_type'               => $docType,
                'document_source'             => $docSource,
                'source'                      => $docSource,
                'document_ready'              => $docReady ? 1 : 0,
                'document_token_length'       => $docTokens,
                'document_deleted'            => isset($doc['document_deleted']) ? (int)$doc['document_deleted'] : 0,
                'document_full_text_available'=> isset($doc['full_text_available']) ? (int)$doc['full_text_available'] : 0,
                'enabled'                     => (bool)$docEnabled,
                'was_enabled'                 => (bool)$docEnabled,
            ];
            if (strpos($docType, 'image/') === 0 && $docContent !== '') {
                $promptDoc['document_text'] = $docContent;
            }
            $promptDocumentsForClient[] = $promptDoc;

            if (!$docEnabled) {
                continue;
            }

            $index += 1;
            // Add document metadata
            $messages[] = [
                "role" => "user",
                "content" => "This is document #" . $index . ". Its filename is: " . $doc['document_name']
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

        $_SESSION['last_document_snapshot'] = $documentStates;
        $GLOBALS['last_prompt_documents_details'] = $promptDocumentsForClient;

        return $messages;
    }

    $_SESSION['last_document_snapshot'] = $documentStates;
    $GLOBALS['last_prompt_documents_details'] = $promptDocumentsForClient;

    return null; // Explicitly return null if no documents exist
}
