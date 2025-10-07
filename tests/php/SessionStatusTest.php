<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

register_test('Session inactive returns false', function (): void {
    $result = run_session_status([]);

    assert_true(array_key_exists('session_active', $result), 'Response should include session_active');
    assert_equals(false, $result['session_active'], 'Inactive session should report false');
});

register_test('Session active within timeout returns true', function (): void {
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
