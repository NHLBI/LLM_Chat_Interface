<?php
// rag_probe.php — minimal POC for rag_retrieve

// 1) Build payload like your app does
$payload = [
  #'question' => 'Give me the key points from lederman1.pdf',
  'question' => 'What does the lederman1.pdf document say about Figshare?',
  'chat_id'  => '0B70ECCC49FF460692F5620792677291',
  'user'     => 'wyrickrv',
  'top_k'    => 8,
  'max_context_tokens' => 2000,
  'config_path' => '/etc/apps/chat_config.ini',
];
$tmp = tempnam(sys_get_temp_dir(), 'ragq_').'.json';
file_put_contents($tmp, json_encode($payload));

// 2) Paths
$python = __DIR__.'/rag310/bin/python3';
$script = __DIR__.'/inc/rag_retrieve.py';

// 3) Use proc_open to capture both streams
$cmd = escapeshellarg($python).' '.escapeshellarg($script).' --json '.escapeshellarg($tmp);

$descs = [
  0 => ['pipe', 'r'],
  1 => ['pipe', 'w'],  // stdout
  2 => ['pipe', 'w'],  // stderr
];
$env = []; $cwd = null;
$proc = proc_open($cmd, $descs, $pipes, $cwd, $env);

if (!is_resource($proc)) {
  unlink($tmp);
  die("Failed to start process\n");
}

fclose($pipes[0]); // no stdin
$stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
$rc = proc_close($proc);
unlink($tmp);

// 4) Print everything so you can see it in the browser/CLI
header('Content-Type: text/plain');
echo "CMD:\n$cmd\n\nRC: $rc\n\n--- STDOUT ---\n$stdout\n\n--- STDERR ---\n$stderr\n";

