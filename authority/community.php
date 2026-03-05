<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Community - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? '');

if ($myAreaId <= 0) {
  http_response_code(403);
  echo "<div class='container py-4 app-container'>
          <div class='alert alert-warning'>
            Your account is not assigned to an area yet. Please contact admin.
          </div>
        </div>";
  require_once __DIR__ . '/../includes/footer_internal.php';
  exit;
}

$sort = trim((string)($_GET['sort'] ?? 'recent'));
if (!in_array($sort, ['recent', 'upvotes', 'comments'], true)) {
  $sort = 'recent';
}

$orderSql = match($sort) {
  'upvotes'  => 'vote_count DESC, i.created_at DESC, i.issue_id DESC',
  'comments' => 'comment_count DESC, i.created_at DESC, i.issue_id DESC',
  default    => 'i.created_at DESC, i.issue_id DESC',
};

$sql = "
  SELECT
    i.issue_id,
    i.title,
    i.status,
    i.created_at,
    COALESCE(v.vote_count, 0) AS vote_count,
    COALESCE(c.comment_count, 0) AS comment_count
  FROM issues i
  LEFT JOIN (
    SELECT issue_id, COUNT(*) AS vote_count
    FROM votes
    GROUP BY issue_id
  ) v ON v.issue_id = i.issue_id
  LEFT JOIN (
    SELECT issue_id, COUNT(*) AS comment_count
    FROM comments
    GROUP BY issue_id
  ) c ON c.issue_id = i.issue_id
  WHERE i.area_id = :area_id
  ORDER BY {$orderSql}
  LIMIT 50
";

$st = $pdo->prepare($sql);
$st->execute([':area_id' => $myAreaId]);
$issues = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold">Community - <?= h($myAreaName ?: 'Designated Area') ?></h2>

    <div class="d-flex align-items-center gap-3">
      <form method="GET" class="d-flex align-items-center gap-2 small">
        <label class="text-muted mb-0">Sort:</label>
        <select name="sort" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
          <option value="recent"   <?= $sort === 'recent'   ? 'selected' : '' ?>>Recently Added</option>
          <option value="upvotes"  <?= $sort === 'upvotes'  ? 'selected' : '' ?>>Most Upvoted</option>
          <option value="comments" <?= $sort === 'comments' ? 'selected' : '' ?>>Most Commented</option>
        </select>
      </form>

      <a class="btn btn-brand btn-sm" href="<?= BASE_URL ?>/authority/leaderboard.php">
        View Leaderboard
      </a>
    </div>
  </div>

  <?php if (empty($issues)): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No community issues found for this area.</div>
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
                <span>⬆ <?= (int)$issue['vote_count'] ?> upvotes</span>
                <span><i class="bi bi-chat-left-text-fill"></i> <?= (int)$issue['comment_count'] ?> comments</span>
              </div>
            </div>

            <a class="btn btn-outline-brand"
               href="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$issue['issue_id'] ?>">
              View Issue
            </a>

          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>