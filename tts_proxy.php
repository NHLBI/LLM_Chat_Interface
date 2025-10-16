<?php
declare(strict_types=1);

require_once __DIR__ . '/session_init.php';
require_once __DIR__ . '/get_config.php';
require_once __DIR__ . '/inc/login-session.inc.php';

error_log('tts_proxy invoked: user=' . ($_SESSION['user_data']['userid'] ?? 'anon'));
$debugLog = '/tmp/tts_proxy_debug.log';
file_put_contents($debugLog, date('c') . " invoked by " . ($_SESSION['user_data']['userid'] ?? 'anon') . PHP_EOL, FILE_APPEND);

header('Content-Type: application/json');

if (empty($_SESSION['user_data']['userid']) || empty($_SESSION['authorized'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    error_log('tts_proxy unauthorized access');
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload) || empty($payload['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing text payload.']);
    exit;
}

$text = (string)$payload['text'];
$voice = isset($payload['voice']) && $payload['voice'] !== ''
    ? (string)$payload['voice']
    : 'af_heart';

$baseUrlConfig = isset($config['tts']['base_url']) ? trim((string)$config['tts']['base_url']) : '';
$apiKeyConfig = isset($config['tts']['api_key']) ? trim((string)$config['tts']['api_key']) : '';

$baseUrl = $baseUrlConfig !== ''
    ? $baseUrlConfig
    : (getenv('TTS_BASE_URL') ?: 'http://[2607:f220:404:1310::1:53]:8088/tts');

$apiKey = $apiKeyConfig !== ''
    ? $apiKeyConfig
    : (getenv('TTS_API_KEY') ?: 't5Lc8Sptmg5XCExPrzqxHpXvnvJxA75j');

if (!$apiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'TTS service not configured.']);
    error_log('tts_proxy missing API key');
    file_put_contents($debugLog, date('c') . " missing api key" . PHP_EOL, FILE_APPEND);
    exit;
}

$ch = curl_init($baseUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'text' => $text,
        'voice' => $voice,
    ], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V6,
]);

$response = curl_exec($ch);
if ($response === false) {
    error_log('tts_proxy curl error: ' . curl_error($ch));
    file_put_contents($debugLog, date('c') . " curl error: " . curl_error($ch) . PHP_EOL, FILE_APPEND);
    http_response_code(502);
    echo json_encode(['error' => 'Failed to reach TTS service.']);
    curl_close($ch);
    exit;
}

$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$headersRaw = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

if ($statusCode < 200 || $statusCode >= 300) {
    http_response_code($statusCode ?: 502);
    $message = @json_decode($body, true);
    $errorMessage = is_array($message) && isset($message['error'])
        ? $message['error']
        : 'TTS service returned an error.';
    error_log(sprintf('tts_proxy non-2xx response: status=%s body=%s', $statusCode, substr($body, 0, 400)));
    file_put_contents($debugLog, date('c') . " non-2xx status {$statusCode} body: " . substr($body, 0, 400) . PHP_EOL, FILE_APPEND);
    echo json_encode(['error' => $errorMessage]);
    exit;
}

header_remove('Content-Type');
header('Content-Type: audio/wav');
header('Content-Length: ' . strlen($body));
echo $body;
exit;
