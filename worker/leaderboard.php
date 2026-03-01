<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Leaderboard - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

/** Worker's branch (default filter) */
$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$myAreaId = (int)($me['area_id'] ?? 0);

/** Area filter */
$areaId = (int)($_GET['area_id'] ?? 0);
if ($areaId === 0 && $myAreaId > 0) $areaId = $myAreaId;

/** Areas dropdown */
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

$params = [];
$areaWhereIssues = "";
$areaWhereAssignments = "";
$areaWhereHistory = "";

if ($areaId > 0) {
  $areaWhereIssues = "WHERE i.area_id = ?";
  $areaWhereAssignments = "WHERE i.area_id = ?";
  $areaWhereHistory = "WHERE i.area_id = ?";
  $params = [$areaId];
}

/** 1) Top Field Worker */
$topWorker = null;
$sqlWorker = "
SELECT
  u.user_id, u.name, u.email,
  COUNT(*) AS completed_jobs
FROM assignments a
JOIN issues i ON i.issue_id = a.issue_id
JOIN users u ON u.user_id = a.field_worker_id
{$areaWhereAssignments}
  AND a.assignment_status IN ('COMPLETED','CLOSED','DONE')
GROUP BY u.user_id, u.name, u.email
ORDER BY completed_jobs DESC
LIMIT 1
";
$st = $pdo->prepare($sqlWorker);
$st->execute($params);
$topWorker = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/** 2) Top Local Authority */
$topAuthority = null;
$sqlAuth = "
SELECT
  u.user_id, u.name, u.email,
  COUNT(*) AS actions_count
FROM issue_status_history h
JOIN issues i ON i.issue_id = h.issue_id
JOIN users u ON u.user_id = h.changed_by_user_id
{$areaWhereHistory}
  AND u.role = 'authority'
GROUP BY u.user_id, u.name, u.email
ORDER BY actions_count DESC
LIMIT 1
";
$st = $pdo->prepare($sqlAuth);
$st->execute($params);
$topAuthority = $st->fetch(PDO::FETCH_ASSOC) ?: null;

/** 3) Most Responsible Citizen */
$topCitizen = null;
$sqlCitizen = "
SELECT
  u.user_id, u.name, u.email,
  COUNT(*) AS reports_count
FROM issues i
JOIN users u ON u.user_id = i.reporter_user_id
{$areaWhereIssues}
  AND u.role = 'citizen'
GROUP BY u.user_id, u.name, u.email
ORDER BY reports_count DESC
LIMIT 1
";
$st = $pdo->prepare($sqlCitizen);
$st->execute($params);
$topCitizen = $st->fetch(PDO::FETCH_ASSOC) ?: null;

$areaName = 'All areas';
if ($areaId > 0) {
  foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = (string)$a['area_name']; break; }
  }
}

function safeName(?array $row): string {
  return (!empty($row['name'])) ? (string)$row['name'] : '—';
}
function safeMetric(?array $row, string $key): string {
  return (isset($row[$key])) ? (string)$row[$key] : '0';
}
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
    line-height: 1;
  }
  .lb-title{ font-weight: 600; font-size: 0.95rem; line-height: 1.2; }
  .lb-name{ font-weight: 700; font-size: 1.08rem; }
  .lb-metric{ color: var(--text-300); font-size: 0.9rem; }
</style>

<div class="container py-4">

  <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
    <h2 class="fw-bold mb-0">Leaderboard</h2>

    <form method="GET" class="d-flex align-items-center gap-2">
      <label class="text-muted mb-0">Area:</label>
      <select name="area_id" class="form-select" style="min-width:240px;" onchange="this.form.submit()">
        <option value="0">All areas</option>
        <?php foreach ($areas as $a): ?>
          <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
            <?= htmlspecialchars($a['area_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <noscript><button class="btn btn-brand">Apply</button></noscript>
    </form>
  </div>

  <div class="text-muted mb-4">
    Showing results for: <span class="fw-semibold"><?= htmlspecialchars($areaName) ?></span>
  </div>

  <div class="row g-4 justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Top Performing Technician of the Month</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topWorker)) ?></div>
        <div class="lb-metric">Completed jobs: <?= htmlspecialchars(safeMetric($topWorker, 'completed_jobs')) ?></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Top performing authority of the Month</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topAuthority)) ?></div>
        <div class="lb-metric">Actions: <?= htmlspecialchars(safeMetric($topAuthority, 'actions_count')) ?></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-4">
      <div class="card-dark lb-card">
        <div class="lb-avatar"><i class="bi bi-person-circle"></i></div>
        <div class="lb-title">Most Responsible citizen</div>
        <div class="lb-name"><?= htmlspecialchars(safeName($topCitizen)) ?></div>
        <div class="lb-metric">Reports: <?= htmlspecialchars(safeMetric($topCitizen, 'reports_count')) ?></div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>