<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Community - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function niceStatus(string $s): string { return strtoupper(trim($s)); }

// 1) Only issues assigned to this worker
$sql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  COALESCE(v.upvotes, 0) AS upvotes,
  COALESCE(c.comments_count, 0) AS comments_count
FROM assignments x
JOIN issues i ON i.issue_id = x.issue_id
LEFT JOIN (
  SELECT issue_id, SUM(CASE WHEN value = 1 THEN 1 ELSE 0 END) AS upvotes
  FROM votes
  GROUP BY issue_id
) v ON v.issue_id = i.issue_id
LEFT JOIN (
  SELECT issue_id, COUNT(*) AS comments_count
  FROM comments
  GROUP BY issue_id
) c ON c.issue_id = i.issue_id
WHERE x.field_worker_id = ?
ORDER BY i.created_at DESC, i.issue_id DESC
LIMIT 50
";
$st = $pdo->prepare($sql);
$st->execute([$userId]);
$assignedIssues = $st->fetchAll(PDO::FETCH_ASSOC);

// 2) Selected issue (default: most recent assigned)
$selectedIssueId = (int)($_GET['issue_id'] ?? 0);
if ($selectedIssueId <= 0 && !empty($assignedIssues)) {
  $selectedIssueId = (int)$assignedIssues[0]['issue_id'];
}

// Get selected issue row
$selected = null;
foreach ($assignedIssues as $r) {
  if ((int)$r['issue_id'] === $selectedIssueId) {
    $selected = $r;
    break;
  }
}

// 3) Comments for selected issue
$comments = [];
if ($selectedIssueId > 0) {
  try {
    $st = $pdo->prepare("
      SELECT c.comment_id, c.comment_text, c.created_at,
             u.name AS user_name
      FROM comments c
      LEFT JOIN users u ON u.user_id = c.user_id
      WHERE c.issue_id = ?
      ORDER BY c.created_at DESC, c.comment_id DESC
      LIMIT 20
    ");
    $st->execute([$selectedIssueId]);
    $comments = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $comments = [];
  }
}
?>

<style>
  /* LFD-ish layout helpers (no theme.css changes) */
  .lfd-title { font-weight: 800; letter-spacing: 0.5px; }
  .issue-box {
    border-radius: 16px;
    border: 1px solid rgba(255,255,255,0.18);
    padding: 18px 18px;
  }
  .comment-pill {
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.18);
    padding: 12px 14px;
  }
  .icon-btn {
    border: 0;
    background: transparent;
    color: rgba(255,255,255,0.75);
    padding: 0;
  }
  .icon-btn:hover { color: rgba(255,255,255,0.95); }
</style>

<div class="container py-4 app-container">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h2 class="lfd-title mb-0">COMMUNITY &amp; Feedbacks</h2>
  </div>

  <?php if (empty($assignedIssues)): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No assigned issues found for you.</div>
    </div>

  <?php else: ?>

    <!-- Top Issue Card (like the LFD) -->
    <?php if ($selected): ?>
      <div class="card-dark issue-box mb-4">
        <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
          <div class="flex-grow-1">
            <div class="fw-semibold">
              Track ID | Issue Title | Issue Status
            </div>

            <div class="mt-2 fw-semibold" style="font-size: 1.05rem;">
              #<?= (int)$selected['issue_id'] ?> |
              <?= h($selected['title']) ?> |
              <?= h(niceStatus((string)$selected['status'])) ?>
            </div>

            <div class="mt-3 d-flex align-items-center gap-4 text-muted small">
              <div class="d-flex align-items-center gap-2">
                <span><?= (int)$selected['upvotes'] ?> upvotes</span>
                <i class="bi bi-caret-up-fill"></i>
              </div>

              <div class="d-flex align-items-center gap-2">
                <span><?= (int)$selected['comments_count'] ?> comments</span>
                <i class="bi bi-chat-square"></i>
              </div>
            </div>
          </div>

          <div class="text-end">
            <a class="btn btn-outline-brand"
               href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$selected['issue_id'] ?>">
              View Issue
            </a>
          </div>
        </div>

        <!-- (Optional) tiny selector - NOT in LFD, but helps testing when you have many issues -->
        <div class="mt-3">
          <form method="GET" class="d-flex gap-2 align-items-center flex-wrap m-0">
            <label class="text-muted small mb-0">Switch Issue:</label>
            <select name="issue_id" class="form-select form-select-sm" style="max-width: 360px;" onchange="this.form.submit()">
              <?php foreach ($assignedIssues as $r): ?>
                <option value="<?= (int)$r['issue_id'] ?>" <?= ((int)$r['issue_id'] === $selectedIssueId) ? 'selected' : '' ?>>
                  #<?= (int)$r['issue_id'] ?> - <?= h($r['title']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm btn-outline-brand" type="submit">Open</button></noscript>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- Comments list (like the LFD small comment boxes) -->
    <div class="d-flex flex-column gap-3 mb-4">
      <?php if (empty($comments)): ?>
        <div class="card-dark comment-pill">
          <div class="text-muted">No comments yet.</div>
        </div>
      <?php else: ?>
        <?php foreach ($comments as $c): ?>
          <div class="card-dark comment-pill d-flex justify-content-between align-items-center gap-3">
            <div class="flex-grow-1">
              <div class="small text-muted mb-1">
                <?= h($c['user_name'] ?? 'User') ?> • <?= h((string)$c['created_at']) ?>
              </div>
              <div><?= h((string)$c['comment_text']) ?></div>
            </div>

            <!-- Right side icons (visual only; worker doesn't vote here) -->
            <div class="d-flex align-items-center gap-3">
              <button type="button" class="icon-btn" title="Up">
                <i class="bi bi-caret-up"></i>
              </button>
              <button type="button" class="icon-btn" title="Comment">
                <i class="bi bi-chat"></i>
              </button>
              <button type="button" class="icon-btn" title="Like">
                <i class="bi bi-heart"></i>
              </button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Add Comment bar + Post button -->
    <div class="card-dark comment-pill">
      <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="d-flex gap-2 align-items-center m-0">
        <input type="hidden" name="issue_id" value="<?= (int)$selectedIssueId ?>">
        <!-- helpful if your comment_add.php supports redirect -->
        <input type="hidden" name="redirect_to" value="<?= h('/worker/community.php?issue_id=' . (int)$selectedIssueId) ?>">

        <input
          type="text"
          name="comment_text"
          class="form-control"
          placeholder="Add Comment"
          required
          maxlength="300"
        >
        <button type="submit" class="btn btn-outline-light" style="min-width:110px;">
          Post
        </button>
      </form>
      <div class="field-error"></div>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>