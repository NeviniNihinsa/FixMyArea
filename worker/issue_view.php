<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'View Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) {
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

// Ensure issue is assigned to this worker
$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.description, i.status, i.created_at,
    c.category_name,
    a.area_name,
    u.name AS reporter_name, u.email AS reporter_email
  FROM assignments x
  JOIN issues i ON i.issue_id = x.issue_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN users u ON u.user_id = i.reporter_user_id
  WHERE x.field_worker_id = ? AND i.issue_id = ?
  LIMIT 1
");
$st->execute([$userId, $issueId]);
$issue = $st->fetch(PDO::FETCH_ASSOC);
if (!$issue) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'You do not have access to this issue.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

// photos
$st = $pdo->prepare("
  SELECT photo_id, photo_type, file_path, created_at
  FROM issue_photos
  WHERE issue_id = ?
  ORDER BY photo_id ASC
");
$st->execute([$issueId]);
$photos = $st->fetchAll(PDO::FETCH_ASSOC);

// timeline
$st = $pdo->prepare("
  SELECT h.status, h.note, h.created_at, u.name AS changed_by
  FROM issue_status_history h
  LEFT JOIN users u ON u.user_id = h.changed_by_user_id
  WHERE h.issue_id = ?
  ORDER BY h.created_at DESC, h.history_id DESC
");
try {
  $st->execute([$issueId]);
} catch (Throwable $e) {
  $st = $pdo->prepare("
    SELECT h.status, h.note, h.created_at, u.name AS changed_by
    FROM issue_status_history h
    LEFT JOIN users u ON u.user_id = h.changed_by_user_id
    WHERE h.issue_id = ?
    ORDER BY h.created_at DESC
  ");
  $st->execute([$issueId]);
}
$history = $st->fetchAll(PDO::FETCH_ASSOC);

// comments
$comments = [];
try {
  $st = $pdo->prepare("
    SELECT c.comment_id, c.comment_text, c.created_at, u.name
    FROM comments c
    LEFT JOIN users u ON u.user_id = c.user_id
    WHERE c.issue_id=?
    ORDER BY c.created_at DESC, c.comment_id DESC
  ");
  $st->execute([$issueId]);
  $comments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $comments = [];
}

// upvotes count
$upvotes = 0;
try {
  $st = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM votes WHERE issue_id=?");
  $st->execute([$issueId]);
  $upvotes = (int)$st->fetchColumn();
} catch (Throwable $e) {
  $upvotes = 0;
}

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function photoUrl(string $path): string {
  if ($path === '') return '';
  return str_starts_with($path, '/')
    ? (BASE_URL . $path)
    : (BASE_URL . '/' . ltrim($path, '/'));
}
?>

<div class="container py-4 app-container">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
    <div>
      <h2 class="fw-bold mb-1">Issue ID: #<?= (int)$issue['issue_id'] ?> - <?= h($issue['title']) ?></h2>
      <div class="text-muted small">
        Reported by: <span class="fw-semibold"><?= h($issue['reporter_name'] ?? $issue['reporter_email'] ?? '—') ?></span>
        &nbsp; • &nbsp; Category: <span class="fw-semibold"><?= h($issue['category_name'] ?? '—') ?></span>
        &nbsp; • &nbsp; Status: <span class="fw-semibold"><?= h((string)$issue['status']) ?></span>
      </div>
    </div>

    <div class="text-end">
      <div class="text-muted small">Upvotes</div>
      <div class="fw-bold fs-4"><?= (int)$upvotes ?></div>
    </div>
  </div>

  <div class="row g-4">
    <!-- LEFT -->
    <div class="col-12 col-lg-8">

      <div class="card-dark p-4 mb-4">
        <div class="fw-semibold mb-2">Issue Photos & Description:</div>

        <div class="text-muted mb-3"><?= nl2br(h($issue['description'] ?? '')) ?></div>

        <!-- REPORT photos -->
        <div class="mb-3">
          <div class="small text-muted mb-2">Reported Photos</div>
          <div class="d-flex flex-wrap gap-2">
            <?php
              $hasReport = false;
              foreach ($photos as $p):
                if (($p['photo_type'] ?? '') !== 'REPORT') continue;
                $hasReport = true;
            ?>
              <a href="<?= h(photoUrl((string)$p['file_path'])) ?>" target="_blank" rel="noreferrer">
                <img src="<?= h(photoUrl((string)$p['file_path'])) ?>" alt="report"
                     style="width:110px;height:90px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,0.12);">
              </a>
            <?php endforeach; ?>
            <?php if (!$hasReport): ?>
              <div class="text-muted small">No report photo yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Proof of Fix -->
        <div class="mt-4">
          <div class="fw-semibold mb-2">Proof of Fix:</div>
          <div class="d-flex flex-wrap gap-2">
            <?php
              $hasProof = false;
              foreach ($photos as $p):
                $t = (string)($p['photo_type'] ?? '');
                if ($t !== 'FIX_PROOF') continue;
                $hasProof = true;
            ?>
              <a href="<?= h(photoUrl((string)$p['file_path'])) ?>" target="_blank" rel="noreferrer">
                <img src="<?= h(photoUrl((string)$p['file_path'])) ?>" alt="<?= h($t) ?>"
                     style="width:110px;height:90px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,0.12);">
              </a>
            <?php endforeach; ?>
            <?php if (!$hasProof): ?>
              <div class="text-muted small">No proof uploaded yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex gap-2 flex-wrap mt-4">
          <!-- Update Status -->
          <form method="POST" action="<?= BASE_URL ?>/actions/worker_issue_update_status.php" class="d-flex gap-2 flex-wrap m-0">
            <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
            <select name="status" class="form-select" style="min-width:220px;" required>
              <option value="">Update status...</option>
              <option value="PENDING">PENDING</option>
              <option value="IN_PROGRESS">IN_PROGRESS</option>
              <option value="RESOLVED">RESOLVED</option>
              <option value="CLOSED">CLOSED</option>
            </select>
            <button class="btn btn-outline-brand" type="submit">Update Status</button>
          </form>

          <!-- Upload Proof -->
          <form method="POST" action="<?= BASE_URL ?>/actions/worker_issue_upload_proof.php"
                enctype="multipart/form-data" class="d-flex gap-2 flex-wrap m-0">
            <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
            <select name="photo_type" class="form-select" style="min-width:220px;" required>
              <option value="">Proof type...</option>
              <option value="FIX_PROOF">FIX_PROOF</option>
            </select>
            <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
            <button class="btn btn-outline-light" type="submit">Upload Proof</button>
          </form>
        </div>

      </div>

      <!-- Comments -->
      <div class="card-dark p-4">
        <div class="fw-semibold mb-3">Comments</div>

        <?php if (!$comments): ?>
          <div class="text-muted small">No comments yet.</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($comments as $c): ?>
              <div class="card-dark p-3">
                <div class="d-flex justify-content-between">
                  <div class="fw-semibold"><?= h($c['name'] ?? 'User') ?></div>
                  <div class="text-muted small"><?= h((string)$c['created_at']) ?></div>
                </div>
                <div class="text-muted mt-2"><?= nl2br(h((string)$c['comment_text'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="mt-3">
          <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
          <div class="d-flex gap-2">
            <input type="text" name="comment_text" class="form-control" placeholder="Add comment..." required maxlength="300">
            <button class="btn btn-brand" type="submit">Add Comment</button>
          </div>
          <div class="text-muted small mt-1">If you don’t have `actions/comment_add.php` yet, tell me — I’ll generate it.</div>
        </form>
      </div>

    </div>

    <!-- RIGHT: timeline -->
    <div class="col-12 col-lg-4">
      <div class="card-dark p-4">
        <div class="fw-semibold mb-3">Timeline</div>
        <?php if (!$history): ?>
          <div class="text-muted small">No history yet.</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($history as $hrow): ?>
              <div class="card-dark p-3">
                <div class="fw-semibold"><?= h((string)$hrow['status']) ?></div>
                <div class="text-muted small"><?= h((string)$hrow['created_at']) ?></div>
                <?php if (!empty($hrow['changed_by'])): ?>
                  <div class="text-muted small">By: <?= h((string)$hrow['changed_by']) ?></div>
                <?php endif; ?>
                <?php if (!empty($hrow['note'])): ?>
                  <div class="text-muted small mt-2"><?= h((string)$hrow['note']) ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>