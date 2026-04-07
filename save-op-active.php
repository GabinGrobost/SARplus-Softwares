<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Empty request body.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => 'Invalid JSON payload.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$target = __DIR__ . DIRECTORY_SEPARATOR . 'op-active.json';
$tmp    = $target . '.tmp';

$json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($json === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to encode JSON for storage.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to write temporary file.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!rename($tmp, $target)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to publish op-active.json.'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'file' => 'op-active.json',
    'updatedAt' => gmdate('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
