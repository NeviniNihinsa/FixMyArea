<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Assigned Issues - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

// ── Filters ──
$filterStatus   = strtoupper(trim((string)($_GET['status']    ?? '')));
$filterLocation = trim((string)($_GET['location'] ?? ''));
$sort           = trim((string)($_GET['sort']     ?? 'newest'));

$allowedStatuses = ['PENDING', 'ASSIGNED', 'IN_PROGRESS', 'COMPLETED', 'CLOSED', 'REJECTED'];
if ($filterStatus !== '' && !in_array($filterStatus, $allowedStatuses, true)) {
  $filterStatus = '';
}
if (!in_array($filterLocation, ['', 'common', 'private'], true)) {
  $filterLocation = '';
}
if (!in_array($sort, ['newest', 'oldest', 'status'], true)) {
  $sort = 'newest';
}

$orderBy = match($sort) {
  'oldest' => 'i.created_at ASC, i.issue_id ASC',
  'status' => 'i.status ASC, i.created_at DESC',
  default  => 'i.created_at DESC, i.issue_id DESC',
};

// ── Build WHERE ──
$where  = ['x.field_worker_id = ?'];
$params = [$userId];

if ($filterStatus !== '') {
  $where[]  = 'i.status = ?';
  $params[] = $filterStatus;
}
if ($filterLocation === 'common') {
  $where[] = 'i.is_common = 1';
} elseif ($filterLocation === 'private') {
  $where[] = 'i.is_common = 0';
}

$whereSql = implode(' AND ', $where);

$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.status, i.created_at,
    i.is_common,
    c.category_name,
    ca.area_name AS common_area_name,
    u.email AS reporter_email,
    u.address AS reporter_address,
    x.assignment_status
  FROM assignments x
  JOIN issues i ON i.issue_id = x.issue_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
  LEFT JOIN users u ON u.user_id = i.reporter_user_id
  WHERE {$whereSql}
  ORDER BY {$orderBy}
");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function niceStatus(string $s): string { return strtoupper(trim($s)); }
function statusBadge(string $s): string {
  return match(strtoupper(trim($s))) {
    'PENDING'     => 'bg-secondary',
    'ASSIGNED'    => 'bg-primary bg-opacity-75',
    'IN_PROGRESS' => 'bg-warning text-dark',
    'COMPLETED'   => 'bg-success',
    'CLOSED'      => 'bg-success',
    'REJECTED'    => 'bg-danger',
    default       => 'bg-secondary',
  };
}
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="fw-bold mb-0">Assigned Issues</h2>
  </div>

  <!-- Filter & Sort bar -->
  <div class="card-dark p-3 mb-3">
    <form method="GET" class="d-flex flex-wrap gap-3 align-items-end">

      <div>
        <label class="form-label text-muted small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" style="min-width:180px;">
          <option value="">All Statuses</option>
          <?php foreach ($allowedStatuses as $s): ?>
            <option value="<?= h($s) ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label text-muted small mb-1">Location Type</label>
        <select name="location" class="form-select form-select-sm" style="min-width:180px;">
          <option value=""       <?= $filterLocation === ''        ? 'selected' : '' ?>>All Locations</option>
          <option value="common" <?= $filterLocation === 'common'  ? 'selected' : '' ?>>Common Area</option>
          <option value="private"<?= $filterLocation === 'private' ? 'selected' : '' ?>>Private Unit</option>
        </select>
      </div>

      <div>
        <label class="form-label text-muted small mb-1">Sort By</label>
        <select name="sort" class="form-select form-select-sm" style="min-width:180px;">
          <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
          <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
          <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>By Status</option>
        </select>
      </div>

      <div class="d-flex gap-2 align-items-end">
        <button class="btn btn-brand btn-sm" type="submit">Apply</button>
        <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/worker/assigned_issues.php">Reset</a>
      </div>

    </form>
  </div>

  <div class="card-dark p-3 p-md-4">
    <div class="table-responsive d-none d-md-block">
      <table class="table table-dark-custom align-middle mb-0">
        <thead>
          <tr>
            <th>Issue ID</th>
            <th>Title</th>
            <th>Category</th>
            <th>Location</th>
            <th>Reported By</th>
            <th>Status</th>
            <th style="width:110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-muted">No assigned issues found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td>
                  <?php if ((int)$r['is_common'] === 1): ?>
                    <span class="badge bg-primary bg-opacity-75">Common</span>
                    <?php if (!empty($r['common_area_name'])): ?>
                      <br><small class="text-muted"><?= h($r['common_area_name']) ?></small>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-secondary bg-opacity-75">Private</span>
                    <?php if (!empty($r['reporter_address'])): ?>
                      <br><small class="text-muted"><?= h($r['reporter_address']) ?></small>
                    <?php endif; ?>
                  <?php endif; ?>
                </td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td><span class="badge <?= statusBadge((string)$r['status']) ?>"><?= h(niceStatus((string)$r['status'])) ?></span></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile cards -->
    <div class="d-md-none d-flex flex-column gap-3">
      <?php if (!$rows): ?>
        <div class="text-muted">No assigned issues found.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <div class="card-dark p-3">
            <div class="d-flex justify-content-between gap-2">
              <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
              <span class="badge <?= statusBadge((string)$r['status']) ?>"><?= h(niceStatus((string)$r['status'])) ?></span>
            </div>
            <div class="text-muted small mt-1">
              <?= h($r['category_name'] ?? '—') ?>
              •
              <?php if ((int)$r['is_common'] === 1): ?>
                <span class="badge bg-primary bg-opacity-75">Common</span>
                <?= !empty($r['common_area_name']) ? h($r['common_area_name']) : '' ?>
              <?php else: ?>
                <span class="badge bg-secondary bg-opacity-75">Private</span>
                <?= !empty($r['reporter_address']) ? h($r['reporter_address']) : '' ?>
              <?php endif; ?>
            </div>
            <div class="text-muted small">Reported by: <?= h($r['reporter_email'] ?? '—') ?></div>
            <div class="mt-2">
              <a class="btn btn-sm btn-outline-brand"
                 href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>