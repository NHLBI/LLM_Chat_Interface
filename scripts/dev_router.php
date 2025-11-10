<?php
declare(strict_types=1);

$projectRoot = realpath(__DIR__ . '/..');
if ($projectRoot === false) {
    http_response_code(500);
    echo "Unable to determine project root.";
    return true;
}

$uri       = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri       = $uri === false ? '/' : $uri;
$basePath  = getenv('APP_PATH') ?: '';
$basePath  = '/' . ltrim(trim($basePath), '/');
$basePath  = rtrim($basePath, '/');

if ($basePath === '/') {
    $basePath = '';
}

/**
 * Attempt to serve a static file if it exists on disk.
 */
$serveStaticFile = function (string $path) use ($projectRoot) {
    $fullPath = realpath($projectRoot . '/' . ltrim($path, '/'));
    if ($fullPath && str_starts_with($fullPath, $projectRoot) && is_file($fullPath)) {
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (in_array($extension, ['php', 'phtml'], true)) {
            return null;
        }
        return $fullPath;
    }
    return null;
};

// 1. Direct static file lookup (rooted paths)
if ($uri !== '/') {
    $directFile = $serveStaticFile($uri);
    if ($directFile) {
        return false; // let the built-in server handle it
    }
}

// 2. Requests under the configured base path (e.g. /chat/xyz)
if ($basePath !== '' && str_starts_with($uri, $basePath . '/')) {
    $subPath = substr($uri, strlen($basePath));
    $subPath = '/' . ltrim($subPath, '/');

    // 2a. Static assets referenced with the base path prefix
    $assetFile = $serveStaticFile($subPath);
    if ($assetFile) {
        $extension = strtolower(pathinfo($assetFile, PATHINFO_EXTENSION));
        $mime = match ($extension) {
            'css'  => 'text/css; charset=UTF-8',
            'js'   => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            default => mime_content_type($assetFile) ?: 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($assetFile));
        readfile($assetFile);
        return true;
    }

    $scriptPath = realpath($projectRoot . '/' . ltrim($subPath, '/'));
    if ($scriptPath && str_starts_with($scriptPath, $projectRoot) && is_file($scriptPath)) {
        require_once $scriptPath;
        return true;
    }

    if (preg_match('#^/([0-9A-Za-z_-]{8,})$#', $subPath, $matches)) {
        $chatId = $matches[1];
        $_GET['chat_id'] = $_REQUEST['chat_id'] = $chatId;
        $_SERVER['REQUEST_URI'] = "/index.php?chat_id={$chatId}";
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['PHP_SELF']    = '/index.php';
        require_once $projectRoot . '/index.php';
        return true;
    }

    // 2b. Dynamic routes (chat detail pages, etc.) → funnel to index.php
    $_SERVER['REQUEST_URI'] = $subPath;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['PHP_SELF']    = '/index.php';
    require_once $projectRoot . '/index.php';
    return true;
}

// 3. Default fallback → index.php (handles /, /index.php, etc.)
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF']    = '/index.php';
require_once $projectRoot . '/index.php';
return true;
