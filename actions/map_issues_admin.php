<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

header('Content-Type: application/json; charset=utf-8');

$areaId   = (int)($_GET['area_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));
$locType  = trim((string)($_GET['loc_type'] ?? '')); // '' | 'common' | 'unit'

$validDate = function(string $d): bool {
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};

if (!$validDate($fromDate) || !$validDate($toDate) || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid date filters']);
  exit;
}
if ($locType !== '' && !in_array($locType, ['common', 'unit'], true)) {
  echo json_encode(['ok' => false, 'error' => 'Invalid location type']);
  exit;
}

$where = [];
$params = [];

if ($areaId > 0)       { $where[] = "i.area_id = ?";     $params[] = $areaId; }
if ($fromDate !== '')  { $where[] = "i.created_at >= ?"; $params[] = $fromDate . " 00:00:00"; }
if ($toDate !== '')    { $where[] = "i.created_at <= ?"; $params[] = $toDate . " 23:59:59"; }
if ($locType === 'common') { $where[] = "i.is_common = 1"; }
if ($locType === 'unit')   { $where[] = "i.is_common = 0"; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/**
 * ✅ CHANGE THESE TWO if your column names differ:
 * - i.lat  -> i.latitude / i.gps_lat ...
 * - i.lng  -> i.longitude / i.gps_lng ...
 */
$sql = "
  SELECT
    i.issue_id,
    i.title,
    i.status,
    i.is_common,
    i.common_area_id,
    ca.area_name AS common_area_name,
    i.lat AS lat,    -- TODO: change column if needed
    i.lng AS lng     -- TODO: change column if needed
  FROM issues i
  LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
  $whereSql
  ORDER BY i.created_at DESC, i.issue_id DESC
  LIMIT 1500
";

try {
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $markers = [];
  foreach ($rows as $r) {
    $lat = isset($r['lat']) ? (float)$r['lat'] : 0.0;
    $lng = isset($r['lng']) ? (float)$r['lng'] : 0.0;
    if (!$lat || !$lng) continue; // skip missing coords

    $markers[] = [
      'issue_id' => (int)$r['issue_id'],
      'title' => (string)$r['title'],
      'status' => (string)$r['status'],
      'is_common' => (int)($r['is_common'] ?? 0),
      'common_area_name' => $r['common_area_name'] ?? null,
      'lat' => $lat,
      'lng' => $lng,
    ];
  }

  echo json_encode(['ok' => true, 'markers' => $markers]);
} catch (Throwable $e) {
  echo json_encode(['ok' => false, 'error' => 'DB error']);
}