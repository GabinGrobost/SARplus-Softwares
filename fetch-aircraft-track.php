<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    emit_json(['ok' => false, 'error' => 'Method not allowed. Use GET.'], 405);
}

$allAircraft = isset($_GET['all']) && ($_GET['all'] === '1' || strtolower((string)$_GET['all']) === 'true');
$registration = strtoupper(trim((string)($_GET['registration'] ?? '')));

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) return false;
            $expectedKey++;
        }
        return true;
    }
}

if (!$allAircraft && $registration === '') {
    emit_json(['ok' => false, 'error' => 'Missing registration query parameter.'], 400);
}


function emit_json(array $payload, int $statusCode = 200): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function http_get_json_first(array $urls, int $timeout = 8, ?string &$resolvedUrl = null): ?array
{
    foreach ($urls as $url) {
        if (!is_string($url) || trim($url) === '') continue;
        $payload = http_get_json($url, $timeout);
        if (is_array($payload)) {
            $resolvedUrl = $url;
            return $payload;
        }
    }
    $resolvedUrl = null;
    return null;
}

function fetch_json_from_urls(array $urls, int $timeout = 8): array
{
    $out = [];
    foreach ($urls as $url) {
        if (!is_string($url) || trim($url) === '') continue;
        $payload = http_get_json($url, $timeout);
        if (is_array($payload)) {
            $out[] = ['url' => $url, 'payload' => $payload];
        }
    }
    return $out;
}

function aircraft_candidates_from_payload(array $payload): array
{
    foreach (['ac', 'aircraft', 'data', 'response', 'list'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }
    if (array_key_exists('hex', $payload) || array_key_exists('icao24', $payload)) {
        return [$payload];
    }
    return [];
}

function registration_from_entry(array $entry): string
{
    return strtoupper(trim((string)($entry['r'] ?? $entry['reg'] ?? $entry['registration'] ?? '')));
}

function pick_first_aircraft(array $payload, string $registration = ''): ?array
{
    $candidates = aircraft_candidates_from_payload($payload);
    $needle = strtoupper(trim($registration));

    if ($needle !== '') {
        foreach ($candidates as $entry) {
            if (is_array($entry) && registration_from_entry($entry) === $needle) {
                return $entry;
            }
        }
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

    if (!is_finite($lat) || !is_finite($lon)) {
        if (isset($entry['lastPosition']) && is_array($entry['lastPosition'])) {
            $last = $entry['lastPosition'];
            $lat = isset($last['lat']) ? (float)$last['lat'] : (isset($last['latitude']) ? (float)$last['latitude'] : NAN);
            $lon = isset($last['lon']) ? (float)$last['lon'] : (isset($last['lng']) ? (float)$last['lng'] : (isset($last['longitude']) ? (float)$last['longitude'] : NAN));
        }
    }

    if (!is_finite($lat) || !is_finite($lon)) {
        $lat = isset($entry['rr_lat']) ? (float)$entry['rr_lat'] : NAN;
        $lon = isset($entry['rr_lon']) ? (float)$entry['rr_lon'] : NAN;
    }

    if (!is_finite($lat) || !is_finite($lon)) return null;
    return ['lat' => $lat, 'lon' => $lon];
}

function seen_pos_from_entry(array $entry): ?float
{
    if (isset($entry['seen_pos']) && is_numeric($entry['seen_pos'])) {
        return (float)$entry['seen_pos'];
    }
    if (isset($entry['seen']) && is_numeric($entry['seen'])) {
        return (float)$entry['seen'];
    }
    return null;
}

function distance_nm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $deltaLat = deg2rad($lat2 - $lat1);
    $deltaLon = deg2rad($lon2 - $lon1);

    $a = sin($deltaLat / 2.0) ** 2
        + cos($lat1Rad) * cos($lat2Rad) * (sin($deltaLon / 2.0) ** 2);
    $c = 2.0 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
    $distanceKm = $earthRadiusKm * $c;
    return $distanceKm / 1.852;
}

function stable_position_from_candidates(array $candidates): ?array
{
    $positions = [];
    foreach ($candidates as $candidate) {
        if (!isset($candidate['position']) || !is_array($candidate['position'])) continue;
        $p = $candidate['position'];
        if (!isset($p['lat'], $p['lon'])) continue;
        $positions[] = [
            'lat' => (float)$p['lat'],
            'lon' => (float)$p['lon'],
            'seen_pos' => isset($candidate['seen_pos']) && is_numeric($candidate['seen_pos']) ? (float)$candidate['seen_pos'] : null
        ];
    }
    if ($positions === []) return null;
    if (count($positions) === 1) return ['lat' => $positions[0]['lat'], 'lon' => $positions[0]['lon'], 'consensus' => 1];

    $consensusRadiusNm = 1.2;
    $best = null;
    foreach ($positions as $pivot) {
        $cluster = [];
        foreach ($positions as $other) {
            $dist = distance_nm($pivot['lat'], $pivot['lon'], $other['lat'], $other['lon']);
            if ($dist <= $consensusRadiusNm) {
                $cluster[] = $other;
            }
        }
        if ($best === null || count($cluster) > count($best)) {
            $best = $cluster;
        }
    }

    if (is_array($best) && count($best) >= 2) {
        $sumLat = 0.0;
        $sumLon = 0.0;
        foreach ($best as $member) {
            $sumLat += $member['lat'];
            $sumLon += $member['lon'];
        }
        return [
            'lat' => $sumLat / count($best),
            'lon' => $sumLon / count($best),
            'consensus' => count($best)
        ];
    }

    usort($positions, function (array $a, array $b): int {
        $av = isset($a['seen_pos']) && $a['seen_pos'] !== null ? (float)$a['seen_pos'] : INF;
        $bv = isset($b['seen_pos']) && $b['seen_pos'] !== null ? (float)$b['seen_pos'] : INF;
        return $av <=> $bv;
    });

    return ['lat' => $positions[0]['lat'], 'lon' => $positions[0]['lon'], 'consensus' => 1];
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
            'registration' => registration_from_entry($entry),
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

function empty_aircraft_response(string $registration = '', string $reason = 'no-data', array $source = []): void
{
    emit_json([
        'ok' => true,
        'registration' => $registration,
        'plannedTrack' => [],
        'pastTrack' => [],
        'currentTrack' => [],
        'currentPosition' => null,
        'radarLost' => false,
        'lastKnownPosition' => null,
        'allAircraft' => [],
        'noData' => true,
        'reason' => $reason,
        'source' => $source,
        'updatedAt' => gmdate('c')
    ]);
}

if ($allAircraft) {
    $lat = isset($_GET['lat']) ? (float)$_GET['lat'] : 47.0;
    $lon = isset($_GET['lon']) ? (float)$_GET['lon'] : 2.0;
    $radiusNm = isset($_GET['radius']) ? (float)$_GET['radius'] : 250.0;
    if (!is_finite($lat) || !is_finite($lon)) {
        emit_json(['ok' => false, 'error' => 'Invalid lat/lon parameters'], 400);
    }
    $radiusNm = max(10.0, min(400.0, $radiusNm));

    $clampedRadiusNm = max(10.0, min(250.0, $radiusNm));
    $snapshotUrl = null;
    $snapshot = http_get_json_first([
        sprintf('https://opendata.adsb.fi/api/v3/lat/%s/lon/%s/dist/%s', rawurlencode((string)$lat), rawurlencode((string)$lon), rawurlencode((string)$clampedRadiusNm)),
        sprintf('https://api.airplanes.live/v2/point/%s/%s/%s', rawurlencode((string)$lat), rawurlencode((string)$lon), rawurlencode((string)$clampedRadiusNm)),
        sprintf('https://api.adsb.lol/v2/point/%s/%s/%s', rawurlencode((string)$lat), rawurlencode((string)$lon), rawurlencode((string)$clampedRadiusNm))
    ], 10, $snapshotUrl);
    $all = is_array($snapshot) ? normalize_all_aircraft($snapshot, 300) : [];
    $source = $snapshotUrl && str_contains($snapshotUrl, 'opendata.adsb.fi')
        ? 'adsb.fi-opendata'
        : ($snapshotUrl && str_contains($snapshotUrl, 'airplanes.live') ? 'airplanes.live' : ($snapshotUrl ? 'adsb.lol' : ''));

    if ($all === []) {
        $deltaLat = $radiusNm / 60.0;
        $deltaLon = $radiusNm / max(10.0, (60.0 * cos(deg2rad($lat))));
        $openskyUrl = sprintf(
            'https://opensky-network.org/api/states/all?lamin=%s&lomin=%s&lamax=%s&lomax=%s',
            rawurlencode((string)($lat - $deltaLat)),
            rawurlencode((string)($lon - $deltaLon)),
            rawurlencode((string)($lat + $deltaLat)),
            rawurlencode((string)($lon + $deltaLon))
        );
        $opensky = http_get_json($openskyUrl, 10);
        $all = is_array($opensky) ? normalize_opensky_states($opensky, 300) : [];
        $source = $all !== [] ? 'opensky-network' : ($source !== '' ? $source : 'opensky-network');
    }

    if ($all === []) {
        empty_aircraft_response('', 'global-snapshot-unavailable', [
            'primary' => $source,
            'mode' => 'point-snapshot',
            'query' => ['lat' => $lat, 'lon' => $lon, 'radiusNm' => $radiusNm]
        ]);
    }

    emit_json([
        'ok' => true,
        'noData' => false,
        'registration' => '',
        'plannedTrack' => [],
        'pastTrack' => [],
        'currentTrack' => [],
        'currentPosition' => null,
        'radarLost' => false,
        'lastKnownPosition' => null,
        'allAircraft' => $all,
        'source' => [
            'primary' => $source,
            'mode' => 'point-snapshot',
            'query' => ['lat' => $lat, 'lon' => $lon, 'radiusNm' => $radiusNm]
        ],
        'updatedAt' => gmdate('c')
    ]);
}

$registrationLookupUrls = [
    'https://opendata.adsb.fi/api/v2/registration/' . rawurlencode($registration),
    'https://api.airplanes.live/v2/reg/' . rawurlencode($registration),
    'https://api.adsb.lol/v2/registration/' . rawurlencode($registration)
];
$registrationLookups = fetch_json_from_urls($registrationLookupUrls, 9);

$adsbAircraft = null;
$adsbSource = '';
$aircraftCandidates = [];
foreach ($registrationLookups as $lookup) {
    $url = (string)($lookup['url'] ?? '');
    $payload = isset($lookup['payload']) && is_array($lookup['payload']) ? $lookup['payload'] : [];
    $entry = pick_first_aircraft($payload, $registration);
    if (!is_array($entry)) continue;

    $sourceLabel = str_contains($url, 'opendata.adsb.fi')
        ? 'adsb.fi-opendata'
        : (str_contains($url, 'airplanes.live') ? 'airplanes.live' : 'adsb.lol');
    $aircraftCandidates[] = [
        'source' => $sourceLabel,
        'entry' => $entry,
        'position' => point_from_entry($entry),
        'seen_pos' => seen_pos_from_entry($entry),
    ];
    if ($adsbAircraft === null) {
        $adsbAircraft = $entry;
        $adsbSource = $sourceLabel;
    }
}

if (!$adsbAircraft) {
    empty_aircraft_response($registration, 'aircraft-not-found', [
        'primary' => $adsbSource !== '' ? $adsbSource : 'adsb-registration-lookup',
        'fallback' => 'opensky-network'
    ]);
}

$icao24 = strtolower(trim((string)($adsbAircraft['hex'] ?? $adsbAircraft['icao24'] ?? '')));
$currentPosition = point_from_entry($adsbAircraft);
$pastTrack = normalize_track_points($adsbAircraft['track'] ?? $adsbAircraft['trail'] ?? $adsbAircraft['history'] ?? []);
$radarLost = $currentPosition === null;
$lastKnown = $pastTrack !== [] ? $pastTrack[count($pastTrack) - 1] : null;
$stable = stable_position_from_candidates($aircraftCandidates);
if (is_array($stable) && isset($stable['lat'], $stable['lon'])) {
    $currentPosition = ['lat' => (float)$stable['lat'], 'lon' => (float)$stable['lon']];
    if ($pastTrack === [] || (abs($pastTrack[count($pastTrack) - 1]['lat'] - $currentPosition['lat']) > 0.000001 || abs($pastTrack[count($pastTrack) - 1]['lon'] - $currentPosition['lon']) > 0.000001)) {
        $pastTrack[] = $currentPosition;
    }
    $radarLost = false;
}

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
    'ok' => true,
    'registration' => $registration,
    'noData' => false,
    'plannedTrack' => [],
    'pastTrack' => $pastTrack,
    'currentTrack' => $currentPosition ? [$currentPosition] : [],
    'currentPosition' => $currentPosition,
    'radarLost' => $radarLost,
    'lastKnownPosition' => $lastKnown,
    'source' => [
        'primary' => $adsbSource !== '' ? $adsbSource : 'adsb-registration-lookup',
        'fallback' => 'opensky-network',
        'icao24' => $icao24,
        'crossCheckSources' => array_values(array_unique(array_map(function (array $row): string {
            return (string)($row['source'] ?? '');
        }, $aircraftCandidates))),
        'crossCheckCount' => count($aircraftCandidates),
        'positionConsensus' => is_array($stable) ? (int)($stable['consensus'] ?? 1) : 0,
    ],
    'callsign' => strtoupper(trim((string)($adsbAircraft['flight'] ?? $adsbAircraft['callsign'] ?? ''))),
    'altitude' => $adsbAircraft['alt_baro'] ?? $adsbAircraft['alt_geom'] ?? $adsbAircraft['altitude'] ?? null,
    'speed' => $adsbAircraft['gs'] ?? $adsbAircraft['tas'] ?? $adsbAircraft['ias'] ?? $adsbAircraft['speed'] ?? null,
    'heading' => $adsbAircraft['track'] ?? $adsbAircraft['heading'] ?? $adsbAircraft['hdg'] ?? null,
    'squawk' => $adsbAircraft['squawk'] ?? null,
    'aircraftType' => strtoupper(trim((string)($adsbAircraft['t'] ?? $adsbAircraft['type'] ?? ''))),
    'aircraftDescription' => trim((string)($adsbAircraft['desc'] ?? $adsbAircraft['aircraft'] ?? '')),
    'aircraftCategory' => trim((string)($adsbAircraft['category'] ?? '')),
    'operator' => trim((string)($adsbAircraft['ownOp'] ?? $adsbAircraft['operator'] ?? '')),
    'origin' => strtoupper(trim((string)($adsbAircraft['from'] ?? $adsbAircraft['origin'] ?? ''))),
    'destination' => strtoupper(trim((string)($adsbAircraft['to'] ?? $adsbAircraft['destination'] ?? ''))),
    'meta' => [
        'seen' => $adsbAircraft['seen'] ?? null,
        'seen_pos' => $adsbAircraft['seen_pos'] ?? null,
        'baro_rate' => $adsbAircraft['baro_rate'] ?? null,
        'geom_rate' => $adsbAircraft['geom_rate'] ?? null,
        'emergency' => $adsbAircraft['emergency'] ?? null,
        'nav_qnh' => $adsbAircraft['nav_qnh'] ?? null,
    ],
    'updatedAt' => gmdate('c')
];

emit_json($response);
