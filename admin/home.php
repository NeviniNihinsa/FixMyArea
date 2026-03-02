<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Admin Home - Fixly';
require_once __DIR__ . '/../includes/header.php';

$navApp = __DIR__ . '/../includes/navbar_app.php';
if (file_exists($navApp)) {
    require_once $navApp;
} else {
    require_once __DIR__ . '/../includes/navbar.php';
}

// ── Area filter ──
$areaId = (int)($_GET['area_id'] ?? 0);
$areas  = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

$areaName = 'All Branches';
foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = $a['area_name']; break; }
}

// ── Helpers ──
function countIssuesByStatus(PDO $pdo, array $statuses, int $areaId = 0): int {
    if (empty($statuses)) return 0;
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $params = $statuses;
    $areaSql = '';
    if ($areaId > 0) { $areaSql = " AND area_id = ?"; $params[] = $areaId; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM issues WHERE status IN ($in)$areaSql");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── KPI Stats (area-filtered) ──
$totalUsersSql = $areaId > 0
    ? "SELECT COUNT(*) FROM users WHERE area_id = ?"
    : "SELECT COUNT(*) FROM users";
$st = $pdo->prepare($totalUsersSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$totalUsers = (int)$st->fetchColumn();

$totalIssuesSql = $areaId > 0
    ? "SELECT COUNT(*) FROM issues WHERE area_id = ?"
    : "SELECT COUNT(*) FROM issues";
$st = $pdo->prepare($totalIssuesSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$totalIssues = (int)$st->fetchColumn();

$pending   = countIssuesByStatus($pdo, ['PENDING'],               $areaId);
$inProg    = countIssuesByStatus($pdo, ['IN_PROGRESS'],            $areaId);
$completed = countIssuesByStatus($pdo, ['COMPLETED', 'CLOSED'],   $areaId);

// ── Latest issues (area-filtered) ──
$latestSql = "
    SELECT i.issue_id, i.title, i.status, i.created_at,
           a.area_name, c.category_name
    FROM issues i
    LEFT JOIN areas a ON a.area_id = i.area_id
    LEFT JOIN issue_categories c ON c.category_id = i.category_id
    " . ($areaId > 0 ? "WHERE i.area_id = ?" : "") . "
    ORDER BY i.created_at DESC
    LIMIT 5
";
$st = $pdo->prepare($latestSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$latest = $st->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4 app-container">

  <!-- Header -->
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
    <div>
      <h2 class="fw-bold mb-1">Admin Dashboard</h2>
      <div class="text-muted small">System overview — <span class="fw-semibold"><?= h($areaName) ?></span></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">Manage Users</a>
      <a class="btn btn-brand"         href="<?= BASE_URL ?>/admin/manage_issues.php">Manage Issues</a>
    </div>
  </div>

  <!-- Branch Filter -->
  <div class="card-dark p-3 mb-4">
    <form method="GET" class="d-flex flex-wrap gap-3 align-items-end">
      <div>
        <label class="form-label mb-1 small">Filter by Branch</label>
        <select name="area_id" class="form-select form-select-sm" style="min-width:200px;">
          <option value="0">All Branches</option>
          <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
              <?= h($a['area_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-brand btn-sm" type="submit">Apply</button>
      <?php if ($areaId > 0): ?>
        <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/admin/home.php">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- KPI Cards — now 5 cards including In Progress -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Users</div>
        <div class="fs-3 fw-bold"><?= $totalUsers ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Issues</div>
        <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Pending</div>
        <div class="fs-3 fw-bold" style="color:var(--accent-600);"><?= $pending ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">In Progress</div>
        <div class="fs-3 fw-bold text-primary"><?= $inProg ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Completed</div>
        <div class="fs-3 fw-bold text-success"><?= $completed ?></div>
      </div>
    </div>
  </div>

  <!-- Bottom row: Quick Actions + Latest Issues -->
  <div class="row g-3">
    <div class="col-12 col-lg-4">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Quick Actions</h5>
        <div class="d-grid gap-2">
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">View Users</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/add_user.php">Add New User</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_issues.php">View Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/analytics.php">Analytics</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/profile.php">My Profile</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Latest Issues
          <?php if ($areaId > 0): ?>
            <span class="badge ms-2" style="background:var(--accent-300);color:#1a1005;font-size:0.75rem;"><?= h($areaName) ?></span>
          <?php endif; ?>
        </h5>

        <?php if (empty($latest)): ?>
          <div class="text-muted">No issues yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark-custom align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:80px;">ID</th>
                  <th>Title</th>
                  <th style="width:130px;">Status</th>
                  <th style="width:150px;">Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($latest as $r): ?>
                  <tr>
                    <td>
                      <a href="<?= BASE_URL ?>/admin/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>"
                         style="color:var(--accent-600); text-decoration:none;">
                        #<?= (int)$r['issue_id'] ?>
                      </a>
                    </td>
                    <td>
                      <div class="fw-semibold"><?= h($r['title']) ?></div>
                      <div class="small text-muted">
                        <?= h($r['category_name'] ?? '—') ?> • <?= h($r['area_name'] ?? '—') ?>
                      </div>
                    </td>
                    <td><span class="badge bg-secondary"><?= h($r['status']) ?></span></td>
                    <td class="text-muted small"><?= h($r['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>