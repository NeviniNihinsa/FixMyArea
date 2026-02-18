<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority']);
if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Community - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);

// ✅ get authority area
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId   = isset($me['area_id']) ? (int)$me['area_id'] : 0;
$myAreaName = (string)($me['area_name'] ?? '');

if ($myAreaId <= 0) {
  http_response_code(403);
  echo "403 Forbidden (Authority has no assigned area)";
  exit;
}

// --- sort options (small dropdown in low-fi) ---
$sort = (string)($_GET['sort'] ?? 'recent'); // recent | top
$sort = in_array($sort, ['recent','top'], true) ? $sort : 'recent';

$orderSql = ($sort === 'top')
  ? "vote_count DESC, i.created_at DESC"
  : "i.created_at DESC";

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
  ORDER BY $orderSql
  LIMIT 50
";
$st = $pdo->prepare($sql);
$st->execute([':area_id' => $myAreaId]);
$issues = $st->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php';
?>

<div class="app-container">
  <div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <h2 class="fw-bold mb-0">COMMUNITY - <?= htmlspecialchars($myAreaName ?: 'Designated Area') ?></h2>

      <form method="GET" class="d-flex align-items-center gap-2">
        <label class="text-muted small mb-0">Sort:</label>
        <select name="sort" class="form-select form-select-sm" style="width: 180px;" onchange="this.form.submit()">
          <option value="recent" <?= $sort==='recent'?'selected':'' ?>>Recently Added</option>
          <option value="top" <?= $sort==='top'?'selected':'' ?>>Most Upvoted</option>
        </select>
      </form>
    </div>

    <?php if (!$issues): ?>
      <div class="card-dark p-4 text-muted">No community issues found for this area.</div>
    <?php else: ?>

      <div class="d-flex flex-column gap-3">
        <?php foreach ($issues as $i): ?>
          <?php
            $trackId = 'ISS' . str_pad((string)$i['issue_id'], 4, '0', STR_PAD_LEFT);
            $title = (string)$i['title'];
            $status = (string)$i['status'];
            $votes = (int)$i['vote_count'];
            $comments = (int)$i['comment_count'];
          ?>

          <div class="card-dark p-3">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
              <div class="flex-grow-1">
                <div class="fw-semibold">
                  Track ID | <?= htmlspecialchars($trackId) ?> |
                  <?= htmlspecialchars($title) ?> |
                  <span class="text-muted"><?= htmlspecialchars($status) ?></span>
                </div>

                <div class="mt-2 d-flex flex-wrap gap-3 text-muted small align-items-center">
                  <span><?= $votes ?> upvotes</span>
                  <span><?= $comments ?> comments</span>
                </div>
              </div>

              <div>
                <a class="btn btn-outline-brand"
                   href="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$i['issue_id'] ?>">
                  View Issue
                </a>
              </div>
            </div>
          </div>

        <?php endforeach; ?>
      </div>

    <?php endif; ?>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>