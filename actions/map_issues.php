<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen', 'worker', 'authority', 'local authority', 'admin']);

if (session_status() === PHP_SESSION_NONE) session_start();

header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'Not logged in']);
  exit;
}

try {
  // Get my role + area
  $st = $pdo->prepare("SELECT role, area_id FROM users WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $role = strtolower((string)($me['role'] ?? ''));
  $myAreaId = (int)($me['area_id'] ?? 0);

  if ($role !== 'admin' && $myAreaId <= 0) {
    // For non-admin, area must exist to show branch map
    echo json_encode(['ok' => true, 'markers' => [], 'meta' => ['reason' => 'no_area']]);
    exit;
  }

  // Optional filter
  $only = strtolower(trim((string)($_GET['only'] ?? ''))); 

  $rows = [];

  if ($role === 'citizen') {
    // Citizen: show ALL common issues in my branch + ONLY my personal issues
    $st = $pdo->prepare("
      SELECT
        i.issue_id,
        i.title,
        i.status,
        i.is_common,
        i.common_area_id,
        ca.area_name AS common_area_name,
        i.lat, i.lng,
        i.created_at
      FROM issues i
      LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
      WHERE i.area_id = ?
        AND (
          i.is_common = 1
          OR (i.is_common = 0 AND i.reporter_user_id = ?)
        )
      ORDER BY i.created_at DESC, i.issue_id DESC
      LIMIT 400
    ");
    $st->execute([$myAreaId, $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  } elseif ($role === 'worker') {
    // Worker: show only issues assigned to this worker 
  
    $st = $pdo->prepare("
      SELECT
        i.issue_id,
        i.title,
        i.status,
        i.is_common,
        i.common_area_id,
        ca.area_name AS common_area_name,
        i.lat, i.lng,
        i.created_at
      FROM assignments a
      JOIN issues i ON i.issue_id = a.issue_id
      LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
      WHERE a.field_worker_id = ?
        AND a.assignment_status IN ('ASSIGNED','ACCEPTED','COMPLETED')
      ORDER BY i.created_at DESC, i.issue_id DESC
      LIMIT 400
    ");
    $st->execute([$userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  } elseif ($role === 'authority' || $role === 'local authority') {
    // Authority: all issues in their branch
    $st = $pdo->prepare("
      SELECT
        i.issue_id,
        i.title,
        i.status,
        i.is_common,
        i.common_area_id,
        ca.area_name AS common_area_name,
        i.lat, i.lng,
        i.created_at
      FROM issues i
      LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
      WHERE i.area_id = ?
      ORDER BY i.created_at DESC, i.issue_id DESC
      LIMIT 500
    ");
    $st->execute([$myAreaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  } else {
    // Admin: show latest across all branches (optional)
    $st = $pdo->query("
      SELECT
        i.issue_id,
        i.title,
        i.status,
        i.is_common,
        i.common_area_id,
        ca.area_name AS common_area_name,
        i.lat, i.lng,
        i.created_at
      FROM issues i
      LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
      ORDER BY i.created_at DESC, i.issue_id DESC
      LIMIT 500
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  // Sanitize + validate coords
  $markers = [];
  foreach ($rows as $r) {
    $lat = (float)($r['lat'] ?? 0);
    $lng = (float)($r['lng'] ?? 0);

    // Skip invalid coordinates (prevents map breaking)
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
    if ($lat == 0.0 && $lng == 0.0) continue;

    $markers[] = [
      'issue_id' => (int)$r['issue_id'],
      'title' => (string)$r['title'],
      'status' => (string)$r['status'],
      'is_common' => (int)$r['is_common'],
      'common_area_name' => (string)($r['common_area_name'] ?? ''),
      'lat' => $lat,
      'lng' => $lng,
      'created_at' => (string)($r['created_at'] ?? ''),
    ];
  }

  echo json_encode([
    'ok' => true,
    'markers' => $markers,
    'meta' => [
      'role' => $role,
      'area_id' => $myAreaId,
      'count' => count($markers),
    ],
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error']);
}