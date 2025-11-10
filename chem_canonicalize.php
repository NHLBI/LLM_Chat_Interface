<?php
declare(strict_types=1);

// JSON only
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// --- read & validate body ---
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Empty request body.']); exit; }
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['smiles'])) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Expected JSON { "smiles": "<text>" }']); exit; }

$smiles = trim((string)$body['smiles']);

// same guards as chem_check
if ($smiles === '' || strlen($smiles) > 1024 || preg_match('/\s/u', $smiles) ||
    !preg_match('/^[A-Za-z0-9#%=\+\-\[\]\(\)@\/\\\\\.]+$/', $smiles)) {
    echo json_encode(['ok'=>true,'canonical'=>$smiles,'inchi'=>null,'inchikey'=>null,'via'=>'noop']); exit;
}

// --- if we can’t exec, return noop (OK) ---
$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$proc_ok = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
if (!$proc_ok) {
    echo json_encode(['ok'=>true,'canonical'=>$smiles,'inchi'=>null,'inchikey'=>null,'via'=>'noop','note'=>'proc_open disabled']); exit;
}

// --- run python helper with blocking reads ---
$cmd = escapeshellcmd('/usr/bin/env').' '.escapeshellarg('python3').' '.escapeshellarg(__DIR__.'/inc/chem_canon.py');
$desc = [ 0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w'] ];
$proc = @proc_open($cmd, $desc, $pipes, __DIR__);
if (!is_resource($proc)) {
    error_log('chem_canonicalize: proc_open failed');
    echo json_encode(['ok'=>true,'canonical'=>$smiles,'inchi'=>null,'inchikey'=>null,'via'=>'noop','note'=>'proc_open failed']); exit;
}

// send JSON to stdin
$payload = json_encode(['smiles'=>$smiles], JSON_UNESCAPED_SLASHES);
fwrite($pipes[0], $payload);
fclose($pipes[0]);

// BLOCKING reads (simpler & more portable)
stream_set_blocking($pipes[1], true);
stream_set_blocking($pipes[2], true);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$rc = proc_close($proc);

// if nothing came back, fall back (OK)
$out = trim((string)$stdout);
if ($out === '') {
    error_log('chem_canonicalize: empty stdout rc='.$rc.' stderr='.trim((string)$stderr));
    echo json_encode(['ok'=>true,'canonical'=>$smiles,'inchi'=>null,'inchikey'=>null,'via'=>'noop','note'=>'empty-stdout']); exit;
}

// parse python JSON; fall back on any oddity
$resp = json_decode($out, true);
if (!is_array($resp)) {
    error_log('chem_canonicalize: bad JSON: '.$out);
    echo json_encode(['ok'=>true,'canonical'=>$smiles,'inchi'=>null,'inchikey'=>null,'via'=>'noop','note'=>'bad-json']); exit;
}

// toolkit path
if (!empty($resp['ok'])) {
    echo json_encode([
        'ok'        => true,
        'canonical' => $resp['canonical'] ?? $smiles,
        'inchi'     => $resp['inchi'] ?? null,
        'inchikey'  => $resp['inchikey'] ?? null,
        'via'       => $resp['via'] ?? 'noop'
    ]);
    exit;
}

// invalid (only if toolkit actually said so)
http_response_code(400);
echo json_encode(['ok'=>false,'message'=>$resp['message'] ?? 'invalid SMILES']); exit;

