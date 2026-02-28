<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Manage Issues - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/** Dropdown data */
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);
$cats  = $pdo->query("SELECT category_id, category_name FROM issue_categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

/** Filters */
$q         = trim((string)($_GET['q'] ?? ''));
$areaId    = (int)($_GET['area_id'] ?? 0);
$catId     = (int)($_GET['category_id'] ?? 0);
$status    = strtoupper(trim((string)($_GET['status'] ?? '')));
$dateFrom  = trim((string)($_GET['from'] ?? ''));
$dateTo    = trim((string)($_GET['to'] ?? ''));

$allowedStatuses = ['PENDING','IN_PROGRESS','RESOLVED','COMPLETED','CLOSED','REJECTED'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
  $status = '';
}

/** Build WHERE safely */
$where = [];
$params = [];

if ($q !== '') {
  $where[] = "(i.title LIKE ? OR i.description LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}
if ($areaId > 0) {
  $where[] = "i.area_id = ?";
  $params[] = $areaId;
}
if ($catId > 0) {
  $where[] = "i.category_id = ?";
  $params[] = $catId;
}
if ($status !== '') {
  $where[] = "i.status = ?";
  $params[] = $status;
}
if ($dateFrom !== '') {
  $where[] = "DATE(i.created_at) >= ?";
  $params[] = $dateFrom;
}
if ($dateTo !== '') {
  $where[] = "DATE(i.created_at) <= ?";
  $params[] = $dateTo;
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/**
 * Assigned To:
 * We get the latest assignment row per issue (if your assignments table exists).
 * If you don't have assignments table yet, this query may fail.
 * To keep safe: we try/catch and fallback without assignment.
 */
$baseSql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  a.area_name,
  c.category_name,
  u.name AS reporter_name,
  ip.file_path AS thumb_path,
  w.name AS assigned_to
FROM issues i
LEFT JOIN areas a ON a.area_id = i.area_id
LEFT JOIN issue_categories c ON c.category_id = i.category_id
LEFT JOIN users u ON u.user_id = i.reporter_user_id

/* thumbnail (report photo) */
LEFT JOIN issue_photos ip
  ON ip.issue_id = i.issue_id
 AND ip.photo_type = 'REPORT'

/* latest assignment */
LEFT JOIN (
  SELECT issue_id, MAX(assignment_id) AS max_id
  FROM assignments
  GROUP BY issue_id
) la ON la.issue_id = i.issue_id
LEFT JOIN assignments ass ON ass.assignment_id = la.max_id
LEFT JOIN users w ON w.user_id = ass.field_worker_id

{$whereSql}
ORDER BY i.created_at DESC, i.issue_id DESC
";

/** If export requested => CSV download */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  try {
    $st = $pdo->prepare($baseSql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="issues_export.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Issue ID','Title','Category','Area','Status','Assigned To','Created At']);

    foreach ($rows as $r) {
      fputcsv($out, [
        $r['issue_id'],
        $r['title'],
        $r['category_name'],
        $r['area_name'],
        $r['status'],
        $r['assigned_to'] ?: '',
        $r['created_at'],
      ]);
    }
    fclose($out);
    exit;
  } catch (Throwable $e) {
    // if assignments table doesn't exist yet, fail nicely
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Export failed (check assignments table exists).'];
    header("Location: " . BASE_URL . "/admin/manage_issues.php");
    exit;
  }
}

/** Fetch rows for UI */
$rows = [];
try {
  $st = $pdo->prepare($baseSql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  // fallback query without assignments if table missing
  $fallbackSql = "
  SELECT
    i.issue_id,
    i.title,
    i.status,
    i.created_at,
    a.area_name,
    c.category_name,
    ip.file_path AS thumb_path
  FROM issues i
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN issue_photos ip
    ON ip.issue_id = i.issue_id
   AND ip.photo_type = 'REPORT'
  {$whereSql}
  ORDER BY i.created_at DESC, i.issue_id DESC
  ";
  $st = $pdo->prepare($fallbackSql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function statusBadge(string $s): string {
  $s = strtoupper(trim($s));
  return match ($s) {
    'PENDING' => 'bg-secondary',
    'IN_PROGRESS' => 'bg-info text-dark',
    'RESOLVED','COMPLETED','CLOSED' => 'bg-success',
    'REJECTED' => 'bg-danger',
    default => 'bg-dark'
  };
}

?>
<div class="container py-4 app-container">

  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-3">
    <h2 class="fw-bold mb-0">Manage Issues</h2>

    <div class="d-flex gap-2">
      <a class="btn btn-outline-brand btn-sm"
         href="<?= BASE_URL ?>/admin/manage_issues.php?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>">
        Export Report (CSV)
      </a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <!-- Filter box (matches low-fi) -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <form method="GET" class="row g-3 align-items-end">

      <div class="col-12">
        <div class="text-muted small mb-2">Filter by</div>
      </div>

      <div class="col-12 col-md-6 col-lg-3">
        <label class="form-label">Search</label>
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="title or description...">
      </div>

      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Branch</label>
        <select class="form-select" name="area_id">
          <option value="0">All</option>
          <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
              <?= h($a['area_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Category</label>
        <select class="form-select" name="category_id">
          <option value="0">All</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['category_id'] ?>" <?= ((int)$c['category_id'] === $catId) ? 'selected' : '' ?>>
              <?= h($c['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 col-lg-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <?php foreach ($allowedStatuses as $s): ?>
            <option value="<?= h($s) ?>" <?= ($s === $status) ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-6 col-lg-1">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= h($dateFrom) ?>">
      </div>

      <div class="col-12 col-md-6 col-lg-1">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= h($dateTo) ?>">
      </div>

      <div class="col-12 col-lg-1 d-grid">
        <button class="btn btn-brand" type="submit">Apply</button>
      </div>

    </form>
  </div>

  <h4 class="fw-semibold mb-3">Issues</h4>

  <?php if (empty($rows)): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No issues found.</div>
    </div>
  <?php else: ?>

    <!-- Desktop table -->
    <div class="card-dark p-3 p-md-4 d-none d-md-block">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:110px;">Issue ID</th>
              <th>Title</th>
              <th style="width:140px;">Category</th>
              <th style="width:140px;">Branch</th>
              <th style="width:140px;">Status</th>
              <th style="width:160px;">Assigned To</th>
              <th style="width:180px;">Created</th>
              <th style="width:110px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td class="fw-semibold"><?= h($r['title']) ?></td>
                <td class="text-muted"><?= h($r['category_name'] ?? '') ?></td>
                <td class="text-muted"><?= h($r['area_name'] ?? '') ?></td>
                <td><span class="badge <?= statusBadge((string)$r['status']) ?>"><?= h($r['status']) ?></span></td>
                <td class="text-muted"><?= h($r['assigned_to'] ?? '—') ?></td>
                <td class="text-muted small"><?= h($r['created_at']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/admin/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mobile cards -->
    <div class="d-md-none d-flex flex-column gap-3">
      <?php foreach ($rows as $r): ?>
        <div class="card-dark p-3">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
            <span class="badge <?= statusBadge((string)$r['status']) ?>"><?= h($r['status']) ?></span>
          </div>
          <div class="text-muted small mt-2">
            Category: <?= h($r['category_name'] ?? '') ?> • Branch: <?= h($r['area_name'] ?? '') ?>
          </div>
          <div class="text-muted small">
            Assigned: <?= h($r['assigned_to'] ?? '—') ?> • Created: <?= h($r['created_at']) ?>
          </div>
          <div class="mt-3">
            <a class="btn btn-sm btn-outline-brand"
               href="<?= BASE_URL ?>/admin/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>">
              View
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>