<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../inc/RAG.inc.php';

register_test('configured rag python path must be executable', function (): void {
    $previousConfig = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = [
        'rag' => [
            'python' => '/tmp/does-not-exist',
        ],
    ];

    try {
        $result = run_rag('Test question', 1, 'tester', '/tmp/does-not-exist.ini');
        assert_true(is_array($result), 'Result should be an array');
        assert_equals(1, $result['rc'] ?? null, 'Invalid config should return rc=1');
        assert_true(
            strpos($result['stderr'] ?? '', 'Configured RAG python not executable') === 0,
            'Should surface configured python error'
        );
    } finally {
        if ($previousConfig !== null) {
            $GLOBALS['config'] = $previousConfig;
        } else {
            unset($GLOBALS['config']);
        }
    }
});
