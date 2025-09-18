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
    # print_r($docs);

    if (!empty($docs)) {
        $messages = [];

        foreach ($docs as $i => $doc) {
            // Add document metadata
            $messages[] = [
                "role" => "user",
                "content" => "This is document #" . ($i + 1) . ". Its filename is: " . $doc['document_name']
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

        return $messages;
    }

    return null; // Explicitly return null if no documents exist
}
