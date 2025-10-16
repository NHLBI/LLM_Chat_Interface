<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function session_log_path(): string
{
    $path = getenv('SESSION_LOG_PATH');
    if ($path === false || $path === '') {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'session_log_fallback.log';
        putenv('SESSION_LOG_PATH=' . $path);
    }
    return $path;
}

function clear_session_log(): void
{
    $path = session_log_path();
    @file_put_contents($path, '');
}

function read_session_log(): array
{
    $path = session_log_path();
    if (!is_file($path)) {
        return [];
    }
    $lines = array_filter(array_map('trim', file($path)));
    $entries = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $entries[] = $decoded;
        }
    }
    return $entries;
}

register_test('Session inactive returns false', function (): void {
    clear_session_log();
    $result = run_session_status([]);

    assert_true(array_key_exists('session_active', $result), 'Response should include session_active');
    assert_equals(false, $result['session_active'], 'Inactive session should report false');
});

register_test('Session active within timeout returns true', function (): void {
    clear_session_log();
    $lastActivity = time() - 120; // 2 minutes ago, within 5-minute timeout fixture

    $result = run_session_status(['LAST_ACTIVITY' => $lastActivity]);

    assert_equals(true, $result['session_active'], 'Active session should report true');
    assert_true(isset($result['remaining_time']), 'Active session should include remaining_time');
    assert_greater_than(0, $result['remaining_time'], 'Remaining time should be positive');
});

register_test('Session expired returns false', function (): void {
    $lastActivity = time() - 900; // 15 minutes ago, beyond 5-minute timeout fixture

    $result = run_session_status(['LAST_ACTIVITY' => $lastActivity]);

    assert_equals(false, $result['session_active'], 'Expired session should report false');
});

register_test('Session status active event is logged', function (): void {
    clear_session_log();
    $lastActivity = time() - 60;

    run_session_status(['LAST_ACTIVITY' => $lastActivity, 'user_data' => ['userid' => 'tester']]);

    $entries = read_session_log();
    $matching = array_filter($entries, function ($entry) {
        return ($entry['event'] ?? null) === 'session_status_active';
    });

    assert_true(!empty($matching), 'Expected session_status_active entry in log');
});

register_test('Session status inactive event records expiry', function (): void {
    clear_session_log();
    $lastActivity = time() - 3600;

    run_session_status(['LAST_ACTIVITY' => $lastActivity, 'user_data' => ['userid' => 'tester']]);

    $entries = read_session_log();
    $matching = array_values(array_filter($entries, function ($entry) {
        return ($entry['event'] ?? null) === 'session_status_inactive';
    }));

    assert_true(!empty($matching), 'Expected session_status_inactive entry in log');
    $context = $matching[0]['context'] ?? [];
    assert_equals('expired', $context['reason'] ?? null, 'Inactive log should note expired reason');
});
