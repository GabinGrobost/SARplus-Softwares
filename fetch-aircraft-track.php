<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use GET.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allAircraft = isset($_GET['all']) && ($_GET['all'] === '1' || strtolower((string)$_GET['all']) === 'true');
$registration = strtoupper(trim((string)($_GET['registration'] ?? '')));
if (!$allAircraft && $registration === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing registration query parameter.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function http_get_json(string $url, int $timeout = 8): ?array
{
    $headers = [
        'Accept: application/json',
        'User-Agent: CartoFLU/1.0 (+opensky-network +adsb.lol)'
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) return null;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $status < 200 || $status >= 300) return null;
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => implode("\r\n", $headers) . "\r\n",
        ]
    ]);
    $raw = @file_get_contents($url, false, $context);
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

function pick_first_aircraft(array $payload): ?array
{
    $candidates = [];
    foreach (['ac', 'aircraft', 'data'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $candidates = $payload[$key];
            break;
        }
    }
    if ($candidates === [] && array_key_exists('hex', $payload)) {
        $candidates = [$payload];
    }

    foreach ($candidates as $entry) {
        if (is_array($entry)) return $entry;
    }
    return null;
}

function point_from_entry(array $entry): ?array
{
    $lat = isset($entry['lat']) ? (float)$entry['lat'] : (isset($entry['latitude']) ? (float)$entry['latitude'] : NAN);
    $lon = isset($entry['lon']) ? (float)$entry['lon'] : (isset($entry['lng']) ? (float)$entry['lng'] : (isset($entry['longitude']) ? (float)$entry['longitude'] : NAN));
    if (!is_finite($lat) || !is_finite($lon)) return null;
    return ['lat' => $lat, 'lon' => $lon];
}

function normalize_track_points($raw): array
{
    if (!is_array($raw)) return [];
    $points = [];
    foreach ($raw as $item) {
        if (is_array($item) && array_is_list($item) && count($item) >= 2) {
            $lat = (float)$item[0];
            $lon = (float)$item[1];
            if (is_finite($lat) && is_finite($lon)) $points[] = ['lat' => $lat, 'lon' => $lon];
            continue;
        }
        if (is_array($item)) {
            $p = point_from_entry($item);
            if ($p !== null) $points[] = $p;
        }
    }
    return $points;
}

function normalize_all_aircraft(array $payload, int $limit = 250): array
{
    $entries = [];
    foreach (['ac', 'aircraft', 'data'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $entries = $payload[$key];
            break;
        }
    }

    $out = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) continue;
        $point = point_from_entry($entry);
        if ($point === null) continue;
        $out[] = [
            'lat' => $point['lat'],
            'lon' => $point['lon'],
            'registration' => strtoupper(trim((string)($entry['r'] ?? $entry['registration'] ?? ''))),
            'icao24' => strtolower(trim((string)($entry['hex'] ?? $entry['icao24'] ?? ''))),
            'callsign' => strtoupper(trim((string)($entry['flight'] ?? $entry['callsign'] ?? ''))),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}


function normalize_opensky_states(array $payload, int $limit = 300): array
{
    if (!isset($payload['states']) || !is_array($payload['states'])) return [];
    $out = [];
    foreach ($payload['states'] as $state) {
        if (!is_array($state)) continue;
        $lon = isset($state[5]) ? (float)$state[5] : NAN;
        $lat = isset($state[6]) ? (float)$state[6] : NAN;
        if (!is_finite($lat) || !is_finite($lon)) continue;
        $out[] = [
            'lat' => $lat,
            'lon' => $lon,
            'registration' => '',
            'icao24' => strtolower(trim((string)($state[0] ?? ''))),
            'callsign' => strtoupper(trim((string)($state[1] ?? ''))),
        ];
        if (count($out) >= $limit) break;
    }
    return $out;
}

if ($allAircraft) {
    $snapshot = http_get_json('https://api.adsb.lol/v2/snapshot', 10);
    $all = is_array($snapshot) ? normalize_all_aircraft($snapshot, 300) : [];
    $source = 'adsb.lol';

    if ($all === []) {
        $opensky = http_get_json('https://opensky-network.org/api/states/all', 10);
        $all = is_array($opensky) ? normalize_opensky_states($opensky, 300) : [];
        $source = 'opensky-network';
    }

    if ($all === []) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Unable to fetch global aircraft snapshot'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'plannedTrack' => [],
        'pastTrack' => [],
        'currentTrack' => [],
        'currentPosition' => null,
        'radarLost' => false,
        'lastKnownPosition' => null,
        'allAircraft' => $all,
        'source' => [
            'primary' => $source,
            'mode' => 'snapshot'
        ],
        'updatedAt' => gmdate('c')
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$adsbUrl = 'https://api.adsb.lol/v2/registration/' . rawurlencode($registration);
$adsbPayload = http_get_json($adsbUrl, 9);
$adsbAircraft = is_array($adsbPayload) ? pick_first_aircraft($adsbPayload) : null;

if (!$adsbAircraft) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Aircraft not found from ADSB.lol',
        'registration' => $registration
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$icao24 = strtolower(trim((string)($adsbAircraft['hex'] ?? $adsbAircraft['icao24'] ?? '')));
$currentPosition = point_from_entry($adsbAircraft);
$pastTrack = normalize_track_points($adsbAircraft['track'] ?? $adsbAircraft['trail'] ?? $adsbAircraft['history'] ?? []);
$radarLost = $currentPosition === null;
$lastKnown = $pastTrack !== [] ? $pastTrack[count($pastTrack) - 1] : null;

if ($icao24 !== '') {
    $openSkyUrl = 'https://opensky-network.org/api/states/all?icao24=' . rawurlencode($icao24);
    $openSkyPayload = http_get_json($openSkyUrl, 9);
    if (is_array($openSkyPayload) && isset($openSkyPayload['states']) && is_array($openSkyPayload['states']) && isset($openSkyPayload['states'][0]) && is_array($openSkyPayload['states'][0])) {
        $state = $openSkyPayload['states'][0];
        $lat = isset($state[6]) ? (float)$state[6] : NAN;
        $lon = isset($state[5]) ? (float)$state[5] : NAN;
        if (is_finite($lat) && is_finite($lon)) {
            $currentPosition = ['lat' => $lat, 'lon' => $lon];
            $radarLost = false;
            if ($pastTrack === [] || (abs($pastTrack[count($pastTrack) - 1]['lat'] - $lat) > 0.000001 || abs($pastTrack[count($pastTrack) - 1]['lon'] - $lon) > 0.000001)) {
                $pastTrack[] = ['lat' => $lat, 'lon' => $lon];
            }
        }
    }
}

if ($currentPosition === null && $lastKnown !== null) {
    $radarLost = true;
}

$response = [
    'plannedTrack' => [],
    'pastTrack' => $pastTrack,
    'currentTrack' => $currentPosition ? [$currentPosition] : [],
    'currentPosition' => $currentPosition,
    'radarLost' => $radarLost,
    'lastKnownPosition' => $lastKnown,
    'source' => [
        'primary' => 'adsb.lol',
        'fallback' => 'opensky-network',
        'icao24' => $icao24,
    ],
    'updatedAt' => gmdate('c')
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
