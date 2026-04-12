<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'ok' => true,
        'message' => 'Interconnect proxy is reachable.',
        'hint' => 'Send a POST JSON body with action=publish or action=list-active.',
        'example' => [
            'action' => 'list-active',
            'envelope' => [
                'version' => 1,
                'kind' => 'operation-interconnect-query',
                'app' => 'CartoFLU',
                'updatedAt' => gmdate('c'),
                'entity' => ['name' => 'ADRASEC 25', 'departement' => '25'],
                'source' => ['path' => '/', 'mode' => 'connected', 'syncSource' => 'join-operation'],
                'payload' => ['excludeEntity' => 'ADRASEC 25', 'excludeDepartement' => '25']
            ]
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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

$configFile = __DIR__ . DIRECTORY_SEPARATOR . 'config.json';
$config = [];
if (is_file($configFile)) {
    $decoded = json_decode((string)file_get_contents($configFile), true);
    if (is_array($decoded)) $config = $decoded;
}

$remoteUrl = trim((string)($config['interconnect-api-url'] ?? $config['interconnectApiUrl'] ?? ''));
if ($remoteUrl === '' || !preg_match('#^https?://#i', $remoteUrl)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Interconnect API URL is missing in config.json.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$timeoutMs = (int)($config['interconnect-fetch-timeout-ms'] ?? $config['interconnectFetchTimeoutMs'] ?? 8000);
if ($timeoutMs < 1000) $timeoutMs = 1000;
if ($timeoutMs > 60000) $timeoutMs = 60000;
$connectTimeoutMs = (int)min(5000, max(500, (int)floor($timeoutMs / 2)));

$action = (string)($data['action'] ?? 'publish');
$envelope = is_array($data['envelope'] ?? null) ? $data['envelope'] : [];

$forwardPayload = [
    'action' => $action,
    'envelope' => $envelope
];

$ch = curl_init($remoteUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($forwardPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
    CURLOPT_TIMEOUT_MS => $timeoutMs
]);
$responseBody = curl_exec($ch);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($responseBody === false) {
    http_response_code($curlErrno === 28 ? 504 : 502);
    echo json_encode([
        'ok' => false,
        'error' => $curlErrno === 28 ? 'Interconnect API request timed out.' : 'Unable to reach interconnect API.',
        'detail' => $curlError
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$decodedResponse = json_decode((string)$responseBody, true);
if (!is_array($decodedResponse)) {
    $decodedResponse = [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'raw' => (string)$responseBody
    ];
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode > 0 ? $httpCode : 502);
}

echo json_encode($decodedResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
