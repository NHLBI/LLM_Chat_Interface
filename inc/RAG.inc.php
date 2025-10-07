<?php

# RAG TOOLS

/**
 * Helper function to call the Python RAG script.
 *
 * @param string $user_question The question to pass to the script.
 * @return array Decoded JSON output from the script (e.g., ['augmented_prompt' => ...] or ['error' => ...]).
 */
function call_rag_script($user_question) {
    global $config; // Access global config for paths

    // --- Retrieve Paths from Config (Ensure these are set!) ---
    $python_executable = dirname(__DIR__).'/rag310/bin/python3';
    $script_path = __DIR__.'/rag_processor.py';

    if (!$script_path || !file_exists($script_path)) {
        return ['error' => 'RAG script path not configured or not found. Path: ' . $script_path];
    }
    if (!is_executable($python_executable) && !preg_match('/^python[3]?$/', $python_executable)) {
         // Basic check if it's not 'python'/'python3' and not executable directly
         // A more robust check might involve `shell_exec("command -v $python_executable")`
         // but let's keep it simple for now. Adjust if needed.
        // return ['error' => 'Python executable not found or not executable: ' . $python_executable];
        // Allow 'python3' assuming it's in PATH
    }


    // --- Prepare Command ---
    // Use escapeshellarg to safely pass the user question
    $escaped_question = escapeshellarg($user_question);
    $command = $python_executable . ' ' . escapeshellarg($script_path) . ' ' . $escaped_question . ' 2>&1'; // Redirect stderr to stdout

    // --- Execute Command ---
    // Consider adding timeout logic if the script might hang
    $output = [];
    $return_var = -1;
    exec($command, $output, $return_var);

    $raw_output = implode("\n", $output); // Combine output lines

    // --- Process Output ---
    if ($return_var !== 0) {
        // Script exited with an error code
        return ['error' => "RAG script execution failed (Exit Code: $return_var). Output: " . $raw_output];
    }

    // Attempt to decode JSON output
    $json_result = json_decode($raw_output, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Output was not valid JSON
        return ['error' => "Failed to decode JSON response from RAG script. Raw output: " . $raw_output];
    }

    // Return the decoded JSON (should contain 'augmented_prompt' or 'error')
    return $json_result;
}

function run_rag($question, $chat_id, $user, $config_path, $timeoutSec = 20) {
    $payload = [
        'question' => $question,
        'chat_id'  => $chat_id,
        'user'     => $user,
        'top_k'    => 12,
        'max_context_tokens' => 50000,
        'config_path' => $config_path,
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'ragq_').'.json';
    file_put_contents($tmp, json_encode($payload));

    $python  = dirname(__DIR__).'/rag310/bin/python3';
    $script  = __DIR__.'/rag_retrieve.py';
    $timeout = '/usr/bin/timeout';
    $errFile = sys_get_temp_dir().'/rag_retrieve_'.getmypid().'.err';

    $cmd = escapeshellarg($timeout).' '.((int)$timeoutSec).' '
         . escapeshellarg($python).' '.escapeshellarg($script)
         .' --json '.escapeshellarg($tmp)
         .' 2>'.escapeshellarg($errFile);

    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    @unlink($tmp);

    $raw = implode("\n", $out);
    $jr  = json_decode($raw, true);

    return [
        'rc'      => $rc,
        'cmd'     => $cmd,
        'stdout'  => $raw,
        'stderr'  => (is_file($errFile) ? substr(@file_get_contents($errFile), 0, 4000) : ''),
        'json'    => $jr,
        'payload' => $payload,
    ];
}


/** THIS IS GOING AWAY IF WE USE RAG
 * Given an array of doc rows and the current user message,
 * return an array of “messages” that inject each document,
 * splitting any long text docs into parts under the Azure limit.
 */
function format_document_messages(array $docs, string $userMessage): array {
    $messages = [];
    $maxLength = 256000;

    foreach ($docs as $i => $doc) {
        $baseName = $doc['document_name'];
        $content  = $doc['document_content'];

        // image files: no splitting
        if (strpos($doc['document_type'], 'image/') === 0) {
            $messages[] = [
                "role"    => "user",
                "content" => "This is document #" . ($i + 1) . ". Filename: " . $baseName
            ];
            $messages[] = [
                "role"    => "user",
                "content" => [
                    ["type" => "text",      "text"      => $userMessage],
                    ["type" => "image_url", "image_url" => ["url" => $content]]
                ]
            ];
            continue;
        }

        // text docs: possibly split into parts
        $length = mb_strlen($content, 'UTF-8');
        if ($length <= $maxLength) {
            // under limit: send as one message
            $messages[] = [
                "role"    => "user",
                "content" => "This is document #" . ($i + 1) . ". Filename: " . $baseName
            ];
            $messages[] = [
                "role"    => "user",
                "content" => $content
            ];
        } else {
            // split into parts
            $parts = [];
            $offset = 0;
            while ($offset < $length) {
                $parts[] = mb_substr($content, $offset, $maxLength, 'UTF-8');
                $offset += $maxLength;
            }
            $totalParts = count($parts);

            foreach ($parts as $k => $partContent) {
                $partNum = $k + 1;
                $messages[] = [
                    "role"    => "user",
                    "content" => sprintf(
                        "This is document #%d. Filename: %s (part %d of %d)",
                        $i + 1,
                        $baseName,
                        $partNum,
                        $totalParts
                    )
                ];
                $messages[] = [
                    "role"    => "user",
                    "content" => $partContent
                ];
            }
        }
    }

    return $messages;
}
