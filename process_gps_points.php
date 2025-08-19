<?php
declare(strict_types=1);

/**
 * GPS Trip Splitter & GeoJSON Exporter (PHP 8)
 *
 * Input:  data/points.csv (auto-downloaded from DRIVE_URL in .env if missing)
 * Output: data/trips.geojson, data/rejects.log
 *
 * Uses only standard PHP libraries (no Composer, no DB).
 */

const INPUT_FILE    = __DIR__ . '/data/points.csv';
const OUTPUT_FILE   = __DIR__ . '/data/trips.geojson';
const REJECTS_FILE  = __DIR__ . '/data/rejects.log';
const ENV_FILE_PATH = __DIR__ . '/.env';

const TIME_GAP_SECONDS = 25 * 60;   // 25 minutes
const DIST_GAP_KM      = 2.0;       // 2 km
const EARTH_RADIUS_KM  = 6371.0088; // mean Earth radius

// Ensure directories exist & writable
ensureDir(dirname(INPUT_FILE));
ensureDir(dirname(OUTPUT_FILE));
ensureDir(dirname(REJECTS_FILE));

// Load .env (optional)
$env = loadEnv(ENV_FILE_PATH);

// If points.csv is missing, try to auto-download from DRIVE_URL
if (!file_exists(INPUT_FILE)) {
    $driveUrl = $env['DRIVE_URL'] ?? '';
    if ($driveUrl === '') {
        fwrite(STDERR, "ERROR: data/points.csv not found and DRIVE_URL is not set in .env\n");
        exit(1);
    }

    $downloadUrl = convertDriveUrlToDirect($driveUrl);
    fwrite(STDERR, "data/points.csv not found. Downloading from Google Drive...\n");

    $data = @file_get_contents($downloadUrl);
    if ($data === false) {
        fwrite(STDERR, "ERROR: Failed to download from: {$driveUrl}\n");
        exit(1);
    }
    if (file_put_contents(INPUT_FILE, $data) === false) {
        fwrite(STDERR, "ERROR: Could not save downloaded points.csv to: " . INPUT_FILE . "\n");
        exit(1);
    }
    fwrite(STDERR, "Downloaded and saved to " . INPUT_FILE . "\n");
}

// -----------------------------------------------------------------------------
// Open rejects log
$rejectsFH = fopen(REJECTS_FILE, 'w');
if (!$rejectsFH) {
    fwrite(STDERR, "ERROR: Cannot write to " . REJECTS_FILE . "\n");
    exit(1);
}
$rej = function (string $reason, int $lineNo, array $row) use ($rejectsFH): void {
    $raw = implode(',', array_map(static fn($v) => (string)$v, $row));
    fwrite($rejectsFH, "line={$lineNo}\treason={$reason}\trow={$raw}\n");
};

// Open input file
$inStream = fopen(INPUT_FILE, 'r');
if (!$inStream) {
    fwrite(STDERR, "ERROR: Unable to open input file: " . INPUT_FILE . "\n");
    exit(1);
}

// Header
$firstChunk = fgets($inStream);
if ($firstChunk === false) {
    fwrite(STDERR, "ERROR: Empty input file.\n");
    exit(1);
}
$delim  = detectDelimiter($firstChunk);
$header = str_getcsv(trimBom($firstChunk), $delim);
$colMap = mapHeader($header);

$required = ['device_id', 'lat', 'lon', 'timestamp'];
foreach ($required as $req) {
    if (!isset($colMap[$req])) {
        fwrite(STDERR, "ERROR: Missing required column '{$req}' in header.\n");
        exit(1);
    }
}

// Parse rows
$lineNo = 1;
$pointsByDevice = [];

while (($row = fgetcsv($inStream, 0, $delim)) !== false) {
    $lineNo++;

    // Skip empty rows
    if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
        continue;
    }

    $deviceId = trim((string)($row[$colMap['device_id']] ?? ''));
    $latStr   = trim((string)($row[$colMap['lat']] ?? ''));
    $lonStr   = trim((string)($row[$colMap['lon']] ?? ''));
    $tsStr    = trim((string)($row[$colMap['timestamp']] ?? ''));

    if ($deviceId === '') {
        $rej('empty device_id', $lineNo, $row);
        continue;
    }

    $lat = parseFloat($latStr);
    $lon = parseFloat($lonStr);
    if (!isFiniteFloat($lat) || !isFiniteFloat($lon)) {
        $rej('non-numeric lat/lon', $lineNo, $row);
        continue;
    }
    if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
        $rej('coords out of range', $lineNo, $row);
        continue;
    }

    try {
        $ts = new DateTimeImmutable($tsStr);
    } catch (Throwable) {
        $rej('invalid timestamp', $lineNo, $row);
        continue;
    }

    $pointsByDevice[$deviceId][] = [
        'device_id' => $deviceId,
        'lat'       => $lat,
        'lon'       => $lon,
        'ts'        => $ts,
    ];
}

fclose($inStream);
fclose($rejectsFH);

// Sort per device by timestamp
foreach ($pointsByDevice as &$pts) {
    usort($pts, fn($a, $b) => $a['ts'] <=> $b['ts']);
}
unset($pts);

// Split into trips
$trips = [];
foreach (sortedDeviceIds(array_keys($pointsByDevice)) as $dev) {
    $pts = $pointsByDevice[$dev];
    if (count($pts) === 0) {
        continue;
    }

    $current = ['device_id' => $dev, 'points' => [$pts[0]]];

    for ($i = 1; $i < count($pts); $i++) {
        $prev = $pts[$i - 1];
        $curr = $pts[$i];

        $dt   = $curr['ts']->getTimestamp() - $prev['ts']->getTimestamp();
        $dist = haversineKm($prev['lat'], $prev['lon'], $curr['lat'], $curr['lon']);

        $newTrip = ($dt > TIME_GAP_SECONDS) || ($dist > DIST_GAP_KM);

        if ($newTrip) {
            $trips[] = $current;
            $current = ['device_id' => $dev, 'points' => [$curr]];
        } else {
            $current['points'][] = $curr;
        }
    }

    if (!empty($current['points'])) {
        $trips[] = $current;
    }
}

// Build GeoJSON
$features  = [];
$tripCount = count($trips);

for ($idx = 0; $idx < $tripCount; $idx++) {
    $trip   = $trips[$idx];
    $tripId = 'trip_' . ($idx + 1);

    $coords = array_map(
        fn($p) => [round($p['lon'], 6), round($p['lat'], 6)],
        $trip['points']
    );

    $stats = computeTripStats($trip['points']);
    $hex   = hslColorHex(($idx / max(1, $tripCount)) * 360.0, 70.0, 50.0);

    $features[] = [
        'type'       => 'Feature',
        'properties' => [
            'trip_id'           => $tripId,
            'device_id'         => $trip['device_id'],
            'point_count'       => $stats['point_count'],
            'total_distance_km' => round($stats['total_distance_km'], 3),
            'duration_min'      => round($stats['duration_min'], 1),
            'avg_speed_kmh'     => round($stats['avg_speed_kmh'], 2),
            'max_speed_kmh'     => round($stats['max_speed_kmh'], 2),
            'start_time'        => $stats['start_time'],
            'end_time'          => $stats['end_time'],
            'stroke'            => $hex,
            'stroke-width'      => 3,
            'stroke-opacity'    => 1.0,
        ],
        'geometry'   => [
            'type'        => 'LineString',
            'coordinates' => $coords,
        ],
    ];
}

$geojson = [
    'type'     => 'FeatureCollection',
    'features' => $features,
];

file_put_contents(
    OUTPUT_FILE,
    json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

fwrite(STDERR, "OK: wrote " . count($trips) . " trip(s) to " . OUTPUT_FILE . "\n");

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

function ensureDir(string $dir): void {
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
            fwrite(STDERR, "ERROR: Failed to create directory: {$dir}\n");
            exit(1);
        }
    }
    if (!is_writable($dir)) {
        fwrite(STDERR, "ERROR: Directory not writable: {$dir}\n");
        exit(1);
    }
}

/**
 * Load a simple .env file (KEY=VALUE lines, ignores comments and blanks).
 */
function loadEnv(string $path): array {
    $vars = [];
    if (!file_exists($path)) {
        return $vars;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // strip optional surrounding quotes
            $val = trim($val, " \t\n\r\0\x0B\"'");
            $vars[$key] = $val;
        }
    }
    return $vars;
}

/**
 * Convert a Google Drive "view" URL to a direct download link.
 * e.g. https://drive.google.com/file/d/<ID>/view?... -> https://drive.google.com/uc?export=download&id=<ID>
 */
function convertDriveUrlToDirect(string $url): string {
    if (preg_match('#/d/([^/]+)/#', $url, $m)) {
        $id = $m[1];
        return 'https://drive.google.com/uc?export=download&id=' . $id;
    }
    return $url; // fallback
}

/**
 * Detect CSV delimiter from header line using simple heuristics.
 */
function detectDelimiter(string $headerLine): string {
    $candidates = [",", ";", "\t", "|"];
    $bestDelim  = ",";
    $bestCount  = 0;

    foreach ($candidates as $d) {
        $cnt = substr_count($headerLine, $d);
        if ($cnt > $bestCount) {
            $bestCount = $cnt;
            $bestDelim = $d;
        }
    }
    return $bestDelim;
}

/**
 * Trim UTF-8 BOM from a string if present.
 */
function trimBom(string $s): string {
    if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
        return substr($s, 3);
    }
    return $s;
}

/**
 * Map header names to canonical keys: device_id, lat, lon, timestamp.
 */
function mapHeader(array $header): array {
    $map = [];
    foreach ($header as $idx => $nameRaw) {
        $name = strtolower(trim((string)$nameRaw));
        $name = preg_replace('/\s+/', '_', $name);

        if (in_array($name, ['device_id', 'deviceid', 'id'], true)) {
            $map['device_id'] = $idx;
        } elseif (in_array($name, ['lat', 'latitude'], true)) {
            $map['lat'] = $idx;
        } elseif (in_array($name, ['lon', 'lng', 'longitude'], true)) {
            $map['lon'] = $idx;
        } elseif (in_array($name, ['timestamp', 'time', 'datetime', 'ts'], true)) {
            $map['timestamp'] = $idx;
        }
    }
    return $map;
}

/**
 * Parse a float, allowing comma decimals like "14,5995".
 */
function parseFloat(string $s): float|false {
    $s = str_replace(' ', '', $s);

    if (preg_match('/^-?\d+,\d+$/', $s)) {
        $s = str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) {
        return false;
    }

    $f = (float)$s;
    return isFiniteFloat($f) ? $f : false;
}

function isFiniteFloat($v): bool {
    return is_float($v) && !is_nan($v) && !is_infinite($v);
}

/**
 * Great-circle distance (km) using the Haversine formula.
 */
function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = deg2rad($lat2 - $lat1);
    $dlmb = deg2rad($lon2 - $lon1);

    $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlmb / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return EARTH_RADIUS_KM * $c;
}

/**
 * Calculate per-trip stats: total distance, duration, avg and max speeds.
 */
function computeTripStats(array $points): array {
    $n = count($points);

    if ($n === 0) {
        return [
            'point_count'       => 0,
            'total_distance_km' => 0.0,
            'duration_min'      => 0.0,
            'avg_speed_kmh'     => 0.0,
            'max_speed_kmh'     => 0.0,
            'start_time'        => null,
            'end_time'          => null,
        ];
    }

    $totalDist = 0.0;
    $maxSpeed  = 0.0;

    for ($i = 1; $i < $n; $i++) {
        $p0    = $points[$i - 1];
        $p1    = $points[$i];
        $dKm   = haversineKm($p0['lat'], $p0['lon'], $p1['lat'], $p1['lon']);
        $dtSec = max(0, $p1['ts']->getTimestamp() - $p0['ts']->getTimestamp());

        $totalDist += $dKm;

        if ($dtSec > 0) {
            $speed = $dKm / ($dtSec / 3600.0);
            if ($speed > $maxSpeed) {
                $maxSpeed = $speed;
            }
        }
    }

    $startTs = $points[0]['ts'];
    $endTs   = $points[$n - 1]['ts'];
    $durMin  = max(0, ($endTs->getTimestamp() - $startTs->getTimestamp()) / 60.0);
    $avgKmh  = ($durMin > 0) ? ($totalDist / ($durMin / 60.0)) : 0.0;

    return [
        'point_count'       => $n,
        'total_distance_km' => $totalDist,
        'duration_min'      => $durMin,
        'avg_speed_kmh'     => $avgKmh,
        'max_speed_kmh'     => $maxSpeed,
        'start_time'        => $startTs->format(DateTimeInterface::ATOM),
        'end_time'          => $endTs->format(DateTimeInterface::ATOM),
    ];
}

/**
 * Sort device IDs in natural case-insensitive order.
 */
function sortedDeviceIds(array $ids): array {
    natcasesort($ids);
    return array_values($ids);
}

/**
 * Convert HSL to HEX (#RRGGBB) for distinct per-trip colors.
 */
function hslColorHex(float $h, float $s, float $l): string {
    $h = fmod(($h + 360.0), 360.0) / 360.0;
    $s = max(0.0, min(1.0, $s / 100.0));
    $l = max(0.0, min(1.0, $l / 100.0));

    $c  = (1 - abs(2 * $l - 1)) * $s;
    $x  = $c * (1 - abs(fmod($h * 6, 2) - 1));
    $m  = $l - $c / 2;

    $r = 0.0; $g = 0.0; $b = 0.0;
    $h6 = $h * 6;

    if ($h6 < 1) {
        $r = $c; $g = $x; $b = 0;
    } elseif ($h6 < 2) {
        $r = $x; $g = $c; $b = 0;
    } elseif ($h6 < 3) {
        $r = 0;  $g = $c; $b = $x;
    } elseif ($h6 < 4) {
        $r = 0;  $g = $x; $b = $c;
    } elseif ($h6 < 5) {
        $r = $x; $g = 0;  $b = $c;
    } else {
        $r = $c; $g = 0;  $b = $x;
    }

    $ri = (int)round(($r + $m) * 255);
    $gi = (int)round(($g + $m) * 255);
    $bi = (int)round(($b + $m) * 255);

    return sprintf("#%02X%02X%02X", $ri, $gi, $bi);
}
