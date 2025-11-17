<?php
declare(strict_types=1);

function rag_app_root(): string
{
    static $root = null;
    if ($root === null) {
        $root = dirname(__DIR__);
    }
    return $root;
}

function rag_workspace_root(?array $config = null): string
{
    $configured = null;
    if ($config) {
        if (!empty($config['rag']['workspace_root'])) {
            $configured = $config['rag']['workspace_root'];
        } elseif (!empty($config['app']['rag_workspace_root'])) {
            $configured = $config['app']['rag_workspace_root'];
        }
    }

    if (!$configured) {
        $env = getenv('RAG_WORKSPACE_ROOT');
        if ($env !== false && $env !== '') {
            $configured = $env;
        }
    }

    if ($configured) {
        return rtrim($configured, DIRECTORY_SEPARATOR);
    }

    return rag_app_root() . '/var/rag';
}

function rag_workspace_paths(?array $config = null): array
{
    $root = rag_workspace_root($config);

    return [
        'root'      => $root,
        'queue'     => $root . '/queue',
        'parsed'    => $root . '/parsed',
        'logs'      => $root . '/logs',
        'completed' => $root . '/completed',
        'failed'    => $root . '/failed',
        'status'    => $root . '/status',
        'uploads'   => $root . '/uploads',
    ];
}

function rag_python_binary(?array $config = null): string
{
    $configured = null;
    if ($config && !empty($config['rag']['python'])) {
        $configured = $config['rag']['python'];
    }

    if (!$configured) {
        $env = getenv('RAG_PYTHON_BIN');
        if ($env !== false && $env !== '') {
            $configured = $env;
        }
    }

    if ($configured) {
        return $configured;
    }

    return rag_app_root() . '/rag310/bin/python3';
}

function rag_indexer_script(?array $config = null): string
{
    $configured = null;
    if ($config && !empty($config['rag']['indexer'])) {
        $configured = $config['rag']['indexer'];
    }

    if (!$configured) {
        $env = getenv('RAG_INDEXER');
        if ($env !== false && $env !== '') {
            $configured = $env;
        }
    }

    if ($configured) {
        return $configured;
    }

    return rag_app_root() . '/inc/build_index.py';
}

function rag_parser_script(?array $config = null): string
{
    $configured = null;
    if ($config && !empty($config['rag']['parser'])) {
        $configured = $config['rag']['parser'];
    }

    if (!$configured) {
        $env = getenv('RAG_PARSER');
        if ($env !== false && $env !== '') {
            $configured = $env;
        }
    }

    if ($configured) {
        return $configured;
    }

    return rag_app_root() . '/parser_multi.py';
}
