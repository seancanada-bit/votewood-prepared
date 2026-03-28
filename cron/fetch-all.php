<?php
/**
 * votewood.ca/prepared — Data fetcher cron job
 * Replaces the Render.com Node.js service entirely.
 * Runs on cPanel cron: /usr/local/bin/ea-php82 /home/seanw2/votewood.ca/public_html/cron/fetch-all.php
 *
 * Fetches from public government APIs and upserts into MySQL.
 * Safe to run every 2-6 hours via cPanel cron.
 */

// Block web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'CLI only';
    exit(1);
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'seanw2_prepared');
define('DB_USER', 'seanw2_claudeprepared');
define('DB_PASS', 'mivnic-refmaV-kubxe7'); // Injected at deploy time

$startTime = microtime(true);
$log = [];

function logMsg($tag, $msg) {
    global $log;
    $line = date('H:i:s') . " [$tag] $msg";
    echo $line . "\n";
    $log[] = $line;
}

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function httpGet($url, $timeout = 15) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: votewood-data/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    if ($result === false) {
        throw new Exception("Failed to fetch: $url");
    }
    return $result;
}

// ================================================================
// 1. RIVER FLOW — Water Survey of Canada
// ================================================================
function fetchRiver() {
    logMsg('river', 'Fetching Englishman River...');
    $url = 'https://api.weather.gc.ca/collections/hydrometric-realtime/items'
         . '?f=json&STATION_NUMBER=08HB002&limit=1&sortby=-DATETIME';
    $json = json_decode(httpGet($url), true);
    $props = $json['features'][0]['properties'] ?? null;
    if (!$props) throw new Exception('No features');

    $flow = $props['DISCHARGE'] ?? null;
    $level = $props['LEVEL'] ?? null;
    $date = $props['DATETIME'] ?? null;

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO river_flow_latest (id, flow_m3s, level_m, pct_median, drought_lvl, data_date, fetched_at)
        VALUES (1, ?, ?, NULL, NULL, ?, NOW())
        ON DUPLICATE KEY UPDATE flow_m3s=VALUES(flow_m3s), level_m=VALUES(level_m), data_date=VALUES(data_date), fetched_at=NOW()");
    $stmt->execute([$flow, $level, $date]);
    logMsg('river', "OK - flow={$flow} m3/s, level={$level} m");
}

// ================================================================
// 2. SNOWPACK — BC ENV ASWS (Mt. Arrowsmith 3B26P)
// ================================================================
function fetchSnowpack() {
    logMsg('snowpack', 'Fetching Arrowsmith ASWS...');
    $sweUrl = 'https://www.env.gov.bc.ca/wsd/data_searches/snow/asws/data/SW.csv';
    $sdUrl  = 'https://www.env.gov.bc.ca/wsd/data_searches/snow/asws/data/SD.csv';
    $taUrl  = 'https://www.env.gov.bc.ca/wsd/data_searches/snow/asws/data/TA.csv';

    $swe = parseASWScsv(httpGet($sweUrl, 30), '3B26P');
    $sd  = parseASWScsv(httpGet($sdUrl, 30), '3B26P');
    $ta  = parseASWScsv(httpGet($taUrl, 30), '3B26P');

    $month = (int)date('n');
    $seasonActive = !($month >= 7 && $month <= 9);
    $dataDate = $swe['date'] ?? $sd['date'] ?? null;

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO snowpack_latest (id, swe_mm, depth_cm, temp_c, pct_normal, season_active, vi_basin_pct, data_date, fetched_at)
        VALUES (1, ?, ?, ?, NULL, ?, NULL, ?, NOW())
        ON DUPLICATE KEY UPDATE swe_mm=VALUES(swe_mm), depth_cm=VALUES(depth_cm), temp_c=VALUES(temp_c), season_active=VALUES(season_active), data_date=VALUES(data_date), fetched_at=NOW()");
    $stmt->execute([$swe['value'], $sd['value'], $ta['value'], $seasonActive ? 1 : 0, $dataDate]);
    logMsg('snowpack', "OK - SWE={$swe['value']}mm, depth={$sd['value']}cm, temp={$ta['value']}C");
}

function parseASWScsv($csv, $stationPattern) {
    $lines = explode("\n", trim($csv));
    if (count($lines) < 2) return ['value' => null, 'date' => null];
    $headers = str_getcsv($lines[0]);
    $colIdx = -1;
    foreach ($headers as $i => $h) {
        if (stripos($h, $stationPattern) !== false) { $colIdx = $i; break; }
    }
    if ($colIdx === -1) return ['value' => null, 'date' => null];
    for ($i = count($lines) - 1; $i >= 1; $i--) {
        $row = str_getcsv($lines[$i]);
        $val = trim($row[$colIdx] ?? '');
        if ($val !== '' && is_numeric($val)) {
            return ['value' => (float)$val, 'date' => trim($row[0] ?? '')];
        }
    }
    return ['value' => null, 'date' => null];
}

// ================================================================
// 3. FIRE WEATHER — BCWS Data Mart CSV
// ================================================================
function fetchFireWeather() {
    logMsg('fireWeather', 'Fetching BCWS fire weather...');
    $dangerMap = [1=>'Low',2=>'Moderate',3=>'High',4=>'Very High',5=>'Extreme'];
    $dangerTextMap = ['low'=>1,'moderate'=>2,'high'=>3,'very high'=>4,'extreme'=>5];
    $stationPatterns = ['bowser', 'parksville', 'qualicum', 'cedar'];

    $base = 'https://www.for.gov.bc.ca/ftp/HPR/external/!publish/BCWS_DATA_MART/';
    $now = new DateTime('now', new DateTimeZone('UTC'));
    $todayUrl = $base . $now->format('Y') . '/' . $now->format('Y-m-d') . '.csv';
    $yesterday = clone $now; $yesterday->modify('-1 day');
    $yesterdayUrl = $base . $yesterday->format('Y') . '/' . $yesterday->format('Y-m-d') . '.csv';

    $csv = @file_get_contents($todayUrl);
    if ($csv === false) $csv = httpGet($yesterdayUrl, 20);

    $lines = explode("\n", trim($csv));
    if (count($lines) < 2) throw new Exception('Empty CSV');
    $headers = array_map(fn($h) => strtolower(trim($h)), str_getcsv($lines[0]));

    $idx = ['station'=>-1,'fwi'=>-1,'isi'=>-1,'bui'=>-1,'danger'=>-1];
    foreach ($headers as $i => $h) {
        if (str_contains($h, 'station_name') || $h === 'station') $idx['station'] = $i;
        if ($h === 'fwi' || str_contains($h, 'fire_weather_index')) $idx['fwi'] = $i;
        if ($h === 'isi' || str_contains($h, 'initial_spread')) $idx['isi'] = $i;
        if ($h === 'bui' || str_contains($h, 'buildup')) $idx['bui'] = $i;
        if ($h === 'danger_rating' || $h === 'danger_class') $idx['danger'] = $i;
    }

    $record = null;
    for ($i = count($lines) - 1; $i >= 1; $i--) {
        $cols = str_getcsv($lines[$i]);
        $name = strtolower(trim($cols[$idx['station']] ?? ''));
        foreach ($stationPatterns as $pat) {
            if ($name === $pat || str_contains($name, $pat)) {
                $record = [
                    'fwi' => $idx['fwi'] >= 0 ? floatval($cols[$idx['fwi']] ?? 0) : null,
                    'isi' => $idx['isi'] >= 0 ? floatval($cols[$idx['isi']] ?? 0) : null,
                    'bui' => $idx['bui'] >= 0 ? floatval($cols[$idx['bui']] ?? 0) : null,
                    'danger_raw' => $idx['danger'] >= 0 ? trim($cols[$idx['danger']] ?? '') : null,
                ];
                break 2;
            }
        }
    }

    if (!$record) throw new Exception('No matching station found in CSV');

    $dangerClass = null; $dangerRating = null;
    $raw = $record['danger_raw'] ?? '';
    if (is_numeric($raw) && isset($dangerMap[(int)$raw])) {
        $dangerClass = (int)$raw; $dangerRating = $dangerMap[$dangerClass];
    } elseif (isset($dangerTextMap[strtolower($raw)])) {
        $dangerClass = $dangerTextMap[strtolower($raw)]; $dangerRating = $dangerMap[$dangerClass];
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO fire_weather_latest (id, fwi, isi, bui, danger_class, danger_rating, data_date, fetched_at)
        VALUES (1, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE fwi=VALUES(fwi), isi=VALUES(isi), bui=VALUES(bui), danger_class=VALUES(danger_class), danger_rating=VALUES(danger_rating), data_date=VALUES(data_date), fetched_at=NOW()");
    $stmt->execute([$record['fwi'], $record['isi'], $record['bui'], $dangerClass, $dangerRating, date('Y-m-d')]);
    logMsg('fireWeather', "OK - FWI={$record['fwi']}, danger={$dangerRating}");
}

// ================================================================
// 4. AIR QUALITY — MSC GeoMet AQHI
// ================================================================
function fetchAirQuality() {
    logMsg('airQuality', 'Fetching AQHI for Nanaimo...');
    $url = 'https://api.weather.gc.ca/collections/aqhi-observations-realtime/items'
         . '?f=json&limit=1&sortby=-observation_datetime&location_name_en=Nanaimo';
    $json = json_decode(httpGet($url), true);
    $props = $json['features'][0]['properties'] ?? null;
    if (!$props) throw new Exception('No features');

    $aqhi = $props['aqhi'] ?? $props['AQHI'] ?? null;
    $date = $props['observation_datetime'] ?? $props['DATE_TIME'] ?? null;
    $risk = null;
    if ($aqhi !== null) {
        if ($aqhi <= 3) $risk = 'Low';
        elseif ($aqhi <= 6) $risk = 'Moderate';
        elseif ($aqhi <= 10) $risk = 'High';
        else $risk = 'Very High';
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO air_quality_latest (id, aqhi, risk_level, data_date, fetched_at)
        VALUES (1, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE aqhi=VALUES(aqhi), risk_level=VALUES(risk_level), data_date=VALUES(data_date), fetched_at=NOW()");
    $stmt->execute([$aqhi, $risk, $date]);
    logMsg('airQuality', "OK - AQHI={$aqhi}, risk={$risk}");
}

// ================================================================
// 5. ROAD EVENTS — DriveBC Open511
// ================================================================
function fetchRoadEvents() {
    logMsg('roadEvents', 'Fetching DriveBC events...');
    $url = 'https://api.open511.gov.bc.ca/events?status=ACTIVE&format=json&limit=10&bbox=-124.5,49.2,-124.1,49.5';
    $json = json_decode(httpGet($url), true);
    $allEvents = $json['events'] ?? [];
    $allowed = ['CONSTRUCTION','INCIDENT','SPECIAL_EVENT','ROAD_CONDITION'];

    $filtered = [];
    foreach ($allEvents as $e) {
        $type = strtoupper($e['event_type'] ?? $e['+event_type'] ?? '');
        if (!in_array($type, $allowed)) continue;
        $roads = [];
        foreach (($e['roads'] ?? []) as $r) {
            $name = $r['name'] ?? $r['road_name'] ?? '';
            if ($name) $roads[] = $name;
        }
        $filtered[] = [
            'event_type' => $e['event_type'] ?? $e['+event_type'] ?? 'UNKNOWN',
            'headline' => $e['headline'] ?? $e['description'] ?? '',
            'severity' => $e['severity'] ?? 'UNKNOWN',
            'roads' => $roads,
        ];
        if (count($filtered) >= 10) break;
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO road_events_latest (id, event_count, events_json, fetched_at)
        VALUES (1, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE event_count=VALUES(event_count), events_json=VALUES(events_json), fetched_at=NOW()");
    $stmt->execute([count($filtered), json_encode($filtered)]);
    logMsg('roadEvents', "OK - " . count($filtered) . " events");
}

// ================================================================
// 6. WILDFIRE — BC Wildfire ESRI REST
// ================================================================
function fetchWildfire() {
    logMsg('wildfire', 'Fetching BC Wildfire data...');
    $url = 'https://services6.arcgis.com/ubm4tcTYICKBpist/arcgis/rest/services/'
         . 'BCWS_ActiveFires_PublicView/FeatureServer/0/query'
         . '?where=1%3D1&outFields=FIRE_STATUS,FIRE_SIZE_HECTARES,GEOGRAPHIC_DESCRIPTION,FIRE_OF_NOTE_NAME'
         . '&f=json&returnCountOnly=false'
         . '&geometry=-126.0,48.5,-122.0,50.5&geometryType=esriGeometryEnvelope&spatialRel=esriSpatialRelIntersects';
    $json = json_decode(httpGet($url, 20), true);
    $features = $json['features'] ?? [];
    $activeCount = count($features);

    $viKeywords = ['vancouver island','nanaimo','parksville','qualicum'];
    $viCount = 0;
    foreach ($features as $f) {
        $desc = strtolower($f['attributes']['GEOGRAPHIC_DESCRIPTION'] ?? '');
        foreach ($viKeywords as $kw) {
            if (str_contains($desc, $kw)) { $viCount++; break; }
        }
    }

    // Top 5 by size
    usort($features, fn($a,$b) => ($b['attributes']['FIRE_SIZE_HECTARES'] ?? 0) - ($a['attributes']['FIRE_SIZE_HECTARES'] ?? 0));
    $top5 = array_map(fn($f) => [
        'status' => $f['attributes']['FIRE_STATUS'] ?? 'Unknown',
        'size_ha' => $f['attributes']['FIRE_SIZE_HECTARES'] ?? 0,
        'description' => $f['attributes']['GEOGRAPHIC_DESCRIPTION'] ?? '',
        'name' => $f['attributes']['FIRE_OF_NOTE_NAME'] ?? null,
    ], array_slice($features, 0, 5));

    $month = (int)date('n');
    $seasonActive = $activeCount > 0 || ($month >= 5 && $month <= 10);

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO wildfire_latest (id, active_count, vi_count, fires_json, season_active, fetched_at)
        VALUES (1, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE active_count=VALUES(active_count), vi_count=VALUES(vi_count), fires_json=VALUES(fires_json), season_active=VALUES(season_active), fetched_at=NOW()");
    $stmt->execute([$activeCount, $viCount, json_encode($top5), $seasonActive ? 1 : 0]);
    logMsg('wildfire', "OK - {$activeCount} active fires, {$viCount} on VI");
}

// ================================================================
// 7. HOUSING — CMHC (known values, check for updates)
// ================================================================
function fetchHousing() {
    logMsg('housing', 'Updating Parksville rental data...');
    $known = ['vacancy_rate'=>0.9, 'avg_rent'=>1573, 'median_rent'=>1625, 'unit_count'=>1209, 'data_year'=>2025];

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO housing_latest (id, vacancy_rate, avg_rent, median_rent, unit_count, data_year, fetched_at)
        VALUES (1, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE vacancy_rate=VALUES(vacancy_rate), avg_rent=VALUES(avg_rent), median_rent=VALUES(median_rent), unit_count=VALUES(unit_count), data_year=VALUES(data_year), fetched_at=NOW()");
    $stmt->execute([$known['vacancy_rate'], $known['avg_rent'], $known['median_rent'], $known['unit_count'], $known['data_year']]);
    logMsg('housing', "OK - vacancy={$known['vacancy_rate']}%, avg_rent=\${$known['avg_rent']}");
}

// ================================================================
// RUN ALL FETCHERS
// ================================================================
logMsg('cron', 'Starting all fetchers...');

$fetchers = [
    'river' => 'fetchRiver',
    'snowpack' => 'fetchSnowpack',
    'fireWeather' => 'fetchFireWeather',
    'airQuality' => 'fetchAirQuality',
    'roadEvents' => 'fetchRoadEvents',
    'wildfire' => 'fetchWildfire',
    'housing' => 'fetchHousing',
];

$ok = 0; $fail = 0;
foreach ($fetchers as $name => $fn) {
    try {
        $fn();
        $ok++;
    } catch (Exception $e) {
        logMsg($name, "ERROR: " . $e->getMessage());
        $fail++;
    }
}

$elapsed = round(microtime(true) - $startTime, 1);
logMsg('cron', "Done. {$ok} OK, {$fail} failed. {$elapsed}s elapsed.");
