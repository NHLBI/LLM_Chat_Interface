<?php

declare(strict_types=1);

function stream_control_base_dir(): string {
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }

    $root = dirname(__DIR__);
    $candidate = $root . '/var/stream_control';
    if (!is_dir($candidate)) {
        @mkdir($candidate, 0770, true);
    }

    if (!is_dir($candidate) && !is_writable(dirname($candidate))) {
        $fallback = sys_get_temp_dir() . '/nhlbi_chat_streams';
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0700, true);
        }
        $dir = $fallback;
    } else {
        $dir = $candidate;
    }

    return $dir;
}

function stream_control_path(string $streamId): ?string {
    if ($streamId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $streamId)) {
        return null;
    }
    return stream_control_base_dir() . '/' . $streamId . '.json';
}

function stream_register(string $streamId): void {
    $path = stream_control_path($streamId);
    if ($path === null) {
        return;
    }
    $payload = [
        'stop'       => false,
        'updated_at' => time(),
    ];
    @file_put_contents($path, json_encode($payload));
}

function stream_request_stop(string $streamId): bool {
    $path = stream_control_path($streamId);
    if ($path === null) {
        return false;
    }
    $payload = [
        'stop'       => true,
        'updated_at' => time(),
    ];
    return (bool)@file_put_contents($path, json_encode($payload));
}

function stream_should_stop(string $streamId): bool {
    $path = stream_control_path($streamId);
    if ($path === null || !is_file($path)) {
        return false;
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        return false;
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return false;
    }
    return !empty($data['stop']);
}

function stream_cleanup(string $streamId): void {
    $path = stream_control_path($streamId);
    if ($path !== null && is_file($path)) {
        @unlink($path);
    }
}
