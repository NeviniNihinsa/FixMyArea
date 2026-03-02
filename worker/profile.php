<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Leaderboard - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

/* Get worker area */
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$areaId   = (int)($me['area_id'] ?? 0);
$areaName = (string)($me['area_name'] ?? 'Designated Area');

if ($areaId <= 0) {
  http_response_code(403);
  echo "<div class='container py-4 app-container'>
          <div class='alert alert-warning'>
            Your account is not assigned to an area yet.
          </div>
        </div>";
  exit;
}

/* 1) Top Field Worker (by completed issues in this area) */
$topWorker = null;
$sqlWorker = "
SELECT
  u.user_id, u.name,
  COUNT(*) AS completed_jobs
FROM assignments a
JOIN issues i ON i.issue_id = a.issue_id
JOIN users u ON u.user_id = a.field_worker_id
WHERE i.area_id = ?
  AND UPPER(TRIM(i.status)) = 'COMPLETED'
GROUP BY u.user_id, u.name
ORDER BY completed_jobs DESC
LIMIT 1
";
$st = $pdo->prepare($sqlWorker);
$st->execute([$areaId]);
$topWorker = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/* 2) Top Local Authority (most status updates in this area) */
$topAuthority = null;
$sqlAuth = "
SELECT
  u.user_id, u.name,
  COUNT(*) AS actions_count
FROM issue_status_history h
JOIN issues i ON i.issue_id = h.issue_id
JOIN users u ON u.user_id = h.changed_by_user_id
WHERE i.area_id = ?
  AND TRIM(LOWER(u.role)) IN ('authority','local authority')
GROUP BY u.user_id, u.name
ORDER BY actions_count DESC
LIMIT 1
";
$st = $pdo->prepare($sqlAuth);
$st->execute([$areaId]);
$topAuthority = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/* 3) Most Responsible Citizen (most reports in this area) */
$topCitizen = null;
$sqlCitizen = "
SELECT
  u.user_id, u.name,
  COUNT(*) AS reports_count
FROM issues i
JOIN users u ON u.user_id = i.reporter_user_id
WHERE i.area_id = ?
  AND TRIM(LOWER(u.role)) = 'citizen'
GROUP BY u.user_id, u.name
ORDER BY reports_count DESC
LIMIT 1
";
$st = $pdo->prepare($sqlCitizen);
$st->execute([$areaId]);
$topCitizen = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/* Helpers */
function safeName(?array $row): string {
  return (!empty($row['name'])) ? (string)$row['name'] : '—';
}
function safeMetric(?array $row, string $key): string {
  return (isset($row[$key])) ? (string)$row[$key] : '0';
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
.lb-card{
  border-radius: 22px;
  border: 1px solid rgba(241,246,246,0.18);
  padding: 28px 18px;
  text-align:center;
  min-height: 260px;
  display:flex;
  flex-direction:column;
  justify-content:center;
  gap: 10px;
}
.lb-avatar{
  width: 92px;
  height: 92px;
  border-radius: 50%;
  border: 3px solid rgba(241,246,246,0.75);
  margin: 0 auto 6px auto;
  display:flex;
  align-items:center;
  justify-content:center;
  background: rgba(255,255,255,0.03);
}
.lb-avatar i{
  font-size: 48px;
  color: rgba(241,246,246,0.75);
}
.lb-title{
  font-weight: 600;
  font-size: 0.95rem;
}
.lb-name{
  font-weight: 700;
  font-size: 1.08rem;
}
.lb-metric{
  color: var(--text-300);
  font-size: 0.9rem;
}
</style>

<div class="container py-4">

  <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
    <h2 class="fw-bold mb-0">Leaderboard</h2>

    <div class="d-flex align-items-center gap-2">
      <label class="text-muted mb-0">Area:</label>
      <input class="form-control" value="<?= htmlspecialchars($areaName) ?>" readonly style="min-width:240px;">
    </div>
  </div>

  <div class="text-muted mb-4">
    Showing results for: <span class="fw-semibold"><?= htmlspecialchars($areaName) ?></span>
  </div>

  <div class="row g-4 justify-content-center">

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Top performing Field Worker of the Month</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topWorker)) ?></div>
        <div class="lb-metric">
          Completed jobs: <?= htmlspecialchars(safeMetric($topWorker, 'completed_jobs')) ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Top performing local authority of the Month</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topAuthority)) ?></div>
        <div class="lb-metric">
          Actions: <?= htmlspecialchars(safeMetric($topAuthority, 'actions_count')) ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Most Responsible citizen</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topCitizen)) ?></div>
        <div class="lb-metric">
          Reports: <?= htmlspecialchars(safeMetric($topCitizen, 'reports_count')) ?>
        </div>
      </div>
    </div>

  </div>

  <div class="mt-4">
    <a class="btn btn-outline-light" href="<?= BASE_URL ?>/worker/community.php">← Back to Community</a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>
