<?php
declare(strict_types=1);

$__registered_tests = [];

if (getenv('SESSION_LOG_PATH') === false || getenv('SESSION_LOG_PATH') === '') {
    $sessionLogTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'session_log_' . bin2hex(random_bytes(6)) . '.log';
    putenv('SESSION_LOG_PATH=' . $sessionLogTmp);
    register_shutdown_function(function () use ($sessionLogTmp): void {
        if (is_file($sessionLogTmp)) {
            @unlink($sessionLogTmp);
        }
    });
}

if (!function_exists('rrmdir')) {
    /**
     * Recursively remove a directory tree.
     */
    function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (is_file($dir)) {
                @unlink($dir);
            }
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}

function register_test(string $name, callable $fn): void
{
    global $__registered_tests;
    $__registered_tests[] = [$name, $fn];
}

function run_tests(): int
{
    global $__registered_tests;
    $passed = 0;
    $total = count($__registered_tests);

    $logs = [];

    foreach ($__registered_tests as [$name, $fn]) {
        try {
            $fn();
            $logs[] = "[PASS] {$name}";
            $passed++;
        } catch (Throwable $e) {
            $logs[] = "[FAIL] {$name}: " . $e->getMessage();
        }
    }

    foreach ($logs as $line) {
        echo $line . "\n";
    }

    echo str_repeat('-', 40) . "\n";
    echo "Passed {$passed}/{$total} tests\n";

    return ($passed === $total) ? 0 : 1;
}

function assert_true(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message ?: sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
        throw new RuntimeException($msg);
    }
}

function assert_greater_than($min, $actual, string $message = ''): void
{
    if (!($actual > $min)) {
        $msg = $message ?: sprintf('Expected greater than %s, got %s', var_export($min, true), var_export($actual, true));
        throw new RuntimeException($msg);
    }
}

function with_temp_session_dir(callable $callback)
{
    $base = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'test_sessions';
    if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
        throw new RuntimeException('Unable to create base temp session directory');
    }

    $dir = $base . DIRECTORY_SEPARATOR . 'chatdev_test_session_' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create temp session directory');
    }

    $previous_save_path = session_save_path();
    $previous_id = session_id();
    $previous_status = session_status();

    try {
        if ($previous_status === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        session_save_path($dir);
        $sid = bin2hex(random_bytes(16));
        session_id($sid);

        return $callback($dir, $sid);
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        if (!empty($previous_id)) {
            session_id($previous_id);
        }
        session_save_path($previous_save_path);

        foreach (glob($dir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}

function run_session_status(array $sessionData): array
{
    $configPath = realpath(__DIR__ . '/../fixtures/test_config.ini');
    if ($configPath === false) {
        throw new RuntimeException('Test config fixture missing');
    }

    $previousEnv = getenv('CHAT_CONFIG_PATH');
    $previousConfig = $GLOBALS['config'] ?? null;

    putenv('CHAT_CONFIG_PATH=' . $configPath);
    $_SERVER['REQUEST_URI'] = '/chatdev/tests';

    try {
        return with_temp_session_dir(function (string $dir, string $sid) use ($sessionData, $configPath) {
            $config = parse_ini_file($configPath, true, INI_SCANNER_TYPED);
            if ($config === false) {
                throw new RuntimeException('Unable to parse test config ini');
            }
            $GLOBALS['config'] = $config;

            session_save_path($dir);
            session_id($sid);
            session_start();
            foreach ($sessionData as $key => $value) {
                $_SESSION[$key] = $value;
            }
            session_write_close();

            session_save_path($dir);
            session_id($sid);

            ob_start();
            require __DIR__ . '/../../session_status.php';
            $output = ob_get_clean();

            $data = json_decode($output, true);
            if (!is_array($data)) {
                throw new RuntimeException('Invalid JSON returned: ' . $output);
            }

            return $data;
        });
    } finally {
        if ($previousConfig !== null) {
            $GLOBALS['config'] = $previousConfig;
        } else {
            unset($GLOBALS['config']);
        }

        if ($previousEnv === false) {
            putenv('CHAT_CONFIG_PATH');
        } else {
            putenv('CHAT_CONFIG_PATH=' . $previousEnv);
        }
    }
}
