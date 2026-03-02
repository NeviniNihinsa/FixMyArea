<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Community - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$sql = "
SELECT
  i.issue_id,
  i.title,
  i.status,
  i.created_at,
  COALESCE(v.upvotes, 0) AS upvotes,
  COALESCE(c.comments_count, 0) AS comments_count
FROM issues i
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
ORDER BY i.created_at DESC, i.issue_id DESC
LIMIT 50
";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h1 class="fw-bold mb-0" style="font-size: 2.4rem;">Community</h1>

    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="text-muted small">
        Sort: <span class="fw-semibold">Recently Added</span>
      </div>

      <!-- ✅ NEW: View Leaderboard button (same style as authority) -->
      <a class="btn btn-brand btn-sm" href="<?= BASE_URL ?>/worker/leaderboard.php">
        View Leaderboard
      </a>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No issues yet.</div>
    </div>
  <?php else: ?>

    <div class="d-flex flex-column gap-3">
      <?php foreach ($rows as $r): ?>
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
                <div><?= (int)$r['upvotes'] ?> upvotes</div>
                <div><?= (int)$r['comments_count'] ?> comments</div>
              </div>
            </div>

            <div class="text-end">
              <a class="btn btn-outline-brand"
                 href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">
                View Issue
              </a>
            </div>

          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>