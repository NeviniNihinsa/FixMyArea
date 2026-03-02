<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/notify.php'; // ✅ ADDED (only change #1)

require_roles(['authority', 'local authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fileUrl(string $path): string {
  $path = trim($path);
  if ($path === '') return '';
  if (str_starts_with($path, 'http')) return $path;
  if (str_starts_with($path, '/')) return BASE_URL . $path;
  return BASE_URL . '/' . $path;
}
function stars(?int $val): string {
  if ($val === null) return '<span class="text-muted small">N/A</span>';
  $out = '';
  for ($i = 1; $i <= 5; $i++) $out .= ($i <= $val) ? '★ ' : '☆ ';
  return '<span style="font-size:22px; line-height:1;">' . $out . '</span>';
}

/* -----------------------------
   0) Flash
------------------------------ */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* -----------------------------
   1) Authority area (must be assigned)
------------------------------ */
$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$userId]);
$myAreaId = (int)($st->fetchColumn() ?: 0);

if ($myAreaId <= 0) {
  http_response_code(403);
  echo "<div style='padding:20px;font-family:Arial'>403 Forbidden (Authority has no assigned area)</div>";
  exit;
}

/* -----------------------------
   2) Get issue id (GET)
------------------------------ */
$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) $issueId = (int)($_GET['id'] ?? 0);

if ($issueId <= 0) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger'>Invalid issue.</div></div>";
  exit;
}

/* -----------------------------
   3) Handle ASSIGN in SAME FILE (POST)
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'assign_worker') {
  $postIssueId = (int)($_POST['issue_id'] ?? 0);
  $workerId    = (int)($_POST['field_worker_id'] ?? 0);

  if ($postIssueId <= 0 || $workerId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid assignment request.'];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }

  // Ensure issue belongs to authority area
  $st = $pdo->prepare("SELECT issue_id FROM issues WHERE issue_id=? AND area_id=? LIMIT 1");
  $st->execute([$postIssueId, $myAreaId]);
  $okIssue = $st->fetchColumn();
  if (!$okIssue) {
    http_response_code(403);
    echo "403 Forbidden (Issue not in your area)";
    exit;
  }

  // Ensure worker is active + same area
  $st = $pdo->prepare("
    SELECT user_id
    FROM users
    WHERE user_id = ?
      AND area_id = ?
      AND LOWER(status) = 'active'
      AND LOWER(role) IN ('field worker','worker')
    LIMIT 1
  ");
  $st->execute([$workerId, $myAreaId]);
  $okWorker = $st->fetchColumn();

  if (!$okWorker) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Selected worker is not active or not in your area.'];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }

  try {
    $pdo->beginTransaction();

    // Prevent duplicate active assignment
    $chk = $pdo->prepare("
      SELECT assignment_id
      FROM assignments
      WHERE issue_id = ?
        AND assignment_status IN ('ASSIGNED','ACCEPTED')
      ORDER BY assigned_at DESC, assignment_id DESC
      LIMIT 1
    ");
    $chk->execute([$postIssueId]);
    $already = $chk->fetchColumn();

    if ($already) {
      $pdo->rollBack();
      $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'This issue already has an active assignment.'];
      header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
      exit;
    }

    // Get reporter id + worker name (used for notifications)
    $st = $pdo->prepare("
      SELECT i.reporter_user_id, u.name AS worker_name
      FROM issues i
      JOIN users u ON u.user_id = ?
      WHERE i.issue_id = ?
      LIMIT 1
    ");
    $st->execute([$workerId, $postIssueId]);
    $tmp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $reporterId = (int)($tmp['reporter_user_id'] ?? 0);
    $workerName = (string)($tmp['worker_name'] ?? 'Field Worker');

    /**
     * IMPORTANT: your DB has FK:
     * assignments.assigned_by_authority_id -> users.user_id
     * so we MUST insert assigned_by_authority_id.
     */
    $ins = $pdo->prepare("
      INSERT INTO assignments (issue_id, field_worker_id, assigned_by_authority_id, assignment_status, assigned_at)
      VALUES (?, ?, ?, 'ASSIGNED', NOW())
    ");
    $ins->execute([$postIssueId, $workerId, $userId]);

    // Update issue status to ASSIGNED if currently PENDING
    $upd = $pdo->prepare("
      UPDATE issues
      SET status = CASE WHEN status = 'PENDING' THEN 'ASSIGNED' ELSE status END
      WHERE issue_id = ? AND area_id = ?
      LIMIT 1
    ");
    $upd->execute([$postIssueId, $myAreaId]);

    // ✅ ADDED (only change #2): insert notifications
    create_notification(
      $pdo,
      $workerId,
      $postIssueId,
      'ASSIGNMENT',
      'New assignment',
      "You have been assigned a new issue (#{$postIssueId}).",
      "/worker/view_issue.php?issue_id={$postIssueId}"
    );

    if ($reporterId > 0) {
      create_notification(
        $pdo,
        $reporterId,
        $postIssueId,
        'STATUS',
        'Issue assigned',
        "Your issue (#{$postIssueId}) has been assigned to {$workerName}.",
        "/citizen/issue_view.php?issue_id={$postIssueId}"
      );
    }

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Worker assigned successfully.'];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId . "&assigned=1");
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Failed to assign worker: ' . $e->getMessage()];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }
}

/* -----------------------------
   4) Load issue (must be in this authority area)
------------------------------ */
$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.description, i.status, i.created_at, i.area_id,
    u.name AS reporter_name,
    u.email AS reporter_email,
    a.area_name,
    c.category_name
  FROM issues i
  JOIN users u ON u.user_id = i.reporter_user_id
  JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  WHERE i.issue_id = ?
    AND i.area_id = ?
  LIMIT 1
");
$st->execute([$issueId, $myAreaId]);
$issue = $st->fetch(PDO::FETCH_ASSOC);

if (!$issue) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger'>Issue not found (or not in your area).</div></div>";
  exit;
}

/* Upvotes */
$st = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE issue_id=?");
$st->execute([$issueId]);
$upvotes = (int)$st->fetchColumn();

/* Report photos (top 3) */
$st = $pdo->prepare("
  SELECT file_path
  FROM issue_photos
  WHERE issue_id=? AND photo_type='REPORT'
  ORDER BY photo_id DESC
");
$st->execute([$issueId]);
$reportPhotos = $st->fetchAll(PDO::FETCH_COLUMN);

/* Comments */
$st = $pdo->prepare("
  SELECT c.comment_text, c.created_at, u.name
  FROM comments c
  JOIN users u ON u.user_id = c.user_id
  WHERE c.issue_id=?
  ORDER BY c.created_at DESC, c.comment_id DESC
");
$st->execute([$issueId]);
$comments = $st->fetchAll(PDO::FETCH_ASSOC);

/* Field workers list (same area) */
$st = $pdo->prepare("
  SELECT user_id, name, email
  FROM users
  WHERE area_id = ?
    AND LOWER(role) IN ('field worker','worker')
    AND LOWER(status) = 'active'
  ORDER BY name
");
$st->execute([$myAreaId]);
$fieldWorkers = $st->fetchAll(PDO::FETCH_ASSOC);

/* Current active assignment (ASSIGNED/ACCEPTED) */
$st = $pdo->prepare("
  SELECT a.assignment_status, u.name AS worker_name, u.email AS worker_email
  FROM assignments a
  JOIN users u ON u.user_id = a.field_worker_id
  WHERE a.issue_id = ?
    AND a.assignment_status IN ('ASSIGNED','ACCEPTED')
  ORDER BY a.assigned_at DESC, a.assignment_id DESC
  LIMIT 1
");
$st->execute([$issueId]);
$activeAssign = $st->fetch(PDO::FETCH_ASSOC);

/* Ratings */
$overall = $worker = $authority = null;
try {
  $st = $pdo->prepare("
    SELECT
      AVG(overall_rating)   AS overall_avg,
      AVG(worker_rating)    AS worker_avg,
      AVG(authority_rating) AS authority_avg
    FROM feedback_ratings
    WHERE issue_id = ?
  ");
  $st->execute([$issueId]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $overall   = !empty($r['overall_avg'])   ? (int)round((float)$r['overall_avg'])   : null;
  $worker    = !empty($r['worker_avg'])    ? (int)round((float)$r['worker_avg'])    : null;
  $authority = !empty($r['authority_avg']) ? (int)round((float)$r['authority_avg']) : null;
} catch (Throwable $e) {}

/* Status options */
$allowedStatuses = ['PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED'];
$currentStatus = (string)$issue['status'];

/* -----------------------------
   5) Render
------------------------------ */
$page_title = 'View Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<style>
  .meta-row{display:flex;flex-wrap:wrap;gap:18px;align-items:center;}
  .meta-row .meta{ color: var(--muted-400); font-size:0.95rem; }
  .upvote-box{display:flex;align-items:center;gap:10px;justify-content:flex-end;min-width:150px;}
  .tri{width:0;height:0;border-left:14px solid transparent;border-right:14px solid transparent;border-bottom:22px solid rgba(255,173,82,0.6);}
  .photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
  .photo{width:100%;height:110px;border-radius:12px;border:1px solid var(--border);background:rgba(0,0,0,0.12);overflow:hidden;display:flex;align-items:center;justify-content:center;}
  .photo img{width:100%;height:100%;object-fit:cover;display:block;}
  .desc-box{border:1px solid var(--border);border-radius:14px;padding:12px;background:rgba(0,0,0,0.08);min-height:110px;}
  .comment-box{min-height:160px;resize:vertical;}
  .ratings-row{display:flex;justify-content:space-between;gap:12px;align-items:center;}
  @media (max-width:768px){
    .photo-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
    .upvote-box{justify-content:flex-start;}
  }
</style>

<div class="container py-4 app-container">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>">
      <?= h($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <div class="card-dark p-3 p-md-4">

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
      <div>
        <h3 class="fw-bold mb-2">
          Issue ID: #<?= (int)$issue['issue_id'] ?> - &lt;<?= h($issue['title']) ?>&gt;
        </h3>

        <div class="meta-row">
          <div class="meta"><span class="fw-semibold text-light">Reported by:</span> <?= h($issue['reporter_name']) ?></div>
          <div class="meta"><span class="fw-semibold text-light">Category:</span> <?= h($issue['category_name'] ?? '—') ?></div>
          <div class="meta"><span class="fw-semibold text-light">Status:</span> <?= h($currentStatus) ?></div>

          <form class="d-flex flex-wrap gap-2 align-items-center" method="POST" action="<?= BASE_URL ?>/actions/authority_update_status.php">
            <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
            <select name="status" class="form-select" style="max-width:190px;" required>
              <?php foreach ($allowedStatuses as $s): ?>
                <option value="<?= h($s) ?>" <?= ($s === $currentStatus) ? 'selected' : '' ?>><?= h($s) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-brand btn-sm" type="submit">Update Status</button>
          </form>
        </div>
      </div>

      <div class="upvote-box">
        <div class="tri"></div>
        <div class="text-end">
          <div class="fw-semibold"><?= (int)$upvotes ?> Upvotes</div>
        </div>
      </div>
    </div>

    <div class="mt-3">
      <div class="card-dark p-3">
        <label class="form-label mb-1">Assign Field Worker</label>

        <?php if ($activeAssign): ?>
          <div class="text-muted small mb-2">
            Currently assigned to: <span class="text-light fw-semibold"><?= h($activeAssign['worker_name']) ?></span>
            (<?= h($activeAssign['assignment_status']) ?>)
          </div>
        <?php endif; ?>

        <form method="POST" class="d-flex flex-wrap gap-2 align-items-center">
          <input type="hidden" name="action" value="assign_worker">
          <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

          <select name="field_worker_id" class="form-select" style="max-width:360px;" <?= $activeAssign ? 'disabled' : '' ?> required>
            <option value="">Select worker</option>
            <?php foreach ($fieldWorkers as $w): ?>
              <option value="<?= (int)$w['user_id'] ?>">
                <?= h($w['name'] . ' (' . $w['email'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button class="btn btn-brand btn-sm" type="submit" <?= $activeAssign ? 'disabled' : '' ?>
                  onclick="return confirm('Assign this field worker to the issue?');">
            Assign
          </button>
        </form>
      </div>
    </div>

    <hr style="border-color: var(--border);" class="my-3">

    <div class="row g-3">
      <div class="col-12 col-lg-7">
        <div class="fw-semibold mb-2">Issue Photos &amp; Description:</div>

        <div class="photo-grid mb-3">
          <?php
            $slice = array_slice($reportPhotos, 0, 3);
            $count = count($slice);
          ?>
          <?php if ($count === 0): ?>
            <?php for ($i=0; $i<3; $i++): ?>
              <div class="photo text-muted small">No Photo</div>
            <?php endfor; ?>
          <?php else: ?>
            <?php foreach ($slice as $p): ?>
              <div class="photo"><img src="<?= h(fileUrl((string)$p)) ?>" alt="Issue photo"></div>
            <?php endforeach; ?>
            <?php for ($i=$count; $i<3; $i++): ?>
              <div class="photo text-muted small">No Photo</div>
            <?php endfor; ?>
          <?php endif; ?>
        </div>

        <div class="desc-box">
          <?= nl2br(h($issue['description'] ?? '')) ?>
        </div>
      </div>

      <div class="col-12 col-lg-5">
        <div class="fw-semibold mb-2">Proof of Fix:</div>
        <div class="photo-grid">
          <?php for ($i=0; $i<3; $i++): ?>
            <div class="photo text-muted small">Placeholder</div>
          <?php endfor; ?>
        </div>
        <div class="text-muted small mt-2">
          Proof images are handled by the field worker process (no upload from authority).
        </div>
      </div>
    </div>

    <hr style="border-color: var(--border);" class="my-3">

    <div class="fw-bold mb-2">Service Ratings:</div>
    <div class="card-dark p-3 mb-3">
      <div class="d-flex flex-column gap-2">
        <div class="ratings-row">
          <div class="text-muted">Overall Issue Fixation:</div>
          <div><?= stars($overall) ?></div>
        </div>
        <div class="ratings-row">
          <div class="text-muted">Field Worker:</div>
          <div><?= stars($worker) ?></div>
        </div>
        <div class="ratings-row">
          <div class="text-muted">Local Authority:</div>
          <div><?= stars($authority) ?></div>
        </div>
      </div>
    </div>

    <div class="fw-semibold mb-2">Comments</div>

    <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="mb-3">
      <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
      <textarea name="comment_text" class="form-control comment-box" placeholder="Write a comment..." required></textarea>
      <div class="d-flex justify-content-end mt-2">
        <button class="btn btn-brand btn-sm" type="submit">Add Comment</button>
      </div>
    </form>

    <div class="card-dark p-3">
      <?php if (empty($comments)): ?>
        <div class="text-muted">No comments yet.</div>
      <?php else: ?>
        <div class="d-flex flex-column gap-3">
          <?php foreach ($comments as $c): ?>
            <div style="border-bottom:1px solid var(--border); padding-bottom:10px;">
              <div class="d-flex justify-content-between gap-2">
                <div class="fw-semibold"><?= h($c['name'] ?? '') ?></div>
                <div class="text-muted small"><?= h($c['created_at'] ?? '') ?></div>
              </div>
              <div class="text-muted"><?= nl2br(h($c['comment_text'] ?? '')) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>

<?php if (!empty($_GET['assigned']) && (int)$_GET['assigned'] === 1): ?>
<script>
  alert("Successfully assigned!");
</script>
<?php endif; ?>