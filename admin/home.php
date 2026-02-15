<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Admin Home - FixMyArea';
require_once __DIR__ . '/../includes/header.php';


$navApp = __DIR__ . '/../includes/navbar_app.php';
if (file_exists($navApp)) {
    require_once $navApp;
} else {
    require_once __DIR__ . '/../includes/navbar.php';
}

function countTable(PDO $pdo, string $table): int {
    $st = $pdo->query("SELECT COUNT(*) FROM `$table`");
    return (int)$st->fetchColumn();
}

function countIssuesByStatus(PDO $pdo, array $statuses): int {
    if (empty($statuses)) return 0;
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $st = $pdo->prepare("SELECT COUNT(*) FROM issues WHERE status IN ($in)");
    $st->execute($statuses);
    return (int)$st->fetchColumn();
}

$totalUsers  = countTable($pdo, 'users');
$totalIssues = countTable($pdo, 'issues');

$pending   = countIssuesByStatus($pdo, ['PENDING']);
$inProg    = countIssuesByStatus($pdo, ['IN_PROGRESS']);
$completed = countIssuesByStatus($pdo, ['COMPLETED', 'CLOSED']);

$latest = [];
$st = $pdo->query("
    SELECT i.issue_id, i.title, i.status, i.created_at,
           a.area_name, c.category_name
    FROM issues i
    LEFT JOIN areas a ON a.area_id = i.area_id
    LEFT JOIN issue_categories c ON c.category_id = i.category_id
    ORDER BY i.created_at DESC
    LIMIT 5
");
$latest = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container py-4 app-container">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
    <div>
      <h2 class="fw-bold mb-1">Admin Dashboard</h2>
      <div class="text-muted small">System overview</div>
    </div>

    <div class="d-flex gap-2">
      <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">Manage Users</a>
      <a class="btn btn-brand" href="<?= BASE_URL ?>/admin/manage_issues.php">Manage Issues</a>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-12 col-md-6 col-lg-3">
      <div class="card-dark p-3">
        <div class="text-muted small">Total Users</div>
        <div class="fs-3 fw-bold"><?= $totalUsers ?></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card-dark p-3">
        <div class="text-muted small">Total Issues</div>
        <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card-dark p-3">
        <div class="text-muted small">Pending</div>
        <div class="fs-3 fw-bold"><?= $pending ?></div>
      </div>
    </div>

    <div class="col-12 col-md-6 col-lg-3">
      <div class="card-dark p-3">
        <div class="text-muted small">Completed</div>
        <div class="fs-3 fw-bold"><?= $completed ?></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Quick Actions</h5>
        <div class="d-grid gap-2">
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">View Users</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/add_user.php">Add New User</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_issues.php">View Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/profile.php">My Profile</a>
        </div>

        <hr style="border-color: rgba(241,246,246,0.10);" class="my-4">

        <div class="text-muted small">In Progress: <span class="fw-semibold"><?= $inProg ?></span></div>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Latest Issues</h5>

        <?php if (empty($latest)): ?>
          <div class="text-muted">No issues yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark-custom align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:100px;">ID</th>
                  <th>Title</th>
                  <th style="width:140px;">Status</th>
                  <th style="width:160px;">Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($latest as $r): ?>
                  <tr>
                    <td>#<?= (int)$r['issue_id'] ?></td>
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