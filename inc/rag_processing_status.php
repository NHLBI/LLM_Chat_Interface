<?php
declare(strict_types=1);

/**
 * Utility helpers for reading/writing per-document processing status files.
 *
 * These are lightweight JSON blobs stored on disk so that background workers
 * can communicate progress (e.g., parsing vs. indexing) back to the web tier.
 */

if (!function_exists('rag_processing_status_dir')) {
    function rag_processing_status_dir(array $paths): string
    {
        $dir = $paths['status'] ?? ($paths['logs'] ?? sys_get_temp_dir()) . '/status';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('rag_processing_status_path')) {
    function rag_processing_status_path(int $documentId, array $paths): string
    {
        $dir = rag_processing_status_dir($paths);
        return $dir . '/doc_' . $documentId . '.json';
    }
}

if (!function_exists('rag_processing_status_write')) {
    function rag_processing_status_write(int $documentId, array $paths, array $data): void
    {
        $payload = $data + [
            'document_id' => $documentId,
            'updated_at'  => date('c'),
        ];
        $path = rag_processing_status_path($documentId, $paths);
        $tmp  = $path . '.tmp';
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($tmp, $json);
        @rename($tmp, $path);
    }
}

if (!function_exists('rag_processing_status_read')) {
    function rag_processing_status_read(int $documentId, array $paths): ?array
    {
        $path = rag_processing_status_path($documentId, $paths);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (!function_exists('rag_processing_status_clear')) {
    function rag_processing_status_clear(int $documentId, array $paths): void
    {
        $path = rag_processing_status_path($documentId, $paths);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
