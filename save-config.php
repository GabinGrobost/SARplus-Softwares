<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$target = __DIR__ . DIRECTORY_SEPARATOR . 'config.json';
$cfg = [];
if (is_file($target)) {
    $existing = json_decode((string)file_get_contents($target), true);
    if (is_array($existing)) $cfg = $existing;
}

if (array_key_exists('aprsfi-api-key', $data)) {
    $cfg['aprsfi-api-key'] = (string)$data['aprsfi-api-key'];
}

if (!array_key_exists('entity-name', $cfg)) $cfg['entity-name'] = 'ADRASEC 25';
if (!array_key_exists('force-offline', $cfg)) $cfg['force-offline'] = false;
if (!array_key_exists('maintenance', $cfg) && !array_key_exists('mantenance', $cfg)) $cfg['maintenance'] = false;
if (!array_key_exists('software-version', $cfg)) $cfg['software-version'] = '1.0';

$json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to encode config JSON.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tmp = $target . '.tmp';
if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $target)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to write config.json.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode(['ok' => true, 'file' => 'config.json', 'updatedAt' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
