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

/*Filters*/
$status = trim((string)($_GET['status'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

/*Fetch categories */
$categories = $pdo->query("
  SELECT category_id, category_name
  FROM issue_categories
  ORDER BY category_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*Build WHERE*/
$where = ["i.reporter_user_id = ?"];
$params = [$userId];

if ($status !== '') {
  $where[] = "i.status = ?";
  $params[] = $status;
}

if ($categoryId > 0) {
  $where[] = "i.category_id = ?";
  $params[] = $categoryId;
}

if ($q !== '') {
  $where[] = "(i.title LIKE ? OR i.description LIKE ?)";
  $params[] = "%" . $q . "%";
  $params[] = "%" . $q . "%";
}

$whereSql = implode(" AND ", $where);

/*Count total*/
$stCount = $pdo->prepare("SELECT COUNT(*) FROM issues i WHERE $whereSql");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/*Thumbnail subquery*/
$thumbJoinSql = "
LEFT JOIN (
  SELECT ip.issue_id, ip.file_path
  FROM issue_photos ip
  INNER JOIN (
    SELECT issue_id, MAX(photo_id) AS max_photo_id
    FROM issue_photos
    WHERE photo_type = 'REPORT'
    GROUP BY issue_id
  ) t ON t.issue_id = ip.issue_id AND t.max_photo_id = ip.photo_id
) ph ON ph.issue_id = i.issue_id
";

/*Fetch issues list*/
$sql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  a.area_name,
  c.category_name,
  ph.file_path AS thumb_path
FROM issues i
LEFT JOIN areas a ON a.area_id = i.area_id
LEFT JOIN issue_categories c ON c.category_id = i.category_id
$thumbJoinSql
WHERE $whereSql
ORDER BY i.created_at DESC
LIMIT $perPage OFFSET $offset
";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/*Status badge helper*/
function status_badge_class(string $s): string {
  $s = strtoupper(trim($s));
  return match ($s) {
    'PENDING' => 'bg-secondary',
    'IN_PROGRESS' => 'bg-warning text-dark',
    'RESOLVED' => 'bg-info text-dark',
    'COMPLETED' => 'bg-success',
    'CLOSED' => 'bg-success',
    'REJECTED' => 'bg-danger',
    default => 'bg-secondary'
  };
}

/*Flash*/
$created = (int)($_GET['created'] ?? 0);
$createdIssueId = (int)($_GET['issue_id'] ?? 0);
?>

<div class="container py-4">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <h2 class="fw-bold mb-0">Track Issues</h2>

    <a class="btn btn-brand" href="<?= BASE_URL ?>/citizen/report_issue.php">+ Report New Issue</a>
  </div>

  <?php if ($created === 1 && $createdIssueId > 0): ?>
    <div class="alert alert-success">
       Issue submitted successfully. Track ID: <strong>#<?= $createdIssueId ?></strong>
      <a class="ms-2" href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= $createdIssueId ?>">View</a>
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <form method="GET" class="row g-3 align-items-end">

      <div class="col-12 col-md-4">
        <label class="form-label">Search</label>
        <input type="text" name="q" class="form-control" placeholder="title or description..."
               value="<?= htmlspecialchars($q) ?>">
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Category</label>
        <select name="category_id" class="form-select">
          <option value="0">All categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['category_id'] ?>" <?= ((int)$c['category_id'] === $categoryId) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['category_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-3">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
          <option value="">All statuses</option>
          <?php
            $statusOptions = ['PENDING','IN_PROGRESS','RESOLVED','COMPLETED','CLOSED','REJECTED'];
            foreach ($statusOptions as $opt):
          ?>
            <option value="<?= $opt ?>" <?= ($status === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-brand" type="submit">Apply</button>
      </div>

      <?php if ($q !== '' || $categoryId > 0 || $status !== ''): ?>
        <div class="col-12">
          <a class="btn btn-sm btn-outline-brand" href="<?= BASE_URL ?>/citizen/track_issue.php">Clear filters</a>
        </div>
      <?php endif; ?>

    </form>
  </div>

  <!-- Results -->
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
              <th style="width:110px;">Track ID</th>
              <th style="width:90px;">Photo</th>
              <th>Title</th>
              <th style="width:160px;">Category</th>
              <th style="width:160px;">Area</th>
              <th style="width:140px;">Status</th>
              <th style="width:190px;">Created</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>

                <td>
                  <?php if (!empty($r['thumb_path'])): ?>
                    <img src="<?= htmlspecialchars(BASE_URL . $r['thumb_path']) ?>"
                         alt="thumb"
                         style="width:64px;height:44px;object-fit:cover;border-radius:8px;border:1px solid rgba(241,246,246,0.12);">
                  <?php else: ?>
                    <span class="text-muted small">—</span>
                  <?php endif; ?>
                </td>

                <td><?= htmlspecialchars($r['title']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($r['category_name'] ?? '—') ?></td>
                <td class="text-muted"><?= htmlspecialchars($r['area_name'] ?? '—') ?></td>

                <td>
                  <span class="badge <?= status_badge_class((string)$r['status']) ?>">
                    <?= htmlspecialchars((string)$r['status']) ?>
                  </span>
                </td>

                <td class="text-muted"><?= htmlspecialchars((string)$r['created_at']) ?></td>

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
    </div>

    <!-- Mobile cards -->
    <div class="d-md-none">
      <div class="row g-3">
        <?php foreach ($rows as $r): ?>
          <div class="col-12">
            <div class="card-dark p-3">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= htmlspecialchars($r['title']) ?></div>
                  <div class="small text-muted">
                    <?= htmlspecialchars($r['category_name'] ?? '—') ?> • <?= htmlspecialchars($r['area_name'] ?? '—') ?>
                  </div>
                </div>
                <span class="badge <?= status_badge_class((string)$r['status']) ?>">
                  <?= htmlspecialchars((string)$r['status']) ?>
                </span>
              </div>

              <?php if (!empty($r['thumb_path'])): ?>
                <img class="mt-3"
                     src="<?= htmlspecialchars(BASE_URL . $r['thumb_path']) ?>"
                     alt="thumb"
                     style="width:100%;height:180px;object-fit:cover;border-radius:12px;border:1px solid rgba(241,246,246,0.12);">
              <?php endif; ?>

              <div class="mt-2 small text-muted">Created: <?= htmlspecialchars((string)$r['created_at']) ?></div>

              <div class="mt-3 d-grid">
                <a class="btn btn-outline-brand"
                   href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">
                  View Details
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <nav class="mt-4">
        <ul class="pagination justify-content-center">
          <?php
            // preserve filters in pagination links
            $baseParams = $_GET;
            unset($baseParams['page']);
            $mk = function(int $p) use ($baseParams): string {
              $baseParams['page'] = $p;
              return '?' . http_build_query($baseParams);
            };
          ?>

          <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $mk(max(1, $page - 1)) ?>">Prev</a>
          </li>

          <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
          ?>
            <li class="page-item <?= ($p === $page) ? 'active' : '' ?>">
              <a class="page-link" href="<?= $mk($p) ?>"><?= $p ?></a>
            </li>
          <?php endfor; ?>

          <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $mk(min($totalPages, $page + 1)) ?>">Next</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
