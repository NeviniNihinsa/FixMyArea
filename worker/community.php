<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Community - FixMyArea';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// Fetch the worker's assigned area
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? '');

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

// Guard: worker must have an area assigned
if ($myAreaId <= 0) {
  echo "<div class='container py-4 app-container'>
          <div class='alert alert-warning'>
            Your account is not assigned to an area yet. Please contact your Property Manager.
          </div>
          <a class='btn btn-outline-brand btn-sm mt-2' href='" . BASE_URL . "/worker/home.php'>← Back to Dashboard</a>
        </div>";
  require_once __DIR__ . '/../includes/footer_internal.php';
  exit;
}

// Sort handling
$sort = trim((string)($_GET['sort'] ?? 'recent'));
if (!in_array($sort, ['recent', 'upvotes', 'comments'], true)) {
  $sort = 'recent';
}

$orderBy = match($sort) {
  'upvotes'  => 'vote_count DESC, i.created_at DESC, i.issue_id DESC',
  'comments' => 'comment_count DESC, i.created_at DESC, i.issue_id DESC',
  default    => 'i.created_at DESC, i.issue_id DESC',
};

// FIX 1: scope to worker's area only
// FIX 2: use COUNT(*) for votes (consistent with schema where value=1 always)
$sql = "
  SELECT
    i.issue_id,
    i.title,
    i.status,
    i.created_at,
    COALESCE(v.vote_count, 0)    AS vote_count,
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
  ORDER BY {$orderBy}
  LIMIT 50
";

$st = $pdo->prepare($sql);
$st->execute([':area_id' => $myAreaId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// FIX 3: check if each issue is assigned to this worker so we can set the correct link
$st = $pdo->prepare("SELECT issue_id FROM assignments WHERE field_worker_id = ?");
$st->execute([$userId]);
$assignedIssueIds = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="font-size: 2.4rem;">Community — <?= h($myAreaName ?: 'My Area') ?></h1>

    <div class="d-flex align-items-center gap-3 flex-wrap">

      <form method="GET" class="d-flex align-items-center gap-2 small">
        <label class="text-muted mb-0">Sort:</label>
        <select name="sort" class="form-select form-select-sm" style="width:auto;"
                onchange="this.form.submit()">
          <option value="recent"   <?= $sort === 'recent'   ? 'selected' : '' ?>>Recently Added</option>
          <option value="upvotes"  <?= $sort === 'upvotes'  ? 'selected' : '' ?>>Most Upvoted</option>
          <option value="comments" <?= $sort === 'comments' ? 'selected' : '' ?>>Most Commented</option>
        </select>
      </form>

      <a class="btn btn-brand btn-sm" href="<?= BASE_URL ?>/worker/leaderboard.php">
        View Leaderboard
      </a>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No issues found for your area yet.</div>
    </div>
  <?php else: ?>

    <div class="d-flex flex-column gap-3">
      <?php foreach ($rows as $r): ?>
        <?php
          // FIX 3: only link to issue_view.php if this worker is assigned to it
          $isAssigned = isset($assignedIssueIds[(int)$r['issue_id']]);
          $viewUrl = $isAssigned
            ? BASE_URL . '/worker/issue_view.php?issue_id=' . (int)$r['issue_id']
            : null;
        ?>
        <div class="card-dark p-3 p-md-4"
             style="border-radius:16px;border:1px solid rgba(255,255,255,0.10);">
          <div class="d-flex justify-content-between align-items-start gap-3">

            <div class="flex-grow-1">
              <div class="fw-semibold" style="font-size:1.05rem;">
                #<?= (int)$r['issue_id'] ?> |
                <?= h($r['title']) ?> |
                <?= h($r['status']) ?>
              </div>

              <div class="mt-2 d-flex flex-wrap gap-4 text-muted small">
                <span>⬆ <?= (int)$r['vote_count'] ?> upvotes</span>
                <span><i class="bi bi-chat-left-text-fill"></i> <?= (int)$r['comment_count'] ?> comments</span>
              </div>
            </div>

            <div class="text-end">
              <?php if ($viewUrl): ?>
                <a class="btn btn-outline-brand" href="<?= h($viewUrl) ?>">View Issue</a>
              <?php else: ?>
                <span class="btn btn-outline-secondary disabled" title="Not assigned to you">View Issue</span>
              <?php endif; ?>
            </div>

          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>