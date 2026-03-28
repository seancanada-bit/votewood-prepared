<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://votewood.ca');
header('Cache-Control: public, max-age=300');

require_once('/home/seanw2/db-config.php');

$allowed = [
  'river_flow_latest', 'snowpack_latest', 'fire_weather_latest',
  'air_quality_latest', 'road_events_latest', 'city_scores_latest',
  'demographics_latest', 'wildfire_latest', 'local_events_latest',
  'housing_latest', 'tax_rates_latest', 'fiscal_health_latest',
  'parksville_history',
];

$table = $_GET['table'] ?? '';

if ($table === 'all') {
  try {
    $pdo = new PDO(
      'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
      DB_USER, DB_PASS,
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $result = [];
    foreach ($allowed as $t) {
      try {
        $stmt = $pdo->query("SELECT * FROM `{$t}` WHERE id = 1 LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
          foreach (['events_json','fires_json','data_json'] as $col) {
            if (isset($row[$col])) $row[$col] = json_decode($row[$col], true) ?? [];
          }
        }
        $result[$t] = $row ?: null;
      } catch (PDOException $e) {
        $result[$t] = null;
      }
    }
    echo json_encode(['data' => $result, 'ok' => true]);
  } catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable', 'ok' => false]);
  }
  exit;
}

if (!in_array($table, $allowed, true)) {
  http_response_code(400);
  echo json_encode(['error' => 'Invalid table']);
  exit;
}

try {
  $pdo = new PDO(
    'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
  $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = 1 LIMIT 1");
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  foreach (['events_json','fires_json','data_json'] as $col) {
    if (isset($row[$col])) $row[$col] = json_decode($row[$col], true) ?? [];
  }
  echo json_encode(['data' => $row, 'ok' => true]);
} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode(['error' => 'Database unavailable', 'ok' => false]);
}
