<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include necessary libraries
require_once 'bootstrap.php';
require_once __DIR__ . '/inc/rag_processing_status.php';

if (!function_exists('smiles_debug_log')) {
    function smiles_debug_log($message) {
        $logFile = __DIR__ . '/logs/smiles_debug.log';
        $line = date('c') . ' ' . $message . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}

if (!defined('UPLOAD_SHOULD_EXIT')) {
    define('UPLOAD_SHOULD_EXIT', true);
}

if (!function_exists('isAjaxRequest')) {
    function isAjaxRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}

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

    if (json_last_error() === JSON_ERROR_NONE && is_array($workflow)) {
        $_SESSION['selected_workflow'] = json_encode($workflow);
        if (!empty($workflow_config['execution']) && $workflow_config['execution'] === 'auto-prompt-submit') {
            $_SESSION['workflow_auto_prompt'] = true;
        }
    }
}

// === Workspace strictly under web root ===
$ragPaths     = rag_workspace_paths($config ?? null);
$workRoot     = $ragPaths['root'];
$parsedDir     = $ragPaths['parsed'];
$queueDir      = $ragPaths['queue'];
$logsDir       = $ragPaths['logs'];
$completedDir  = $ragPaths['completed'];
$failedDir     = $ragPaths['failed'];
$statusDir     = $ragPaths['status'] ?? ($logsDir . '/status');
$uploadStageDir= $ragPaths['uploads'] ?? ($workRoot . '/uploads');

$dirs = [$workRoot, $parsedDir, $queueDir, $logsDir, $completedDir, $failedDir, $statusDir, $uploadStageDir];
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
    $imageConfig = get_image_processing_config($config ?? []);
    $imageDownsampleOptions = [
        'max_width_px'       => $imageConfig['max_width_px'],
        'max_bytes'          => $imageConfig['max_bytes'],
        'keep_original'      => $imageConfig['keep_original'],
        'min_width'          => 96,
        'original_store_dir' => $workRoot . '/original-images',
    ];

    $smilesGenerated = !empty($_POST['smiles_generated']);
    $smilesLabel = isset($_POST['smiles_label']) ? trim((string)$_POST['smiles_label']) : '';


    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['uploadDocument']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName       = $_FILES['uploadDocument']['tmp_name'][$i];
        $originalName  = basename($_FILES['uploadDocument']['name'][$i]);
        $mimeType      = mime_content_type($tmpName);
        $originalSize  = @filesize($tmpName);

        // ===== IMAGES: downscale if needed, then store inline (no indexing) =====
        if (strpos($mimeType, 'image/') === 0) {
            $downscaleMeta = downscale_image_if_needed($tmpName, $imageDownsampleOptions);
            if (!empty($downscaleMeta['error'])) {
                error_log("Image downscale skipped: " . $downscaleMeta['error']);
            }

            $postProcessMime = mime_content_type($tmpName);
            if ($postProcessMime) {
                $mimeType = $postProcessMime;
            }

            $base64Image = local_image_to_data_url($tmpName, $mimeType);
            $document_id = insert_document($user, $chat_id, $originalName, $mimeType, $base64Image, [
                'compute_tokens' => false,
                'token_length'   => 0,
            ]);
            if ($smilesGenerated) {
                smiles_debug_log(sprintf(
                    'SMILES image insert chat=%s doc=%d name=%s label=%s size=%d bytes=%s',
                    $chat_id,
                    $document_id,
                    $originalName,
                    $smilesLabel,
                    strlen($base64Image),
                    $originalSize !== false ? (int)$originalSize : 'n/a'
                ));
                smiles_debug_log('SMILES image base64 sample: ' . substr($base64Image, 0, 120));
            }
            try {
                $stmt = $pdo->prepare("UPDATE document SET source = :source, full_text_available = 0 WHERE id = :id");
                $stmt->execute([
                    'source' => 'image',
                    'id'     => $document_id,
                ]);
            } catch (Throwable $e) {
                error_log("Failed to set image source for document_id {$document_id}: " . $e->getMessage());
            }
            $processedSize = @filesize($tmpName);
            $originalPreserved = !empty($downscaleMeta['original_copy_path']);
            if (isset($downscaleMeta['original_copy_path'])) {
                unset($downscaleMeta['original_copy_path']);
            }
            $fileSha = false;
            if (is_file($tmpName)) {
                $fileSha = @hash_file('sha256', $tmpName);
            }
            @unlink($tmpName);
            $imageAdjustments = array_merge($downscaleMeta, [
                'processed_bytes' => $processedSize !== false ? (int)$processedSize : null,
                'original_preserved' => $originalPreserved,
            ]);

            try {
                if ($fileSha) {
                    $stmt = $pdo->prepare("UPDATE document SET file_sha256 = :sha WHERE id = :id");
                    $stmt->execute([
                        'sha' => $fileSha,
                        'id'  => $document_id,
                    ]);
                }
            } catch (Throwable $e) {
                error_log("Failed to set file_sha256 for image document_id {$document_id}: " . $e->getMessage());
            }

            $uploadedDocuments[] = [
                'id'                => (int)$document_id,
                'name'              => $originalName,
                'type'              => $mimeType,
                'queued'            => false,
                'inline_only'      => true,
                'original_size'     => $originalSize !== false ? (int)$originalSize : null,
                'parsed_size'       => null,
                'image_adjustments' => $imageAdjustments,
            ];
            continue;
        }

        // ===== DOCS: stage for async parsing/indexing =====
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $stagePath = $uploadStageDir . '/rag_' . uniqid('', true);
        if (!empty($extension)) {
            $stagePath .= '.' . $extension;
        }

        $moved = false;
        if (function_exists('move_uploaded_file')) {
            $moved = @move_uploaded_file($tmpName, $stagePath);
        }
        if (!$moved) {
            $moved = @rename($tmpName, $stagePath);
        }
        if (!$moved) {
            error_log("Failed to move uploaded document into staging for {$originalName}");
            continue;
        }
        @chmod($stagePath, 0640);

        $document_id = insert_document($user, $chat_id, $originalName, $mimeType, '', [
            'compute_tokens' => false,
            'token_length'   => 0,
        ]);

        try {
            $stmt = $pdo->prepare("UPDATE document SET full_text_available = 0, source = 'parsing', document_token_length = 0 WHERE id = :id");
            $stmt->execute(['id' => $document_id]);
        } catch (Throwable $e) {
            error_log("Failed to initialize async parsing metadata for document_id {$document_id}: " . $e->getMessage());
        }

        rag_processing_status_write((int)$document_id, $ragPaths, [
            'status'   => 'running',
            'stage'    => 'uploading',
            'progress' => 0,
            'message'  => 'Uploading document',
        ]);

        $payload = [
            'document_id'         => (int)$document_id,
            'chat_id'             => $chat_id,
            'user'                => $user,
            'config_path'         => $config_file,
            'embedding_model'     => "NHLBI-Chat-workflow-text-embedding-3-large",
            'source_path'         => $stagePath,
            'filename'            => $originalName,
            'mime'                => $mimeType,
            'cleanup_tmp'         => true,
            'original_size_bytes' => $originalSize !== false ? (int)$originalSize : null,
            'queue_timestamp'     => time(),
        ];

        $jobPath = $queueDir . '/job_' . uniqid('', true) . '.json';
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($jobPath, $encoded) === false) {
            error_log("Failed to enqueue async parse job for document_id {$document_id}");
            rag_processing_status_write((int)$document_id, $ragPaths, [
                'status'   => 'failed',
                'stage'    => 'uploading',
                'progress' => 0,
                'message'  => 'Failed to queue parser job',
            ]);
            @unlink($stagePath);
            continue;
        }

        rag_processing_status_write((int)$document_id, $ragPaths, [
            'status'   => 'queued',
            'stage'    => 'uploading',
            'progress' => 10,
            'message'  => 'Waiting to start parsing',
        ]);

        $queued = true;
        $queuedDocuments[] = (int)$document_id;

        $uploadedDocuments[] = [
            'id'                => (int)$document_id,
            'name'              => $originalName,
            'type'              => $mimeType,
            'queued'            => true,
            'inline_only'       => false,
            'original_size'     => $originalSize !== false ? (int)$originalSize : null,
            'parsed_size'       => null,
            'rag_document_ids'  => [(int)$document_id],
            'processing_status' => [
                'stage'    => 'uploading',
                'status'   => 'queued',
                'progress' => 10,
                'message'  => 'Waiting to start parsing',
            ],
        ];

        continue;
    }

    // If we queued documents and there are entries in rag_usage_log we can re-check later,
    // but no immediate cleanup is needed here. Parsed txt files will be removed by the worker.

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

if (UPLOAD_SHOULD_EXIT) {
    exit();
}
return;

/**
 * Helper function to detect AJAX requests.
 */
