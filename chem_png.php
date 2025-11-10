<?php
declare(strict_types=1);

// Optional: require session here if you want.
// @session_start();

$h = isset($_GET['h']) ? preg_replace('/[^a-f0-9]/', '', $_GET['h']) : '';
if ($h === '' || strlen($h) !== 40) { http_response_code(404); exit; }

$png = __DIR__ . '/var/chem/png/' . $h . '.png';
if (!is_file($png)) { http_response_code(404); exit; }

header('Content-Type: image/png');
header('Cache-Control: public, max-age=31536000, immutable');
readfile($png);

