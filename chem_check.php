<?php
// chem_check.php — standalone, JSON-only SMILES detector
declare(strict_types=1);

// JSON headers first, and never emit anything else
header_remove();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// If this endpoint should require auth, keep the session gate:
@session_start();

// Read JSON body
$raw = file_get_contents('php://input');
if (!is_string($raw) || $raw === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Empty request body.']);
    exit;
}
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['text'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Expected JSON { "text": "<candidate>" }']);
    exit;
}

$text = trim((string)$body['text']);
$len  = strlen($text);

// Heuristics: length, no whitespace, allowed charset, balanced (), simple ring digits
if ($len < 1 || $len > 1024) { echo json_encode(['ok'=>true,'is_probable'=>false]); exit; }
if (preg_match('/\s/u', $text)) { echo json_encode(['ok'=>true,'is_probable'=>false]); exit; }
if (!preg_match('/^[A-Za-z0-9#%=\+\-\[\]\(\)@\/\\\\\.]+$/', $text)) {
    echo json_encode(['ok'=>true,'is_probable'=>false]); exit;
}
// Paren balance/depth
$depth = 0; $maxDepth = 0;
for ($i=0,$n=strlen($text); $i<$n; $i++) {
    $c = $text[$i];
    if ($c==='(') { $depth++; if ($depth>$maxDepth) $maxDepth=$depth; if ($maxDepth>16){ echo json_encode(['ok'=>true,'is_probable'=>false]); exit; } }
    if ($c===')') { $depth--; if ($depth<0){ echo json_encode(['ok'=>true,'is_probable'=>false]); exit; } }
}
if ($depth !== 0) { echo json_encode(['ok'=>true,'is_probable'=>false]); exit; }
// Ring-digit sanity (ignore %nn)
$masked = preg_replace('/%[0-9]{2}/', '', $text);
preg_match_all('/[0-9]/', $masked, $m);
if (!empty($m[0])) {
    $counts = array_count_values($m[0]);
    foreach ($counts as $cnt) { if ($cnt % 2 !== 0) { echo json_encode(['ok'=>true,'is_probable'=>false]); exit; } }
}

echo json_encode(['ok'=>true,'is_probable'=>true]);
exit;

