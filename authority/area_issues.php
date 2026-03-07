<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function niceStatus(string $s): string {
  return str_replace('_', ' ', strtoupper(trim($s)));
}

function statusBadge(string $s): string {
  return match(strtoupper(trim($s))) {
    'PENDING'     => 'bg-secondary',
    'ASSIGNED'    => 'bg-primary bg-opacity-75',
    'IN_PROGRESS' => 'bg-warning text-dark',
    'COMPLETED'   => 'bg-success',
    'CLOSED'      => 'bg-success',
    'REOPENED'    => 'bg-info text-dark',
    'REJECTED'    => 'bg-danger',
    default       => 'bg-secondary',
  };
}

/** Get authority branch/area */
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? '');

if ($myAreaId <= 0) {
  // Now it's safe to show HTML because export won't run when area is missing
  $page_title = 'Area Issues - FixMyArea';
  require_once __DIR__ . '/../includes/header.php';
  require_once __DIR__ . '/../includes/navbar.php';

  echo '<div class="container py-4 app-container">';
  echo '<div class="alert alert-warning">Your account is not assigned to a branch/area yet. Please contact admin.</div>';
  echo '</div>';

  require_once __DIR__ . '/../includes/footer_internal.php';
  exit;
}

/** Categories */
$categories = $pdo->query("
  SELECT category_id, category_name
  FROM issue_categories
  ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/** Field workers (same branch/area) */
$st = $pdo->prepare("
  SELECT user_id, name
  FROM users
  WHERE LOWER(role) IN ('field worker','worker')
    AND LOWER(status) = 'active'
    AND area_id = ?
  ORDER BY name ASC
");
$st->execute([$myAreaId]);
$fieldWorkers = $st->fetchAll(PDO::FETCH_ASSOC);

/** Filters */
$q             = trim((string)($_GET['q'] ?? ''));
$fieldWorkerId = (int)($_GET['field_worker_id'] ?? 0);
$status        = trim((string)($_GET['status'] ?? ''));
$categoryId    = (int)($_GET['category_id'] ?? 0);
$dateFrom      = trim((string)($_GET['date_from'] ?? ''));
$dateTo        = trim((string)($_GET['date_to'] ?? ''));

$allowedStatuses = ['','PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED'];
if (!in_array($status, $allowedStatuses, true)) $status = '';

$where  = ["i.area_id = ?"];
$params = [$myAreaId];

if ($q !== '') {
  $where[] = "(i.title LIKE ? OR CAST(i.issue_id AS CHAR) LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

if ($fieldWorkerId > 0) {
  $where[] = "la.field_worker_id = ?";
  $params[] = $fieldWorkerId;
}

if ($status !== '') {
  $where[] = "i.status = ?";
  $params[] = $status;
}

if ($categoryId > 0) {
  $where[] = "i.category_id = ?";
  $params[] = $categoryId;
}

if ($dateFrom !== '') {
  $where[] = "DATE(i.created_at) >= ?";
  $params[] = $dateFrom;
}
if ($dateTo !== '') {
  $where[] = "DATE(i.created_at) <= ?";
  $params[] = $dateTo;
}

$whereSql = implode(" AND ", $where);

$sqlBase = "
  SELECT
    i.issue_id,
    i.title,
    i.created_at,
    i.status,
    c.category_name,
    a.area_name,
    ru.email AS reporter_email,
    fw.name AS field_worker_name
  FROM issues i
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  INNER JOIN areas a ON a.area_id = i.area_id
  INNER JOIN users ru ON ru.user_id = i.reporter_user_id
  LEFT JOIN assignments la
    ON la.assignment_id = (
      SELECT MAX(a2.assignment_id)
      FROM assignments a2
      WHERE a2.issue_id = i.issue_id
    )
  LEFT JOIN users fw ON fw.user_id = la.field_worker_id
  WHERE {$whereSql}
";

/** Export CSV (MUST be before header/navbar output) */
$isExport = (isset($_GET['export']) && (string)$_GET['export'] === '1');

if ($isExport) {
  $st = $pdo->prepare($sqlBase . " ORDER BY i.created_at DESC, i.issue_id DESC");
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // Safety: if any output buffer exists, clear it
  if (ob_get_length()) { @ob_clean(); }

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=area_issues_report.csv');
  header('Pragma: no-cache');
  header('Expires: 0');

  $out = fopen('php://output', 'w');
  fputcsv($out, [
    'Issue ID','Issue Date','Title','Category','Branch','Reported By','Field Worker','Status'
  ]);

  foreach ($rows as $r) {
    fputcsv($out, [
      $r['issue_id'],
      $r['created_at'],
      $r['title'],
      $r['category_name'] ?? '',
      $r['area_name'] ?? '',
      $r['reporter_email'] ?? '',
      $r['field_worker_name'] ?? 'Not Assigned',
      $r['status'] ?? '',
    ]);
  }
  fclose($out);
  exit;
}

/** Load issues for screen */
$st = $pdo->prepare($sqlBase . " ORDER BY i.created_at DESC, i.issue_id DESC LIMIT 200");
$st->execute($params);
$issues = $st->fetchAll(PDO::FETCH_ASSOC);

/** Keep filters in links */
function qs(array $overrides = []): string {
  $current = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($current[$k]);
    else $current[$k] = $v;
  }
  return http_build_query($current);
}

/** NOW include header/navbar (after export is handled) */
$page_title = 'Area Issues - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
  .area-readonly{
    background: rgba(0,0,0,0.18) !important;
    border: 1px solid var(--border) !important;
    color: var(--text) !important;
  }
  .area-wrap{
    white-space: normal !important;
    word-break: break-word !important;
  }
</style>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-start align-items-md-center flex-column flex-md-row gap-2 mb-3">
    <div>
      <h2 class="fw-bold mb-1">Area Issues</h2>
      <div class="text-muted small">
        Showing all issues in <span class="fw-semibold"><?= h($myAreaName) ?></span>
      </div>
    </div>

    <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/authority/area_issues.php?<?= h(qs(['export' => '1'])) ?>">
      Generate Report
    </a>
  </div>

  <div class="card-dark p-3 p-md-4 mb-3">
    <form method="GET" action="<?= BASE_URL ?>/authority/area_issues.php">
      <div class="row g-3 align-items-end">

        <div class="col-12 col-md-6 col-lg-3">
          <label class="form-label">Search Issue</label>
          <input class="form-control" type="text" name="q" value="<?= h($q) ?>" placeholder="Search Issue">
        </div>

        <div class="col-6 col-md-4 col-lg-2">
          <label class="form-label">Technician</label>
          <select name="field_worker_id" class="form-select">
            <option value="0">All</option>
            <?php foreach ($fieldWorkers as $fw): ?>
              <option value="<?= (int)$fw['user_id'] ?>" <?= ($fieldWorkerId === (int)$fw['user_id']) ? 'selected' : '' ?>>
                <?= h((string)$fw['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">All</option>
            <?php foreach ($allowedStatuses as $s): if ($s === '') continue; ?>
              <option value="<?= h($s) ?>" <?= ($status === $s) ? 'selected' : '' ?>>
                <?= h(niceStatus($s)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
          <label class="form-label">Category</label>
          <select name="category_id" class="form-select">
            <option value="0">All</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['category_id'] ?>" <?= ($categoryId === (int)$c['category_id']) ? 'selected' : '' ?>>
                <?= h((string)$c['category_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-4 col-lg-3">
          <label class="form-label">Branch</label>
          <input type="text" class="form-control area-readonly area-wrap" value="<?= h($myAreaName) ?>" readonly>
        </div>

        <div class="col-6 col-md-4 col-lg-2">
          <label class="form-label">Date From</label>
          <input class="form-control" type="date" name="date_from" value="<?= h($dateFrom) ?>">
        </div>

        <div class="col-6 col-md-4 col-lg-2">
          <label class="form-label">Date To</label>
          <input class="form-control" type="date" name="date_to" value="<?= h($dateTo) ?>">
        </div>

        <div class="col-12 col-md-4 col-lg-2 d-flex gap-2">
          <button class="btn btn-brand w-100" type="submit">Apply</button>
          <a class="btn btn-outline-light w-100" href="<?= BASE_URL ?>/authority/area_issues.php">Reset</a>
        </div>

      </div>
    </form>
  </div>

  <div class="card-dark p-3 p-md-4">
    <div class="table-responsive">
      <table class="table table-dark-custom align-middle mb-0">
        <thead>
          <tr>
            <th style="width:90px;">Issue ID</th>
            <th style="width:170px;">Issue Date</th>
            <th style="min-width:240px;">Title</th>
            <th style="width:160px;">Category</th>            
            <th style="min-width:220px;">Reported By</th>
            <th style="min-width:160px;">Assigned To</th>
            <th style="width:130px;">Status</th>
            <th style="width:110px;">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($issues)): ?>
          <tr>
            <td colspan="9" class="text-muted">No issues found.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($issues as $it): ?>
            <tr>
              <td>#<?= (int)$it['issue_id'] ?></td>
              <td class="text-muted small"><?= h((string)$it['created_at']) ?></td>
              <td><?= h((string)$it['title']) ?></td>
              <td><?= h((string)($it['category_name'] ?? '')) ?></td>
              <td><?= h((string)($it['reporter_email'] ?? '')) ?></td>
              <td><?= h((string)($it['field_worker_name'] ?? 'Not Assigned')) ?></td>
              <td><span class="badge <?= statusBadge((string)($it['status'] ?? '')) ?>"><?= h(niceStatus((string)($it['status'] ?? ''))) ?></span></td>
              <td>
                <a class="btn btn-sm btn-outline-brand"
                   href="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$it['issue_id'] ?>">
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

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>