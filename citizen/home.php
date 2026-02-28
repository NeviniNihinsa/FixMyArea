<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* -----------------------------
   1) Get citizen info + branch
------------------------------ */
$st = $pdo->prepare("
  SELECT u.area_id, u.name, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? 'Not set');
$citizenName = (string)($me['name'] ?? ($_SESSION['name'] ?? 'Citizen'));

/* -----------------------------
   2) Stats (branch totals + my totals)
------------------------------ */
$building = ['total_reported' => 0, 'total_fixed' => 0];
$mine     = ['my_reported' => 0, 'my_fixed' => 0];

if ($myAreaId > 0) {
    // Building totals (issues in my branch)
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total_reported,
        SUM(CASE WHEN status IN ('COMPLETED','CLOSED') THEN 1 ELSE 0 END) AS total_fixed
      FROM issues
      WHERE area_id = ?
    ");
    $st->execute([$myAreaId]);
    $building = $st->fetch(PDO::FETCH_ASSOC) ?: $building;
}

// My totals (issues reported by me)
$st = $pdo->prepare("
  SELECT
    COUNT(*) AS my_reported,
    SUM(CASE WHEN status IN ('COMPLETED','CLOSED') THEN 1 ELSE 0 END) AS my_fixed
  FROM issues
  WHERE reporter_user_id = ?
");
$st->execute([$userId]);
$mine = $st->fetch(PDO::FETCH_ASSOC) ?: $mine;

/* -----------------------------
   3) Recent issues (my branch)
   (Removed word 'Local' in UI)
------------------------------ */
$recent = [];
if ($myAreaId > 0) {
    $st = $pdo->prepare("
        SELECT issue_id, title, status, created_at
        FROM issues
        WHERE area_id = ?
        ORDER BY created_at DESC, issue_id DESC
        LIMIT 3
    ");
    $st->execute([$myAreaId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Welcome <?= h($citizenName) ?></h2>

  <div class="row g-4">
    <!-- LEFT: Map placeholder -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-3">
        <div class="ratio ratio-4x3" style="border-radius: 12px; overflow:hidden;">
          <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
            <div class="text-center">
              <div class="text-muted">Map Placeholder</div>
              <div class="small text-muted">OpenStreetMap will be added soon</div>
            </div>
          </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <a class="btn btn-brand" href="<?= BASE_URL ?>/citizen/report_issue.php">Report an Issue</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/track_issue.php">Track Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/community.php">Community</a>
        </div>
      </div>
    </div>

    <!-- RIGHT: Stats -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">

        <!-- Building totals (branch totals) -->
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Issues</div>
              <div class="fs-3 fw-bold"><?= (int)$building['total_reported'] ?></div>
              <div class="small text-muted">In your branch</div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Fixed</div>
              <div class="fs-3 fw-bold"><?= (int)$building['total_fixed'] ?></div>
              <div class="small text-muted">In your branch</div>
            </div>
          </div>
        </div>

        <hr style="border-color: rgba(241,246,246,0.10);" class="my-4">

        <!-- Removed area dropdown: show readonly branch -->
        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
          <div class="text-muted">Issues reported in</div>
          <input
            type="text"
            class="form-control"
            style="max-width: 260px; opacity: 0.9;"
            value="<?= h($myAreaName) ?>"
            readonly
          >
        </div>

        <!-- Tenant totals (my issues) -->
        <div class="row g-3 mt-1">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Reported</div>
              <div class="fs-3 fw-bold"><?= (int)$mine['my_reported'] ?></div>
              <div class="small text-muted">By you</div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Fixed</div>
              <div class="fs-3 fw-bold"><?= (int)$mine['my_fixed'] ?></div>
              <div class="small text-muted">By you</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Recent Issues (removed 'Local') -->
  <div class="mt-4 card-dark p-4">
    <h4 class="fw-semibold mb-3">Recent Issues</h4>

    <?php if ($myAreaId <= 0): ?>
      <div class="text-muted">Your branch is not set. Please update your profile and select a branch.</div>
    <?php elseif (empty($recent)): ?>
      <div class="text-muted">No issues found for your branch yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:110px;">Track ID</th>
              <th>Title</th>
              <th style="width:140px;">Status</th>
              <th style="width:180px;">Created</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><span class="badge bg-secondary"><?= h($r['status']) ?></span></td>
                <td class="text-muted"><?= h($r['created_at']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>