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

$sort = trim($_GET['sort'] ?? 'recent');
if (!in_array($sort, ['recent', 'upvotes', 'comments'], true)) {
  $sort = 'recent';
}

$orderBy = match($sort) {
  'upvotes'  => 'upvotes DESC, i.created_at DESC',
  'comments' => 'comments_count DESC, i.created_at DESC',
  default    => 'i.created_at DESC',
};

$sql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  (SELECT COALESCE(SUM(v.value),0) FROM votes v WHERE v.issue_id=i.issue_id) AS upvotes,
  (SELECT COUNT(*) FROM comments cm WHERE cm.issue_id=i.issue_id) AS comments_count
FROM issues i
WHERE i.area_id = ?
ORDER BY {$orderBy}
LIMIT 30
";

$st = $pdo->prepare($sql);
$st->execute([$myAreaId]);
$issues = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold">Community</h2>

    <div class="d-flex align-items-center gap-3">
      <form method="GET" class="d-flex align-items-center gap-2 small">
        <label class="text-muted mb-0">Sort:</label>
        <select name="sort" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
          <option value="recent"   <?= $sort === 'recent'   ? 'selected' : '' ?>>Recently Added</option>
          <option value="upvotes"  <?= $sort === 'upvotes'  ? 'selected' : '' ?>>Most Upvoted</option>
          <option value="comments" <?= $sort === 'comments' ? 'selected' : '' ?>>Most Commented</option>
        </select>
      </form>
    </div>
  </div>

  <?php if ($myAreaId <= 0): ?>
    <div class="card-dark p-4">
      <div class="text-danger">Your branch (area) is not set. Please update your profile.</div>
    </div>
  <?php elseif (empty($issues)): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No issues found for your branch yet.</div>
    </div>
  <?php else: ?>

    <div class="d-flex flex-column gap-4">
      <?php foreach ($issues as $issue): ?>
        <div class="card-dark p-4 rounded-4">
          <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">

            <div>
              <div class="fw-semibold mb-2">
                #<?= (int)$issue['issue_id'] ?> |
                <?= h($issue['title']) ?> |
                <?= h($issue['status']) ?>
              </div>

              <div class="text-muted small d-flex gap-4">
                <span>⬆ <?= (int)$issue['upvotes'] ?> upvotes</span>
                <span><i class="bi bi-chat-left-text-fill"></i> <?= (int)$issue['comments_count'] ?> comments</span>
              </div>
            </div>

            <a class="btn btn-outline-brand"
               href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= (int)$issue['issue_id'] ?>">
              View Issue
            </a>

          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>