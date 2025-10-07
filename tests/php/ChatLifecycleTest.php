<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../session_init.php';
require_once __DIR__ . '/../../db.php';

function integration_config_path(): string
{
    $path = getenv('CHAT_TEST_CONFIG_PATH');
    if ($path === false || $path === '') {
        $path = getenv('CHAT_CONFIG_PATH');
    }
    if ($path === false || $path === '') {
        $path = '/etc/apps/chatdev_config.ini';
    }
    return $path;
}

function load_integration_config(): array
{
    $path = integration_config_path();
    $config = parse_ini_file($path, true, INI_SCANNER_TYPED);
    if ($config === false) {
        throw new RuntimeException('Unable to parse integration config: ' . $path);
    }
    return $config;
}

function with_database(callable $callback): void
{
    $config = load_integration_config();
    $GLOBALS['config'] = $config;

    $pdo = get_connection();
    $GLOBALS['pdo'] = $pdo;

    try {
        $callback($pdo, $config);
    } finally {
        unset($GLOBALS['pdo']);
    }
}

function with_authenticated_session(PDO $pdo, array $config, callable $callback): void
{
    with_temp_session_dir(function (string $dir, string $sid) use ($config, $callback): void {
        session_save_path($dir);
        session_id($sid);
        session_start();

        $user = 'integration.user.' . bin2hex(random_bytes(4));
        $deployment = $config['azure']['default'] ?? '';

        $_SESSION['user_data']['userid'] = $user;
        $_SESSION['tokens']['access_token'] = 'integration-token';
        $_SESSION['splash'] = 'acknowledged';
        $_SESSION['authorized'] = true;
        $_SESSION['LAST_ACTIVITY'] = time();
        $_SESSION['LAST_REGEN'] = time();
        $_SESSION['deployment'] = $deployment;
        $_SESSION['temperature'] = $_SESSION['temperature'] ?? '0.7';
        $_SESSION['reasoning_effort'] = $_SESSION['reasoning_effort'] ?? 'medium';
        $_SESSION['verbosity'] = $_SESSION['verbosity'] ?? 'medium';

        try {
            $callback($user, $deployment, $dir, $sid);
        } finally {
            session_write_close();
        }
    });
}

register_test('create_chat persists chat row', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        with_temp_session_dir(function (string $dir, string $sid) use ($pdo, $config): void {
            session_save_path($dir);
            session_id($sid);
            session_start();

            $user = 'integration.user.' . bin2hex(random_bytes(4));
            $title = 'Integration Chat ' . bin2hex(random_bytes(4));
            $summary = 'Integration summary';
            $deployment = $config['azure']['default'] ?? '';

            $_SESSION['temperature'] = '0.8';
            $_SESSION['reasoning_effort'] = 'high';
            $_SESSION['verbosity'] = 'low';

            $chatId = create_chat($user, $title, $summary, $deployment);

            assert_equals(32, strlen($chatId), 'Chat ID should be 32 characters');

            $stmt = $pdo->prepare('SELECT user, title, summary, deployment, temperature, reasoning_effort, verbosity, deleted FROM chat WHERE id = :id');
            $stmt->execute(['id' => $chatId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            assert_true(is_array($row), 'Chat row should exist');
            assert_equals($user, $row['user']);
            assert_equals($title, $row['title']);
            assert_equals($summary, $row['summary']);
            assert_equals($deployment, $row['deployment']);
            assert_equals('0', (string)$row['deleted']);
            assert_equals('0.8', (string)$row['temperature']);
            assert_equals('high', $row['reasoning_effort']);
            assert_equals('low', $row['verbosity']);

            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);

            session_write_close();
        });
    });
});

register_test('create_exchange persists prompt and reply', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        with_temp_session_dir(function (string $dir, string $sid) use ($pdo, $config): void {
            session_save_path($dir);
            session_id($sid);
            session_start();

            $user = 'integration.user.' . bin2hex(random_bytes(4));
            $_SESSION['user_data']['userid'] = $user;
            $_SESSION['tokens']['access_token'] = 'dummy-token';
            $_SESSION['splash'] = 'ack';
            $_SESSION['authorized'] = true;

            $deployment = $config['azure']['default'] ?? '';
            $_SESSION['deployment'] = $deployment;
            $_SESSION['temperature'] = '0.6';
            $_SESSION['api_endpoint'] = 'https://example.test/api';

            if (!isset($config[$deployment]['handles_images'])) {
                $config[$deployment]['handles_images'] = false;
            }
            $GLOBALS['config'][$deployment]['handles_images'] = $config[$deployment]['handles_images'];

            $title = 'Integration Chat ' . bin2hex(random_bytes(3));
            $chatId = create_chat($user, $title, '', $deployment);

            $prompt = 'Integration prompt ' . bin2hex(random_bytes(4));
            $reply = 'Integration reply ' . bin2hex(random_bytes(4));
            $_SERVER['HTTP_REFERER'] = 'https://example.test/chat';

            $exchangeId = create_exchange($deployment, $chatId, $prompt, $reply, 0, null);

            assert_true(is_numeric($exchangeId), 'Exchange insert ID should be numeric');

            $stmt = $pdo->prepare('SELECT user, chat_id, prompt, reply, prompt_token_length, reply_token_length, deleted FROM exchange WHERE id = :id');
            $stmt->execute(['id' => $exchangeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            assert_true(is_array($row), 'Exchange row should exist');
            assert_equals($user, $row['user']);
            assert_equals($chatId, $row['chat_id']);
            assert_equals($prompt, $row['prompt']);
            assert_equals($reply, $row['reply']);
            assert_equals('0', (string)$row['deleted']);
            assert_greater_than(0, (int)$row['prompt_token_length'], 'Prompt token length should be > 0');
            assert_greater_than(0, (int)$row['reply_token_length'], 'Reply token length should be > 0');

            $pdo->prepare('DELETE FROM exchange WHERE id = :id')->execute(['id' => $exchangeId]);
            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);

            session_write_close();
        });
    });
});

register_test('get_all_chats respects search term', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        with_authenticated_session($pdo, $config, function (string $user, string $deployment, string $dir, string $sid) use ($pdo): void {
            $chatMatch = create_chat($user, 'Alpha Integration ' . bin2hex(random_bytes(3)), '', $deployment);
            $chatOther = create_chat($user, 'Beta Integration ' . bin2hex(random_bytes(3)), '', $deployment);

            $results = get_all_chats($user, 'Alpha');

            assert_true(isset($results[$chatMatch]), 'Matching chat should be returned');
            assert_true(!isset($results[$chatOther]), 'Non-matching chat should be filtered out');

            $stmt = $pdo->prepare('DELETE FROM chat WHERE id IN (:a, :b)');
            $stmt->execute(['a' => $chatMatch, 'b' => $chatOther]);
        });
    });
});

register_test('update_chat_title persists rename', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        with_authenticated_session($pdo, $config, function (string $user, string $deployment, string $dir, string $sid) use ($pdo): void {
            $chatId = create_chat($user, 'Original Title ' . bin2hex(random_bytes(3)), '', $deployment);
            $newTitle = 'Renamed Title ' . bin2hex(random_bytes(3));

            update_chat_title($user, $chatId, $newTitle);

            $stmt = $pdo->prepare('SELECT title, new_title FROM chat WHERE id = :id');
            $stmt->execute(['id' => $chatId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            assert_true(is_array($row), 'Chat row should exist after rename');
            assert_equals($newTitle, $row['title']);
            assert_equals('0', (string)$row['new_title']);

            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);
        });
    });
});

register_test('soft delete flags chat and exchange rows', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        with_authenticated_session($pdo, $config, function (string $user, string $deployment, string $dir, string $sid) use ($pdo): void {
            $_SESSION['api_endpoint'] = 'https://example.test/api';
            $_SESSION['temperature'] = '0.7';

            $chatId = create_chat($user, 'Delete Flow ' . bin2hex(random_bytes(3)), '', $deployment);
            $prompt = 'Prompt ' . bin2hex(random_bytes(4));
            $reply = 'Reply ' . bin2hex(random_bytes(4));
            $_SERVER['HTTP_REFERER'] = 'https://example.test/chat';
            $exchangeId = create_exchange($deployment, $chatId, $prompt, $reply, 0, null);

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE document SET deleted = 1 WHERE chat_id = :chat_id')->execute(['chat_id' => $chatId]);
                $pdo->prepare('UPDATE chat SET deleted = 1 WHERE id = :id')->execute(['id' => $chatId]);
                $pdo->prepare('UPDATE exchange SET deleted = 1 WHERE chat_id = :chat_id')->execute(['chat_id' => $chatId]);
                $pdo->commit();
            } catch (Throwable $t) {
                $pdo->rollBack();
                throw $t;
            }

            $chatStmt = $pdo->prepare('SELECT deleted FROM chat WHERE id = :id');
            $chatStmt->execute(['id' => $chatId]);
            $chatRow = $chatStmt->fetch(PDO::FETCH_ASSOC);
            assert_true(is_array($chatRow), 'Chat row should exist after delete');
            assert_equals('1', (string)$chatRow['deleted']);

            $exStmt = $pdo->prepare('SELECT deleted FROM exchange WHERE id = :id');
            $exStmt->execute(['id' => $exchangeId]);
            $exchangeRow = $exStmt->fetch(PDO::FETCH_ASSOC);
            assert_true(is_array($exchangeRow), 'Exchange row should exist after delete');
            assert_equals('1', (string)$exchangeRow['deleted']);

            $pdo->prepare('DELETE FROM exchange WHERE chat_id = :chat')->execute(['chat' => $chatId]);
            $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);
        });
    });
});

register_test('verify_user_chat rejects non-owner', function (): void {
    with_database(function (PDO $pdo, array $config): void {
        $deployment = $config['azure']['default'] ?? '';
        $owner = 'integration.owner.' . bin2hex(random_bytes(4));
        $other = 'integration.other.' . bin2hex(random_bytes(4));
        $chatId = null;

        with_temp_session_dir(function (string $dir, string $sid) use ($pdo, $deployment, $owner, &$chatId): void {
            session_save_path($dir);
            session_id($sid);
            session_start();
            $_SESSION['user_data']['userid'] = $owner;
            $_SESSION['tokens']['access_token'] = 'owner-token';
            $_SESSION['splash'] = 'ack';
            $_SESSION['authorized'] = true;
            $_SESSION['deployment'] = $deployment;
            $_SESSION['temperature'] = '0.7';

            $chatId = create_chat($owner, 'Guard Chat ' . bin2hex(random_bytes(3)), '', $deployment);
            session_write_close();
        });

        assert_true(verify_user_chat($owner, $chatId), 'Owner should pass verification');
        assert_true(!verify_user_chat($other, $chatId), 'Non-owner should fail verification');

        $pdo->prepare('DELETE FROM chat WHERE id = :id')->execute(['id' => $chatId]);
    });
});
