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

$localUrlTemplate = (string)($data['localUrlTemplate'] ?? '');
$seedUrlTemplate  = (string)($data['seedUrlTemplate'] ?? '');
$plan = is_array($data['plan'] ?? null) ? $data['plan'] : [];

$minZoom = max(0, min(18, (int)($plan['minZoom'] ?? 6)));
$maxZoom = max($minZoom, min(18, (int)($plan['maxZoom'] ?? 14)));
$boundsRaw = is_array($plan['bounds'] ?? null) ? $plan['bounds'] : [];
$north = (float)($boundsRaw['north'] ?? 51.2);
$south = (float)($boundsRaw['south'] ?? 41.2);
$west = (float)($boundsRaw['west'] ?? -5.8);
$east = (float)($boundsRaw['east'] ?? 9.8);
if ($north < $south) { $tmp = $north; $north = $south; $south = $tmp; }
if ($east < $west) { $tmp = $east; $east = $west; $west = $tmp; }

if ($localUrlTemplate === '' || $seedUrlTemplate === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing localUrlTemplate or seedUrlTemplate.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = (string)parse_url($localUrlTemplate, PHP_URL_PATH);
if ($path === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unable to parse local URL path.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$basePart = $path;
$needle = '/{z}';
$pos = strpos($path, $needle);
if ($pos !== false) {
    $basePart = substr($path, 0, $pos);
} else {
    $basePart = dirname($path);
}

$basePart = trim($basePart, '/');
if ($basePart === '' || str_contains($basePart, '..')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid local storage path.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$targetExt = 'png';
if (preg_match('/\{y\}\.([a-zA-Z0-9]+)/', $path, $m)) {
    $targetExt = strtolower($m[1]);
}

$root = __DIR__;
$localRoot = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $basePart);
if (!is_dir($localRoot) && !mkdir($localRoot, 0775, true) && !is_dir($localRoot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to create local directory.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$downloaded = 0;
$failed = 0;
$errors = [];

function fetchUrlBinary(string $url): string|false {
    $url = str_replace('{s}', 'a', $url);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'CartoFLU/1.0 (+local tile seeding)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
            return $body;
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'follow_location' => 1,
            'user_agent' => 'CartoFLU/1.0 (+local tile seeding)'
        ]
    ]);
    $content = @file_get_contents($url, false, $ctx);
    return (is_string($content) && $content !== '') ? $content : false;
}

function latLngToTile(float $lat, float $lon, int $zoom): array {
    $n = 2 ** $zoom;
    $x = (int)floor((($lon + 180.0) / 360.0) * $n);
    $latRad = deg2rad($lat);
    $y = (int)floor((1.0 - log(tan($latRad) + (1 / cos($latRad))) / M_PI) / 2.0 * $n);
    $x = max(0, min($n - 1, $x));
    $y = max(0, min($n - 1, $y));
    return [$x, $y];
}

set_time_limit(0);
ignore_user_abort(true);

for ($z = $minZoom; $z <= $maxZoom; $z++) {
    [$xMin, $yMin] = latLngToTile($north, $west, $z);
    [$xMax, $yMax] = latLngToTile($south, $east, $z);
    if ($xMax < $xMin) { $tmp = $xMax; $xMax = $xMin; $xMin = $tmp; }
    if ($yMax < $yMin) { $tmp = $yMax; $yMax = $yMin; $yMin = $tmp; }

    for ($tx = $xMin; $tx <= $xMax; $tx++) {
        $targetDir = $localRoot . DIRECTORY_SEPARATOR . $z . DIRECTORY_SEPARATOR . $tx;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            $failed += ($yMax - $yMin + 1);
            continue;
        }

        for ($ty = $yMin; $ty <= $yMax; $ty++) {
            $sourceUrl = str_replace(['{z}', '{x}', '{y}'], [(string)$z, (string)$tx, (string)$ty], $seedUrlTemplate);
            $targetFile = $targetDir . DIRECTORY_SEPARATOR . $ty . '.' . $targetExt;
            if (is_file($targetFile) && filesize($targetFile) > 0) {
                continue;
            }

            $content = fetchUrlBinary($sourceUrl);
            if ($content === false || $content === '') {
                $failed++;
                if (count($errors) < 10) $errors[] = "download_failed:$z/$tx/$ty";
                continue;
            }

            if (@file_put_contents($targetFile, $content, LOCK_EX) === false) {
                $failed++;
                if (count($errors) < 10) $errors[] = "write_failed:$z/$tx/$ty";
                continue;
            }
            $downloaded++;
        }
    }
}

echo json_encode([
    'ok' => true,
    'downloaded' => $downloaded,
    'failed' => $failed,
    'errors' => $errors,
    'path' => $basePart,
    'plan' => [
        'minZoom' => $minZoom,
        'maxZoom' => $maxZoom,
        'bounds' => ['north' => $north, 'south' => $south, 'west' => $west, 'east' => $east]
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
