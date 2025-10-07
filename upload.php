<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include necessary libraries
require_once 'bootstrap.php';

// Ensure $user and $config_file are available
$user = $_SESSION['user_data']['userid'] ?? '';
if (!isset($config_file) || !$config_file) {
    $config_file = '/etc/apps/chatdev_config.ini';
}

// Get the chat_id if present
$chat_id = isset($_REQUEST['chat_id']) ? $_REQUEST['chat_id'] : '';

// Flag to indicate a new chat was created
$new_chat_created = false;

// Create a new chat session if no chat ID is provided
if (empty($chat_id)) {
    $chat_id = create_chat($user, 'New auto-generated Chat', '', $_SESSION['deployment']);
    $new_chat_created = true;
}

// Check if there's a request to remove the uploaded document(s)
if (isset($_GET['remove']) && $_GET['remove'] == '1') {
    remove_chat_documents($user, $chat_id);
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'redirect' => true]);
        exit;
    }
    header('Location: ' . urlencode($chat_id));
    exit;
}

if (!empty($_REQUEST['selected_workflow'])) {
    $workflow = json_decode($_REQUEST['selected_workflow'], true);
    if (!empty($workflow['configLabel'])) {
        $configLabels = explode(',', $workflow['configLabel']);
        $configDescriptions = explode(',', $workflow['configDescription']);
        for ($i = 0; $i < count($configLabels); $i++) {
            $workflow_config[$configLabels[$i]] = $configDescriptions[$i];
        }
    }
}

// === Workspace strictly under web root ===
$ragPaths     = rag_workspace_paths($config ?? null);
$workRoot     = $ragPaths['root'];
$parsedDir    = $ragPaths['parsed'];
$queueDir     = $ragPaths['queue'];
$logsDir      = $ragPaths['logs'];
$completedDir = $ragPaths['completed'];
$failedDir    = $ragPaths['failed'];

$dirs = [$workRoot, $parsedDir, $queueDir, $logsDir, $completedDir, $failedDir];
foreach ($dirs as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    @chmod($d, 0775);
    if (!is_writable($d)) {
        error_log("RAG workspace not writable: $d");
    }
}

// === Tooling paths (under your app) ===
$python  = rag_python_binary($config ?? null);
$parser  = rag_parser_script($config ?? null);
$indexer = rag_indexer_script($config ?? null);

// Add validation for required files
if (!file_exists($python)) {
    error_log("Python executable not found: $python");
}
if (!file_exists($parser)) {
    error_log("Parser script not found: $parser");
}
if (!file_exists($indexer)) {
    error_log("Indexer script not found: $indexer");
}

if (isset($_FILES['uploadDocument'])) {
    $uploadedDocuments = [];
    $queuedDocuments   = [];
    $fileCount = count($_FILES['uploadDocument']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['uploadDocument']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName       = $_FILES['uploadDocument']['tmp_name'][$i];
        $originalName  = basename($_FILES['uploadDocument']['name'][$i]);
        $mimeType      = mime_content_type($tmpName);
        $originalSize  = @filesize($tmpName);

        // ===== IMAGES: store as before; NO indexing =====
        if (strpos($mimeType, 'image/') === 0) {
            $base64Image = local_image_to_data_url($tmpName, $mimeType);
            $document_id = insert_document($user, $chat_id, $originalName, $mimeType, $base64Image);
            @unlink($tmpName);
            $uploadedDocuments[] = [
                'id'                => (int)$document_id,
                'name'              => $originalName,
                'type'              => $mimeType,
                'queued'            => false,
                'original_size'     => $originalSize !== false ? (int)$originalSize : null,
                'parsed_size'       => null,
            ];
            continue;
        }

        // ===== DOCS: parse to a file under ./var/rag/parsed =====
        $txtPath  = $parsedDir . '/rag_' . uniqid('', true) . '.txt';
        $parseLog = $logsDir   . '/parse_' . time() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName) . '.log';

        // Ensure the upload temp is readable
        @chmod($tmpName, 0640);

        // Run parser once, streaming stdout directly to disk so large documents don't exhaust PHP memory
        $parseCmd = sprintf('%s %s %s %s',
            escapeshellarg($python),
            escapeshellarg($parser),
            escapeshellarg($tmpName),
            escapeshellarg($originalName)
        );
        error_log("PARSE CMD: $parseCmd");

        $parseHandle = fopen($txtPath, 'w');
        if ($parseHandle === false) {
            error_log("Unable to open parsed output path: {$txtPath}");
            @unlink($tmpName);
            continue;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $parsePipes = [];
        $parseProc = proc_open($parseCmd, $descriptorSpec, $parsePipes, __DIR__);
        if (!is_resource($parseProc)) {
            fclose($parseHandle);
            error_log("Failed to start parser process for {$originalName}");
            @unlink($tmpName);
            continue;
        }

        fclose($parsePipes[0]);           // no stdin
        stream_set_blocking($parsePipes[1], true);
        stream_set_blocking($parsePipes[2], true);

        $preview     = '';
        $maxPreview  = 800;
        $stdout      = $parsePipes[1];
        while (!feof($stdout)) {
            $chunk = fread($stdout, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                continue;
            }
            fwrite($parseHandle, $chunk);
            if (strlen($preview) < $maxPreview) {
                $preview .= $chunk;
                if (strlen($preview) > $maxPreview) {
                    $preview = substr($preview, 0, $maxPreview);
                }
            }
        }

        $stderrOutput = stream_get_contents($parsePipes[2]);

        fclose($stdout);
        fclose($parsePipes[2]);
        fclose($parseHandle);

        $rc = proc_close($parseProc);
        error_log("PARSE RC: $rc");
        if ($stderrOutput !== false && $stderrOutput !== '') {
            error_log("PARSE STDERR: " . substr($stderrOutput, 0, 400));
        }
        if ($preview !== '') {
            error_log("PARSE OUT (first 800): " . $preview);
        }

        if ($rc !== 0) {
            error_log("Parser failed for {$originalName} rc={$rc}");
            @unlink($tmpName);
            continue;
        }

        if (!is_file($txtPath) || filesize($txtPath) === 0) {
            error_log("Parser produced no output file for {$originalName}");
            @unlink($tmpName);
            continue;
        }

        // Store parsed text in DB (but avoid loading extremely large payloads fully into memory)
        $parsedSize      = filesize($txtPath);
        $maxDbBytes      = 2 * 1024 * 1024; // 2 MB cap for DB storage
        $parsedText      = '';
        $insertOptions   = [];

        if ($parsedSize === false) {
            $parsedSize = 0;
        }

        if ($parsedSize > $maxDbBytes) {
            $fh = fopen($txtPath, 'r');
            if ($fh !== false) {
                $parsedText = stream_get_contents($fh, $maxDbBytes);
                fclose($fh);
            }
            if ($parsedText === false || $parsedText === null) {
                $parsedText = '';
            }
            $parsedText .= "\n\n[Content truncated for preview; full text indexed via RAG.]";
            $insertOptions = ['compute_tokens' => false, 'token_length' => 0];
            error_log("Parsed output truncated for database storage (size={$parsedSize} bytes)");
        } else {
            $parsedText = @file_get_contents($txtPath);
            if ($parsedText === false) { $parsedText = ''; }
        }

        // Create the document row and update file_sha256
        $document_id = insert_document($user, $chat_id, $originalName, $mimeType, $parsedText, $insertOptions);

        try {
            $file_sha256 = hash_file('sha256', $tmpName);
            if ($file_sha256) {
                $stmt = $pdo->prepare("UPDATE document SET file_sha256 = :sha WHERE id = :id");
                $stmt->execute(['sha' => $file_sha256, 'id' => $document_id]);
            }
        } catch (Throwable $e) {
            error_log("Failed to set file_sha256 for document_id {$document_id}: ".$e->getMessage());
        }

        $parsedSizeBytes = @filesize($txtPath);

        // ===== Queue the RAG index =====
        $payload = [
            'document_id'     => (int)$document_id,
            'chat_id'         => $chat_id,
            'user'            => $user,
            'embedding_model' => "NHLBI-Chat-workflow-text-embedding-3-large",
            'config_path'     => $config_file,
            'file_path'       => $txtPath,
            'filename'        => $originalName,
            'mime'            => $mimeType,
            'cleanup_tmp'     => false,
            'original_size_bytes' => $originalSize !== false ? (int)$originalSize : null,
            'parsed_size_bytes'   => $parsedSizeBytes !== false ? (int)$parsedSizeBytes : null,
            'queue_timestamp'     => time(),
        ];

        $jobPath = $queueDir . '/job_' . uniqid('', true) . '.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $queued = false;
        if ($encoded === false || file_put_contents($jobPath, $encoded) === false) {
            error_log("Failed to enqueue RAG job for document_id {$document_id}");
        } else {
            error_log("Queued RAG indexing job for document_id {$document_id}: {$jobPath}");
            $queued = true;
            $queuedDocuments[] = (int)$document_id;
        }

        $uploadedDocuments[] = [
            'id'                => (int)$document_id,
            'name'              => $originalName,
            'type'              => $mimeType,
            'queued'            => $queued,
            'original_size'     => $originalSize !== false ? (int)$originalSize : null,
            'parsed_size'       => $parsedSizeBytes !== false ? (int)$parsedSizeBytes : null,
        ];

        // Upload temp file is no longer needed once parsed
        @unlink($tmpName);

        continue;
    }

    // Use JSON response for AJAX requests, else do a header redirect
    if (isAjaxRequest()) {
        echo json_encode([
            'chat_id'              => $chat_id,
            'new_chat'             => $new_chat_created,
            'uploaded_documents'   => $uploadedDocuments ?? [],
            'processing_documents' => $queuedDocuments ?? [],
        ]);
    } else {
        header('Location: ' . urlencode($chat_id));
    }
} else {
    if (isAjaxRequest()) {
        echo json_encode([
            'chat_id'              => $chat_id,
            'new_chat'             => false,
            'uploaded_documents'   => [],
            'processing_documents' => [],
        ]);
    } else {
        header('Location: ' . urlencode($chat_id));
    }
}

exit();

/**
 * Helper function to detect AJAX requests.
 */
function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
