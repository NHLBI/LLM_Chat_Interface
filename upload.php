<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include necessary libraries
require_once 'lib.required.php';

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
$workRoot  = __DIR__ . '/var/rag';
$parsedDir = $workRoot . '/parsed';
$queueDir  = $workRoot . '/queue';
$logsDir   = $workRoot . '/logs';

$dirs = [$workRoot, $parsedDir, $queueDir, $logsDir];
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
$python  = __DIR__ . '/rag310/bin/python3';
$parser  = __DIR__ . '/parser_multi.py';
$indexer = __DIR__ . '/inc/build_index.py';

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
    $fileCount = count($_FILES['uploadDocument']['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        if ($_FILES['uploadDocument']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpName      = $_FILES['uploadDocument']['tmp_name'][$i];
        $originalName = basename($_FILES['uploadDocument']['name'][$i]);
        $mimeType     = mime_content_type($tmpName);

        // ===== IMAGES: store as before; NO indexing =====
        if (strpos($mimeType, 'image/') === 0) {
            $base64Image = local_image_to_data_url($tmpName, $mimeType);
            $document_id = insert_document($user, $chat_id, $originalName, $mimeType, $base64Image);
            @unlink($tmpName);
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

        // ===== Build the RAG index =====
        $payload = [
            'document_id'     => (int)$document_id,
            'chat_id'         => $chat_id,
            'user'            => $user,
            'embedding_model' => "NHLBI-Chat-workflow-text-embedding-3-large",
            'config_path'     => $config_file,
            'file_path'       => $txtPath,
            'filename'        => $originalName,
            'mime'            => $mimeType,
            'cleanup_tmp'     => false
        ];
        $json_file = $queueDir . '/job_' . uniqid('', true) . '.json';
        file_put_contents($json_file, json_encode($payload));

        $logPath = $logsDir . '/index_' . $document_id . '_' . time() . '.log';

        // Enhanced debugging for the indexer command
        error_log("DEBUG: Checking indexer prerequisites");
        error_log("DEBUG: Python path exists: " . (file_exists($python) ? 'YES' : 'NO'));
        error_log("DEBUG: Python is executable: " . (is_executable($python) ? 'YES' : 'NO'));
        error_log("DEBUG: Indexer script exists: " . (file_exists($indexer) ? 'YES' : 'NO'));
        error_log("DEBUG: JSON file exists: " . (file_exists($json_file) ? 'YES' : 'NO'));
        error_log("DEBUG: JSON file content: " . file_get_contents($json_file));
        error_log("DEBUG: Working directory: " . getcwd());

        // Test the Python environment first
        $testCmd = sprintf('%s --version 2>&1', escapeshellarg($python));
        exec($testCmd, $testOut, $testRc);
        error_log("DEBUG: Python version test - RC: $testRc, Output: " . implode(' ', $testOut));

        // Test if the indexer script can be accessed
        $syntaxCmd = sprintf('%s -B -m py_compile %s 2>&1', escapeshellarg($python), escapeshellarg($indexer));
        exec($syntaxCmd, $syntaxOut, $syntaxRc);
        error_log("DEBUG: Python syntax check - RC: $syntaxRc, Output: " . implode(' ', $syntaxOut));

        $cmd = sprintf(
            '%s -u %s --json %s 2>&1',
            escapeshellarg($python),
            escapeshellarg($indexer),
            escapeshellarg($json_file)
        );
        error_log("INDEX CMD: $cmd");

        // Alternative approach: Use exec first to see if there are immediate errors
        $execOutput = [];
        $execRc = 0;
        exec($cmd, $execOutput, $execRc);
        error_log("DEBUG: Direct exec result - RC: $execRc");
        if (!empty($execOutput)) {
            error_log("DEBUG: Direct exec output: " . implode("\n", $execOutput));
        }

        // If exec works, continue with proc_open for better control
        if ($execRc === 0 && !empty($execOutput)) {
            // Use the exec output
            $captured = implode("\n", $execOutput);
            $rc = $execRc;
        } else {
            // Fall back to proc_open with enhanced error handling
            $descs = [
                0 => ['pipe', 'r'], // stdin
                1 => ['pipe', 'w'], // stdout
                2 => ['pipe', 'w'], // stderr
            ];
            $pipes = [];
            $env = [
                'PYTHONPATH' => dirname($indexer),
                'PATH' => getenv('PATH')
            ];
            
            $proc = proc_open($cmd, $descs, $pipes, __DIR__, $env);

            $captured = '';
            $rc = 1;

            if (is_resource($proc)) {
                fclose($pipes[0]); // no stdin

                // Set streams to non-blocking
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);

                $start = microtime(true);
                $timeoutSec = 600;

                while (true) {
                    $outChunk = stream_get_contents($pipes[1]);
                    $errChunk = stream_get_contents($pipes[2]);
                    if ($outChunk !== false && $outChunk !== '') {
                        $captured .= $outChunk;
                        error_log("DEBUG: Got stdout chunk: " . substr($outChunk, 0, 200));
                    }
                    if ($errChunk !== false && $errChunk !== '') {
                        $captured .= $errChunk;
                        error_log("DEBUG: Got stderr chunk: " . substr($errChunk, 0, 200));
                    }

                    $status = proc_get_status($proc);
                    if (!$status['running']) {
                        error_log("DEBUG: Process finished with exit code: " . $status['exitcode']);
                        break;
                    }

                    if ((microtime(true) - $start) > $timeoutSec) {
                        proc_terminate($proc, 9);
                        $captured .= "\n[php] indexer timeout after {$timeoutSec}s";
                        error_log("DEBUG: Process timed out");
                        break;
                    }
                    usleep(50_000);
                }

                // Final drain
                stream_set_blocking($pipes[1], true);
                stream_set_blocking($pipes[2], true);
                $finalOut = stream_get_contents($pipes[1]);
                $finalErr = stream_get_contents($pipes[2]);
                if ($finalOut !== false && $finalOut !== '') {
                    $captured .= $finalOut;
                    error_log("DEBUG: Final stdout: " . substr($finalOut, 0, 200));
                }
                if ($finalErr !== false && $finalErr !== '') {
                    $captured .= $finalErr;
                    error_log("DEBUG: Final stderr: " . substr($finalErr, 0, 200));
                }

                fclose($pipes[1]);
                fclose($pipes[2]);

                $rc = proc_close($proc);
                error_log("DEBUG: Final return code: $rc");
            } else {
                $captured = "[php] proc_open failed";
                error_log("DEBUG: proc_open failed to start");
            }
        }

        if ($captured === '') {
            $captured = "[empty stdout/stderr]";
            error_log("DEBUG: No output captured from indexer");
        }
        
        @file_put_contents($logPath, $captured);

        // Parse the last JSON object from the captured text
        $resultJson = null;
        if ($captured !== '' && $captured !== "[empty stdout/stderr]") {
            $lines = preg_split("/\r\n|\n|\r/", $captured);
            for ($k = count($lines) - 1; $k >= 0; $k--) {
                $line = trim($lines[$k]);
                if ($line === '') continue;

                $decoded = json_decode($line, true);
                if (is_array($decoded)) { 
                    $resultJson = $decoded; 
                    error_log("DEBUG: Found JSON result: " . json_encode($decoded));
                    break; 
                }

                if (preg_match('/\{(?:[^{}]|(?R))*\}\s*$/s', $line, $m)) {
                    $decoded = json_decode($m[0], true);
                    if (is_array($decoded)) { 
                        $resultJson = $decoded; 
                        error_log("DEBUG: Found JSON result (regex): " . json_encode($decoded));
                        break; 
                    }
                }
            }
        }

        // Drop the temp upload file
        @unlink($tmpName);

        if ($rc !== 0 || !$resultJson || empty($resultJson['ok'])) {
            $preview = substr($captured, 0, 1200);
            error_log("RAG indexing failed for document_id {$document_id} (rc=$rc). Log=$logPath Preview:\n$preview");
        } else {
            error_log("RAG indexing DONE for document_id {$document_id}: chunks=".$resultJson['chunk_count']." (log=$logPath)");
        }
    }

    // Use JSON response for AJAX requests, else do a header redirect
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'new_chat' => $new_chat_created]);
    } else {
        header('Location: ' . urlencode($chat_id));
    }
} else {
    if (isAjaxRequest()) {
        echo json_encode(['chat_id' => $chat_id, 'new_chat' => false]);
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
