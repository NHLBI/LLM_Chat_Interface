<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../db.php';

if (!function_exists('integration_config_path')) {
    require_once __DIR__ . '/ChatLifecycleTest.php';
}

/**
 * Recursively remove a directory tree.
 */
function set_env_var(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
    } else {
        putenv($key . '=' . $value);
    }
}

/**
 * Helper to execute upload.php without exiting the test harness.
 */
function include_upload_script(): string
{
    if (!defined('UPLOAD_SHOULD_EXIT')) {
        define('UPLOAD_SHOULD_EXIT', false);
    }

    $configPath = integration_config_path();
    if ($configPath) {
        putenv('CHAT_CONFIG_PATH=' . $configPath);
    }

    global $config, $pdo;
    if (isset($GLOBALS['config'])) {
        $config = $GLOBALS['config'];
    }
    if (isset($GLOBALS['pdo'])) {
        $pdo = $GLOBALS['pdo'];
    }

    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/chatdev/tests';

    ob_start();
    require_once __DIR__ . '/../../bootstrap.php';
    include __DIR__ . '/../../upload.php';
    return (string) ob_get_clean();
}

register_test('upload_document_enqueues_rag_job_with_workflow', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        $GLOBALS['config'] = $config;

        $workspaceRoot = sys_get_temp_dir() . '/rag_test_' . bin2hex(random_bytes(6));
        $previousWorkspace = getenv('RAG_WORKSPACE_ROOT');
        set_env_var('RAG_WORKSPACE_ROOT', $workspaceRoot);

        $parserStub = $workspaceRoot . '/parser_stub.py';
        if (!is_dir($workspaceRoot) && !mkdir($workspaceRoot, 0775, true) && !is_dir($workspaceRoot)) {
            throw new RuntimeException('Unable to create workspace root');
        }
        $parserDir = dirname($parserStub);
        if (!is_dir($parserDir)) {
            mkdir($parserDir, 0775, true);
        }
        $parserContent = <<<'SCRIPT'
#!/bin/sh
input="$1"
if [ -z "$input" ]; then
  exit 1
fi
printf 'Parsed:%s' "$(cat "$input")"
SCRIPT;
        file_put_contents($parserStub, $parserContent);
        chmod($parserStub, 0755);
        $previousParser = getenv('RAG_PARSER');
        $previousPython = getenv('RAG_PYTHON_BIN');
        set_env_var('RAG_PARSER', $parserStub);
        set_env_var('RAG_PYTHON_BIN', '/bin/sh');

        try {
            with_temp_session_dir(function (string $sessionDir, string $sid) use ($pdo, $config, $workspaceRoot): void {
                session_save_path($sessionDir);
                session_id($sid);
                session_start();

                $user = 'integration.upload.' . bin2hex(random_bytes(4));
            $deployment = $config['azure']['default'] ?? 'azure-gpt4o';

            $_SESSION['user_data']['userid'] = $user;
            $_SESSION['authorized'] = true;
            $_SESSION['deployment'] = $deployment;
            $_SESSION['temperature'] = '0.7';
            $_SESSION['tokens']['access_token'] = 'token';
            $_SESSION['LAST_ACTIVITY'] = time();
            $_SESSION['splash'] = 'acknowledged';

            $tmpBase = tempnam(sys_get_temp_dir(), 'upload_doc_');
            if ($tmpBase === false) {
                throw new RuntimeException('Unable to create temp file');
            }
            $docPath = $tmpBase . '.txt';
            rename($tmpBase, $docPath);
            $largePayload = str_repeat("Workflow integration paragraph for testing.\n", 2000);
            file_put_contents($docPath, $largePayload);

            $_FILES = [
                'uploadDocument' => [
                    'name'     => ['workflow-test.txt'],
                    'type'     => ['text/plain'],
                    'tmp_name' => [$docPath],
                    'error'    => [UPLOAD_ERR_OK],
                    'size'     => [filesize($docPath)],
                ],
            ];
            $_REQUEST = [
                'chat_id' => '',
                'selected_workflow' => json_encode([
                    'workflowId' => 'wf-123',
                    'configLabel' => 'execution',
                    'configDescription' => 'auto-prompt-submit',
                ]),
            ];
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

            $_SERVER['REQUEST_URI'] = '/chatdev/tests';
            $output = include_upload_script();
            $payload = json_decode($output, true);
            assert_true(is_array($payload), 'Upload response should decode to array');
            assert_true(($payload['new_chat'] ?? false) === true, 'Upload should create a new chat');
            assert_true(!empty($payload['chat_id']), 'Response should contain chat_id');
            assert_equals(1, count($payload['uploaded_documents'] ?? []), 'Should report one uploaded document');

            $documentInfo = $payload['uploaded_documents'][0];
            assert_true(($documentInfo['queued'] ?? false) === true, 'Document should be queued for indexing');
            assert_true(($documentInfo['inline_only'] ?? true) === false, 'Queued documents should not be flagged inline_only');

            $jobFiles = glob($workspaceRoot . '/queue/job_*.json');
            assert_true(!empty($jobFiles), 'Queue should contain a job file');
            $jobPayload = json_decode(file_get_contents($jobFiles[0]), true);
            assert_equals($documentInfo['id'], $jobPayload['document_id'], 'Job should reference uploaded document');

            $stmt = $pdo->prepare('SELECT content, document_token_length, full_text_available, source FROM document WHERE id = :id');
            $stmt->execute(['id' => $documentInfo['id']]);
            $docRow = $stmt->fetch(PDO::FETCH_ASSOC);
            assert_true(is_array($docRow), 'Document row should exist');
            assert_true(strpos($docRow['content'], 'Parsed:Workflow integration paragraph for testing.') === 0, 'Parsed text should match stub output');
            assert_true((int)$docRow['document_token_length'] > 0, 'Token length should be populated');
            assert_equals('1', (string)$docRow['full_text_available']);
            assert_equals('rag', (string)$docRow['source']);

            $serializedWorkflow = $_SESSION['selected_workflow'] ?? null;
            assert_equals(
                json_encode(['workflowId' => 'wf-123', 'configLabel' => 'execution', 'configDescription' => 'auto-prompt-submit']),
                $serializedWorkflow,
                'Workflow selection should be stored in session'
            );
            assert_true(!empty($_SESSION['workflow_auto_prompt']), 'Auto prompt flag should be set when workflow demands it');

            $chatId = $payload['chat_id'];
            $pdo->prepare('DELETE FROM document WHERE id = :id')->execute(['id' => $documentInfo['id']]);
            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);

            unset($_FILES, $_REQUEST);
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);

            session_write_close();

        });
        } finally {
            set_env_var('RAG_PARSER', $previousParser === false ? null : $previousParser);
            set_env_var('RAG_PYTHON_BIN', $previousPython === false ? null : $previousPython);
            set_env_var('RAG_WORKSPACE_ROOT', $previousWorkspace === false ? null : $previousWorkspace);
            rrmdir($workspaceRoot);
        }
    });
});

register_test('upload_image_stores_base64_document', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        $GLOBALS['config'] = $config;

        $workspaceRoot = sys_get_temp_dir() . '/rag_img_' . bin2hex(random_bytes(6));
        $previousWorkspace = getenv('RAG_WORKSPACE_ROOT');
        set_env_var('RAG_WORKSPACE_ROOT', $workspaceRoot);

        if (!is_dir($workspaceRoot)) {
            mkdir($workspaceRoot, 0775, true);
        }

        try {
            with_temp_session_dir(function (string $sessionDir, string $sid) use ($pdo, $config): void {
                session_save_path($sessionDir);
                session_id($sid);
                session_start();

                $user = 'integration.upload.' . bin2hex(random_bytes(4));
            $deployment = $config['azure']['default'] ?? 'azure-gpt4o';

            $_SESSION['user_data']['userid'] = $user;
            $_SESSION['authorized'] = true;
            $_SESSION['deployment'] = $deployment;
            $_SESSION['temperature'] = '0.7';
            $_SESSION['tokens']['access_token'] = 'token';
            $_SESSION['LAST_ACTIVITY'] = time();
            $_SESSION['splash'] = 'acknowledged';

            $imageBuffer = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/wIAAgMBApNqvwAAAABJRU5ErkJggg==', true);
            $imagePath = tempnam(sys_get_temp_dir(), 'upload_img_');
            file_put_contents($imagePath, $imageBuffer);

            $_FILES = [
                'uploadDocument' => [
                    'name'     => ['preview-test.png'],
                    'type'     => ['image/png'],
                    'tmp_name' => [$imagePath],
                    'error'    => [UPLOAD_ERR_OK],
                    'size'     => [strlen($imageBuffer)],
                ],
            ];
            $_REQUEST = ['chat_id' => ''];
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

            $_SERVER['REQUEST_URI'] = '/chatdev/tests';
            $output = include_upload_script();
            $payload = json_decode($output, true);
            assert_true(is_array($payload), 'Response should decode to array');

            $docInfo = $payload['uploaded_documents'][0] ?? null;
            assert_true(is_array($docInfo), 'Uploaded document metadata should be present');
            assert_equals('preview-test.png', $docInfo['name'], 'Document name should match upload');
            assert_true(($docInfo['queued'] ?? true) === false, 'Images should not be queued for RAG');
            assert_true(($docInfo['inline_only'] ?? false) === true, 'Images should be treated as inline-only assets');

            $stmt = $pdo->prepare('SELECT content, type, full_text_available, source FROM document WHERE id = :id');
            $stmt->execute(['id' => $docInfo['id']]);
            $docRow = $stmt->fetch(PDO::FETCH_ASSOC);
            assert_true(is_array($docRow), 'Image document row should exist');
            assert_equals('image/png', $docRow['type']);
            assert_true(strpos($docRow['content'], 'data:image/png;base64,') === 0, 'Image content should be stored as data URL');
            assert_equals('0', (string)$docRow['full_text_available']);
            assert_equals('image', (string)$docRow['source']);

            $chatId = $payload['chat_id'];
            $pdo->prepare('DELETE FROM document WHERE id = :id')->execute(['id' => $docInfo['id']]);
            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);

            unset($_FILES, $_REQUEST);
            unset($_SERVER['HTTP_X_REQUESTED_WITH']);
            session_write_close();

        });
        } finally {
            set_env_var('RAG_WORKSPACE_ROOT', $previousWorkspace === false ? null : $previousWorkspace);
            rrmdir($workspaceRoot);
        }
    });
});
