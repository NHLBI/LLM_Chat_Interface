<?php
declare(strict_types=1);

// JSON only
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!function_exists('smiles_debug_log')) {
    function smiles_debug_log(string $message): void
    {
        $logFile = __DIR__ . '/logs/smiles_debug.log';
        $line = date('c') . ' [chem_render_png] ' . $message . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}

// Read JSON
$raw = file_get_contents('php://input');
smiles_debug_log('raw_len=' . (is_string($raw) ? strlen($raw) : 0));
if (!is_string($raw) || $raw === '') { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Empty body']); exit; }
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['smiles'])) { http_response_code(400); echo json_encode(['ok'=>false,'message'=>'Expected {"smiles": "..."}']); exit; }

$smiles = trim((string)$body['smiles']);
smiles_debug_log('smiles=' . $smiles);

// Guards (same as chem_check)
if ($smiles === '' || strlen($smiles) > 1024 || preg_match('/\s/u', $smiles) ||
    !preg_match('/^[A-Za-z0-9#%=\+\-\[\]\(\)@\/\\\\\.]+$/', $smiles)) {
    smiles_debug_log('guard_reject');
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>'Not SMILES-like']); exit;
}

// Cache path (under /var, served via chem_png.php)
$hash = sha1(strtolower($smiles));
$pngDir = __DIR__ . '/var/chem/png';
$pngPath = $pngDir . '/' . $hash . '.png';

// If cached with reasonable dimensions, return immediately. Otherwise, discard stale placeholders.
if (is_file($pngPath) && filesize($pngPath) > 0) {
$info = @getimagesize($pngPath);
    if ($info && $info[0] >= 256 && $info[1] >= 256) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        smiles_debug_log('cache_hit hash=' . $hash . ' size=' . filesize($pngPath));
        echo json_encode(['ok'=>true,'png_url'=> $base . '/chem_png.php?h=' . $hash, 'via'=>'cache']);
        exit;
    }
    smiles_debug_log('cache_stale hash=' . $hash);
    @unlink($pngPath);
}

// If we can’t exec, write a placeholder ourselves (same tiny PNG) and return
if (!defined('SMILES_PLACEHOLDER_BASE64')) {
    define('SMILES_PLACEHOLDER_BASE64', 'iVBORw0KGgoAAAANSUhEUgAAAUAAAADwCAIAAAD+Tyo8AAAEx0lEQVR4nO3aO07kWABAUTNiLSRILIOcFbA2VkDOCgiIQCSspoMalTxluz646OKOzgla9bGfbcm3nm366vXtYwCarodhuLu9ufRuACd7//z659L7AHyfgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjAB/+v+4fH+4fHSewGnuV65/vSkf3l+2n718vw0XmD6ds8I0yX3bHe6wKnWjwB/39Xr28fd7c33Vt5UtO1t2+f4xc5i07cHP9mMvGfrSy+OeT37mzKMfiB2ltw5NLig98+vM1xCby4+15/Q04vYU0ee/l4c80Pw8vy08/nBtdTLL7Eq4PGpP27vezeT49GWRp6anQ83b2dHmL0o2DPyzlv3yfwqqwI+Jp79q89OuUsjz5rOn0vLnDptjtcy5fI7neEeeGP2XnH/Q6wlS/elezb9jQ/3zPBLD9u2B3XwEOAveP/8WhUwcEHneYgFXIqAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGsOtL78Bvcf/wOAzDy/PT9vXG5pPZhXe+PbgWnN2qgP9Pp+x4/6cZTxeefntwLTi7VQFvz+PZc3ebxHS+2q6182I4Ysbb+dUY78DSkkv7eeR8u3QUw3/n7ePXgnM55z3wzjm6OXen5SzVu2cGm57924VnRx5/tWO6rZfnp4N17b/AXpp1Dx4XrHT+h1ib83WbxPGn7/pTfH9g4x07dVtrIpQuP+fHn0IfvMjc2syEay41j9nW8fuzfq2N9ccFS65e3z7ubm++t/LsXLe5JB7/u7PW9D52dqiDm9tzI70zzvhy+ifmw6X5efZI4VzeP79WBbzSmmkNeP/8usDfgU1KcC4XCFi0cC7+KyWECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGEChjABQ5iAIUzAECZgCBMwhAkYwgQMYQKGMAFDmIAhTMAQJmAIEzCECRjCBAxhAoYwAUOYgCFMwBAmYAgTMIQJGMIEDGHXwzC8f35dejeA7/gDEA7vhVUZcyYAAAAASUVORK5CYII=');
}

$disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$proc_ok = function_exists('proc_open') && !in_array('proc_open', $disabled, true);
$fallbackImage = base64_decode(SMILES_PLACEHOLDER_BASE64);

if (!$proc_ok) {
    smiles_debug_log('proc_open_disabled');
    @mkdir($pngDir, 0775, true);
    file_put_contents($pngPath, $fallbackImage);
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    echo json_encode(['ok'=>true,'png_url'=> $base . '/chem_png.php?h=' . $hash, 'via'=>'placeholder']);
    exit;
}

// Run python renderer
@mkdir($pngDir, 0775, true);
$cmd = escapeshellcmd('/usr/bin/env') . ' ' . escapeshellarg('python3') . ' ' .
       escapeshellarg(__DIR__ . '/inc/chem_render_png.py');

$desc = [ 0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w'] ];
$proc = @proc_open($cmd, $desc, $pipes, __DIR__);
if (!is_resource($proc)) {
    smiles_debug_log('proc_open_failed');
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    // fallback placeholder
    $placeholder = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGMwMAAAAgAB4iG8MwAAAABJRU5ErkJggg==');
    file_put_contents($pngPath, $placeholder);
    echo json_encode(['ok'=>true,'png_url'=> $base . '/chem_png.php?h=' . $hash, 'via'=>'placeholder']);
    exit;
}

$payload = json_encode(['smiles'=>$smiles, 'out_path'=>$pngPath, 'size'=>[320,240]], JSON_UNESCAPED_SLASHES);
fwrite($pipes[0], $payload); fclose($pipes[0]);

$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]); $rc = proc_close($proc);
smiles_debug_log('python_rc=' . $rc . ' stdout_len=' . strlen((string)$stdout) . ' stderr_len=' . strlen((string)$stderr));
if ($stderr) {
    smiles_debug_log('python_stderr=' . substr($stderr, 0, 500));
}

// If Python didn’t create the file, fallback to placeholder
if (!is_file($pngPath) || filesize($pngPath) === 0) {
    smiles_debug_log('python_missing_file');
    file_put_contents($pngPath, $fallbackImage);
    $via = 'placeholder';
} else {
    // Parse JSON to capture via (rdkit/obabel/placeholder)
    $via = 'unknown';
    $resp = json_decode(trim((string)$stdout), true);
    if (is_array($resp) && !empty($resp['ok']) && !empty($resp['via'])) {
        $via = $resp['via'];
    } else {
        smiles_debug_log('python_resp_unparsed stdout=' . substr((string)$stdout, 0, 500));
    }
}

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$size = @filesize($pngPath);
smiles_debug_log('response via=' . $via . ' size=' . ($size !== false ? $size : -1) . ' hash=' . $hash);
echo json_encode(['ok'=>true,'png_url'=> $base . '/chem_png.php?h=' . $hash, 'via'=>$via]);
