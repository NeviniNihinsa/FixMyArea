<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

/** Citizen area (branch) - force filter */
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
$myAreaName = (string)($me['area_name'] ?? 'Not set');

/** Sort only (Area filter removed) */
$sort = trim($_GET['sort'] ?? 'recent'); // recent | upvotes | comments

/** Sort SQL */
$orderBy = "i.created_at DESC";
if ($sort === 'upvotes')  $orderBy = "upvotes DESC, i.created_at DESC";
if ($sort === 'comments') $orderBy = "comments_count DESC, i.created_at DESC";

/** Feed query (issues in citizen's branch only) */
$params = [];
$where = "WHERE i.area_id = ?";
$params[] = $myAreaId;

$sql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  (SELECT COALESCE(SUM(v.value),0) FROM votes v WHERE v.issue_id=i.issue_id) AS upvotes,
  (SELECT COUNT(*) FROM comments cm WHERE cm.issue_id=i.issue_id) AS comments_count
FROM issues i
{$where}
ORDER BY {$orderBy}
LIMIT 30
";

$st = $pdo->prepare($sql);
$st->execute($params);
$issues = $st->fetchAll(PDO::FETCH_ASSOC);

function status_badge(string $status): string {
  $s = strtoupper($status);
  return '<span class="badge bg-secondary">'.htmlspecialchars($s).'</span>';
}
?>

<div class="container py-4">

  <!-- HEADER ROW (COMMUNITY + Sort only) -->
  <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
    <div>
      <h2 class="fw-bold mb-1" style="letter-spacing:0.5px;">COMMUNITY</h2>
      <div class="text-muted small">Branch: <strong><?= htmlspecialchars($myAreaName) ?></strong></div>
    </div>

    <form method="GET" class="d-flex flex-column flex-md-row align-items-md-center gap-3 ms-lg-auto">

      <!-- Sort -->
      <div class="d-flex align-items-md-center gap-2">
        <label class="text-muted mb-0">Sort:</label>
        <select name="sort" class="form-select" style="min-width:200px;" onchange="this.form.submit()">
          <option value="recent"   <?= $sort==='recent' ? 'selected' : '' ?>>Recently Added</option>
          <option value="upvotes"  <?= $sort==='upvotes' ? 'selected' : '' ?>>Most Upvoted</option>
          <option value="comments" <?= $sort==='comments' ? 'selected' : '' ?>>Most Commented</option>
        </select>
      </div>

      <noscript><button class="btn btn-brand">Apply</button></noscript>
    </form>
  </div>

  <!-- LIST -->
  <div class="d-flex flex-column gap-4">

    <?php if ($myAreaId <= 0): ?>
      <div class="card-dark p-4">
        <div class="text-danger">Your branch (area) is not set. Please update your profile.</div>
      </div>
    <?php elseif (empty($issues)): ?>
      <div class="card-dark p-4">
        <div class="text-muted">No issues found for your branch yet.</div>
      </div>
    <?php endif; ?>

    <?php foreach ($issues as $row): ?>
      <div class="card-dark p-4"
           style="border-radius: 22px; border: 1px solid rgba(241,246,246,0.18);">

        <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3">
          <!-- Left content -->
          <div class="flex-grow-1">
            <div class="fw-semibold mb-2">
              Track ID | Issue Title | Issue Status
            </div>

            <div class="d-flex flex-wrap gap-3 align-items-center">
              <div class="fw-bold">#<?= (int)$row['issue_id'] ?></div>
              <div><?= htmlspecialchars($row['title']) ?></div>
              <div><?= status_badge((string)$row['status']) ?></div>
            </div>

            <div class="mt-3 d-flex flex-wrap gap-4 align-items-center">
              <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold"><?= (int)$row['upvotes'] ?></span>
                <span class="text-muted">upvotes</span>
                <span class="text-muted">▲</span>
              </div>

              <div class="d-flex align-items-center gap-2">
                <span class="fw-semibold"><?= (int)$row['comments_count'] ?></span>
                <span class="text-muted">comments</span>
                <span class="text-muted">💬</span>
              </div>
            </div>
          </div>

          <!-- Right button -->
          <div>
            <a class="btn btn-outline-light px-4 py-2"
               href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= (int)$row['issue_id'] ?>">
              View Issue
            </a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>