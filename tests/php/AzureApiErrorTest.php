<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../inc/utils.inc.php';
require_once __DIR__ . '/../../inc/azure-api.inc.php';

register_test('process_api_response_handles_azure_error_payload', function (): void {
    $response = json_encode([
        'error' => [
            'message' => 'Rate limit exceeded',
        ],
    ]);

    $activeConfig = [
        'deployment'      => 'azure-gpt4o',
        'deployment_name' => 'azure-gpt4o',
        'host'            => 'Azure',
        'context_limit'   => 8192,
    ];

    $result = process_api_response($response, $activeConfig, 'chat123', 'Test prompt', [], []);

    assert_true(is_array($result), 'Result should be an array');
    assert_true(!empty($result['error']), 'Error flag should be set');
    assert_equals('Rate limit exceeded', $result['message']);
});

register_test('process_api_response_handles_invalid_json', function (): void {
    $response = '<<<not-json>>>';
    $activeConfig = [
        'deployment'      => 'azure-gpt4o',
        'deployment_name' => 'azure-gpt4o',
        'host'            => 'Azure',
        'context_limit'   => 8192,
    ];

    $result = process_api_response($response, $activeConfig, 'chat123', 'Prompt', [], []);

    assert_true(is_array($result));
    assert_true(!empty($result['error']), 'Invalid JSON should set error flag');
    assert_equals('Invalid JSON from completions API', $result['message']);
});
