<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Support both role names (some DBs use "local authority", some use "authority")
 */
require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Authority Dashboard - FixMyArea';

/**
 * ✅ Use the LOGGED-IN navbar (NOT navbar_auth.php)
 * navbar_auth.php is for Login/Register header.
 */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* -----------------------------
   1) Load authority + area
------------------------------ */
$st = $pdo->prepare("
  SELECT u.name, u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$userName = (string)($me['name'] ?? 'Local Authority');
$areaId   = (int)($me['area_id'] ?? 0);
$areaName = (string)($me['area_name'] ?? '—');

/* -----------------------------
   2) Stats for this area
------------------------------ */
$stats = [
  'total'       => 0,
  'completed'   => 0,
  'in_progress' => 0,
  'pending'     => 0,
];

if ($areaId > 0) {
  $st = $pdo->prepare("
    SELECT
      COUNT(*) AS total,
      SUM(status IN ('COMPLETED','CLOSED')) AS completed,
      SUM(status IN ('IN_PROGRESS','ASSIGNED')) AS in_progress,
      SUM(status = 'PENDING') AS pending
    FROM issues
    WHERE area_id = ?
  ");
  $st->execute([$areaId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $stats['total']       = (int)($row['total'] ?? 0);
  $stats['completed']   = (int)($row['completed'] ?? 0);
  $stats['in_progress'] = (int)($row['in_progress'] ?? 0);
  $stats['pending']     = (int)($row['pending'] ?? 0);
}

/* -----------------------------
   3) Recently updates (latest 8)
------------------------------ */
$recent = [];
if ($areaId > 0) {
  $st = $pdo->prepare("
    SELECT
      i.issue_id,
      i.title,
      i.status,
      i.created_at,
      a.area_name,
      c.category_name,
      urep.email AS reporter_email,
      uw.name AS worker_name,
      uw.email AS worker_email
    FROM issues i
    JOIN areas a ON a.area_id = i.area_id
    LEFT JOIN issue_categories c ON c.category_id = i.category_id
    JOIN users urep ON urep.user_id = i.reporter_user_id

    /* latest assignment (if exists) */
    LEFT JOIN assignments asg ON asg.assignment_id = (
      SELECT a2.assignment_id
      FROM assignments a2
      WHERE a2.issue_id = i.issue_id
      ORDER BY a2.assigned_at DESC
      LIMIT 1
    )
    LEFT JOIN users uw ON uw.user_id = asg.field_worker_id

    WHERE i.area_id = ?
    ORDER BY i.created_at DESC, i.issue_id DESC
    LIMIT 8
  ");
  $st->execute([$areaId]);
  $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container py-4 app-container">

  <div class="mb-4">
    <h2 class="fw-bold mb-1">Welcome <?= h($userName) ?></h2>
    <div class="text-muted">
      Reported issues in area: <span class="fw-semibold"><?= h($areaName) ?></span>
    </div>
  </div>

  <div class="row g-4">
    <!-- Map placeholder -->
    <div class="col-12 col-lg-7">
      <div class="card-dark p-4" style="min-height:320px; display:flex; align-items:center; justify-content:center;">
        <div class="text-center text-muted">
          <div class="fw-semibold mb-1">Map Placeholder</div>
          <div class="small">OpenStreetMap will be added soon</div>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="col-12 col-lg-5">
      <div class="card-dark p-4 h-100">
        <div class="fw-semibold mb-3">Reported Issues in area</div>

        <div class="row g-3">
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total number of issues</div>
              <div class="display-6 fw-bold"><?= (int)$stats['total'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Completed</div>
              <div class="display-6 fw-bold"><?= (int)$stats['completed'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">In Progress</div>
              <div class="display-6 fw-bold"><?= (int)$stats['in_progress'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Pending</div>
              <div class="display-6 fw-bold"><?= (int)$stats['pending'] ?></div>
            </div>
          </div>
        </div>

        <?php if ($areaId === 0): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Your account has no area assigned. Please set <code>users.area_id</code> for this authority.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recently Updates -->
  <div class="mt-4">
    <h5 class="fw-semibold mb-3">Recently Updates</h5>

    <div class="card-dark p-3 p-md-4">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:90px;">Issue ID</th>
              <th>Title</th>
              <th style="width:140px;">Category</th>
              <th style="width:140px;">Area branch</th>
              <th style="width:180px;">Reported By</th>
              <th style="width:200px;">Assigned Field Worker</th>
              <th style="width:120px;">Status</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($recent)): ?>
            <tr>
              <td colspan="8" class="text-muted">No issues found for this area.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title'] ?? '') ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['area_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td>
                  <?php if (!empty($r['worker_email'])): ?>
                    <?= h($r['worker_email']) ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-secondary"><?= h($r['status'] ?? '') ?></span></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>