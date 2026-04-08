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

function writeJsonAtomic(string $target, array $payload): void
{
    $tmp = $target . '.tmp';
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException('Unable to encode JSON for storage.');
    }

    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write temporary file.');
    }

    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('Unable to publish JSON file.');
    }
}

$activeTarget = __DIR__ . DIRECTORY_SEPARATOR . 'op-active.json';
$inactiveTarget = __DIR__ . DIRECTORY_SEPARATOR . 'op-inactive.json';

try {
    writeJsonAtomic($activeTarget, $data);

    $operation = is_array($data['operation'] ?? null) ? $data['operation'] : null;
    $operationRef = strtoupper(trim((string)($operation['ref'] ?? '')));
    $isClosingPayload = (($data['active'] ?? true) === false) && preg_match('/^\d{10}$/', $operationRef);

    if ($isClosingPayload) {
        $inactiveData = [
            'version' => 1,
            'kind' => 'op-inactive',
            'app' => 'CartoFLU',
            'updatedAt' => null,
            'operations' => []
        ];

        if (is_file($inactiveTarget)) {
            $existing = json_decode((string) file_get_contents($inactiveTarget), true);
            if (is_array($existing)) {
                $inactiveData = array_merge($inactiveData, $existing);
                if (!is_array($inactiveData['operations'] ?? null)) {
                    $inactiveData['operations'] = [];
                }
            }
        }

        $record = [
            'ref' => $operationRef,
            'name' => (string)($operation['name'] ?? ''),
            'type' => (string)($operation['type'] ?? ''),
            'exercise' => (string)($operation['exercise'] ?? 'non'),
            'sizing' => (string)($operation['sizing'] ?? ''),
            'openingAuthority' => (string)($operation['openingAuthority'] ?? 'non'),
            'openingAuthorityLabel' => (string)($operation['openingAuthorityLabel'] ?? ''),
            'mode' => (string)($operation['mode'] ?? ''),
            'createdAt' => (string)($operation['createdAt'] ?? ''),
            'closedAt' => (string)($operation['closedAt'] ?? gmdate('c')),
            'updatedAt' => (string)($data['updatedAt'] ?? gmdate('c')),
            'rollcall' => is_array($data['rollcall'] ?? null) ? $data['rollcall'] : [
                'totalStations' => 0,
                'presentCount' => 0,
                'presentCallsigns' => [],
                'stations' => []
            ],
            'session' => is_array($data['session'] ?? null) ? $data['session'] : [
                'bearings' => [],
                'negListenings' => [],
                'balise' => null,
                'intersection' => null
            ],
            'sync' => is_array($data['sync'] ?? null) ? $data['sync'] : [],
            'snapshot' => $data
        ];

        $operations = array_values(array_filter(
            $inactiveData['operations'],
            static fn(array $op): bool => strtoupper(trim((string)($op['ref'] ?? ''))) !== $operationRef
        ));
        $operations[] = $record;
        usort($operations, static function (array $a, array $b): int {
            return strcmp((string)($b['closedAt'] ?? ''), (string)($a['closedAt'] ?? ''));
        });

        $inactiveData['version'] = 1;
        $inactiveData['kind'] = 'op-inactive';
        $inactiveData['app'] = 'CartoFLU';
        $inactiveData['updatedAt'] = gmdate('c');
        $inactiveData['operations'] = $operations;

        writeJsonAtomic($inactiveTarget, $inactiveData);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'file' => 'op-active.json',
    'updatedAt' => gmdate('c')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
