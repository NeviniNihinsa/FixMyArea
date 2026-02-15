<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);

$page_title = "Issue View";

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) {
    echo "<div class='container py-5 text-danger'>Invalid issue.</div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);

/* Flash messages */
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* Fetch issue */
$st = $pdo->prepare("
    SELECT i.issue_id, i.reporter_user_id, i.area_id, i.category_id, i.title, i.description,
           i.lat, i.lng, i.status, i.created_at,
           u.name AS reporter_name,
           a.area_name,
           c.category_name
    FROM issues i
    JOIN users u ON u.user_id = i.reporter_user_id
    JOIN areas a ON a.area_id = i.area_id
    JOIN issue_categories c ON c.category_id = i.category_id
    WHERE i.issue_id = ?
    LIMIT 1
");
$st->execute([$issueId]);
$issue = $st->fetch(PDO::FETCH_ASSOC);

if (!$issue) {
    echo "<div class='container py-5 text-danger'>Issue not found.</div>";
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

/* Photos */
$st = $pdo->prepare("SELECT photo_type, file_path FROM issue_photos WHERE issue_id = ? ORDER BY photo_id ASC");
$st->execute([$issueId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$reportPhotos = [];
$proofBefore  = [];
$proofAfter   = [];

foreach ($rows as $r) {
    if ($r['photo_type'] === 'REPORT') $reportPhotos[] = $r['file_path'];
    if ($r['photo_type'] === 'PROOF_BEFORE') $proofBefore[] = $r['file_path'];
    if ($r['photo_type'] === 'PROOF_AFTER')  $proofAfter[]  = $r['file_path'];
}

/* Timeline */
$st = $pdo->prepare("
    SELECT status, note, created_at
    FROM issue_status_history
    WHERE issue_id = ?
    ORDER BY created_at ASC
");
$st->execute([$issueId]);
$timeline = $st->fetchAll(PDO::FETCH_ASSOC);

/* Votes */
$st = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE issue_id = ? AND value = 1");
$st->execute([$issueId]);
$totalVotes = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT 1 FROM votes WHERE issue_id = ? AND user_id = ? AND value = 1 LIMIT 1");
$st->execute([$issueId, $userId]);
$alreadyVoted = (bool)$st->fetchColumn();

/* Comments */
$st = $pdo->prepare("
    SELECT c.comment_id, c.comment_text, c.created_at, u.name
    FROM comments c
    JOIN users u ON u.user_id = c.user_id
    WHERE c.issue_id = ?
    ORDER BY c.created_at DESC
");
$st->execute([$issueId]);
$comments = $st->fetchAll(PDO::FETCH_ASSOC);

/* Feedback (citizen may have already submitted) */
$st = $pdo->prepare("
    SELECT feedback_id, overall_rating, worker_rating, authority_rating, feedback_text, created_at
    FROM feedback_ratings
    WHERE issue_id = ? AND citizen_user_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$st->execute([$issueId, $userId]);
$myFeedback = $st->fetch(PDO::FETCH_ASSOC);

/* Helper: render stars */
function stars(int $n): string {
    $n = max(0, min(5, $n));
    $out = '';
    for ($i=1; $i<=5; $i++) $out .= ($i <= $n) ? '★' : '☆';
    return $out;
}
?>

<div class="container py-4 app-container">

  <?php if ($flash_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
  <?php endif; ?>
  <?php if ($flash_error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flash_error) ?></div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- LEFT -->
    <div class="col-12 col-lg-8">

      <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
          <h3 class="fw-bold mb-2">
            Issue ID: #<?= (int)$issue['issue_id'] ?> - <?= htmlspecialchars($issue['title']) ?>
          </h3>

          <div class="small text-muted mb-3">
            Reported by: <strong><?= htmlspecialchars($issue['reporter_name']) ?></strong> &nbsp; | &nbsp;
            Category: <strong><?= htmlspecialchars($issue['category_name']) ?></strong> &nbsp; | &nbsp;
            Status: <span class="badge bg-secondary"><?= htmlspecialchars($issue['status']) ?></span>
          </div>
        </div>

        <!-- Upvotes box (top right like low-fi) -->
        <div class="text-center">
          <form method="POST" action="<?= BASE_URL ?>/actions/vote_toggle.php">
            <input type="hidden" name="issue_id" value="<?= $issueId ?>">
            <button type="submit" class="btn btn-outline-brand btn-sm">
              <?= $alreadyVoted ? '▲ Upvoted' : '▲ Upvote' ?>
            </button>
          </form>
          <div class="mt-2 small text-muted"><?= $totalVotes ?> Upvotes</div>
        </div>
      </div>

      <!-- Issue Photos & Description -->
      <div class="card-dark p-4 mb-4">
        <h6 class="fw-semibold mb-3">Issue Photos &amp; Description:</h6>

        <?php if (!$reportPhotos): ?>
          <div class="text-muted small mb-3">No report photos uploaded.</div>
        <?php else: ?>
          <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach ($reportPhotos as $img): ?>
              <img src="<?= BASE_URL . $img ?>"
                   alt="Issue photo"
                   style="width:150px;height:110px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,0.10);">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div style="white-space:pre-wrap;"><?= htmlspecialchars($issue['description']) ?></div>
      </div>

      <!-- Proof of Fix -->
      <div class="card-dark p-4 mb-4">
        <h6 class="fw-semibold mb-3">Proof of Fix:</h6>

        <?php if (!$proofBefore && !$proofAfter): ?>
          <div class="text-muted small">No proof photos yet.</div>
        <?php else: ?>
          <?php if ($proofBefore): ?>
            <div class="small text-muted mb-2">Proof Before:</div>
            <div class="d-flex flex-wrap gap-2 mb-3">
              <?php foreach ($proofBefore as $img): ?>
                <img src="<?= BASE_URL . $img ?>"
                     alt="Proof before"
                     style="width:150px;height:110px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,0.10);">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($proofAfter): ?>
            <div class="small text-muted mb-2">Proof After:</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($proofAfter as $img): ?>
                <img src="<?= BASE_URL . $img ?>"
                     alt="Proof after"
                     style="width:150px;height:110px;object-fit:cover;border-radius:10px;border:1px solid rgba(255,255,255,0.10);">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <!-- Timeline -->
      <div class="card-dark p-4 mb-4">
        <h6 class="fw-semibold mb-3">Status Timeline</h6>
        <?php if (!$timeline): ?>
          <div class="text-muted small">No history yet.</div>
        <?php else: ?>
          <ul class="mb-0">
            <?php foreach ($timeline as $t): ?>
              <li class="small mb-2">
                <strong><?= htmlspecialchars($t['status']) ?></strong>
                <span class="text-muted"> — <?= htmlspecialchars($t['created_at']) ?></span>
                <?php if (!empty($t['note'])): ?>
                  <div class="text-muted"><?= htmlspecialchars($t['note']) ?></div>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <!-- Comments -->
      <div class="card-dark p-4">
        <h6 class="fw-semibold mb-3">Comments</h6>

        <div style="max-height:220px; overflow:auto; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:12px;">
          <?php if (!$comments): ?>
            <div class="text-muted small">No comments yet.</div>
          <?php else: ?>
            <?php foreach ($comments as $c): ?>
              <div class="mb-3">
                <div class="small">
                  <strong><?= htmlspecialchars($c['name']) ?></strong>
                  <span class="text-muted"> · <?= htmlspecialchars($c['created_at']) ?></span>
                </div>
                <div style="white-space:pre-wrap;"><?= htmlspecialchars($c['comment_text']) ?></div>
              </div>
              <hr style="border-color: rgba(241,246,246,0.08);">
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="mt-3">
          <input type="hidden" name="issue_id" value="<?= $issueId ?>">
          <label class="form-label mt-2">Add Comment</label>
          <textarea name="comment_text" class="form-control" rows="3" required></textarea>
          <button class="btn btn-outline-brand mt-2" type="submit">Add Comment</button>
        </form>
      </div>

    </div>

    <!-- RIGHT -->
    <div class="col-12 col-lg-4">

      <div class="card-dark p-4">
        <h6 class="fw-semibold mb-3">Service Ratings:</h6>

        <?php if ($myFeedback): ?>
          <div class="mb-3">
            <div class="small text-muted">Overall Issue Fixation</div>
            <div class="fs-5" style="color:#ffd36b;"><?= stars((int)$myFeedback['overall_rating']) ?></div>
          </div>
          <div class="mb-3">
            <div class="small text-muted">Field Worker</div>
            <div class="fs-5" style="color:#ffd36b;"><?= stars((int)$myFeedback['worker_rating']) ?></div>
          </div>
          <div class="mb-3">
            <div class="small text-muted">Local Authority</div>
            <div class="fs-5" style="color:#ffd36b;"><?= stars((int)$myFeedback['authority_rating']) ?></div>
          </div>

          <?php if (!empty($myFeedback['feedback_text'])): ?>
            <div class="small text-muted">Your feedback</div>
            <div class="mb-3" style="white-space:pre-wrap;"><?= htmlspecialchars($myFeedback['feedback_text']) ?></div>
          <?php endif; ?>

          <button class="btn btn-outline-brand w-100" disabled>Feedback Submitted</button>

        <?php else: ?>

          <form method="POST" action="<?= BASE_URL ?>/actions/feedback_add.php">
            <input type="hidden" name="issue_id" value="<?= $issueId ?>">

            <div class="mb-3">
              <label class="form-label">Overall Issue Fixation (1-5)</label>
              <select name="overall_rating" class="form-select" required>
                <option value="">Select</option>
                <?php for($i=1;$i<=5;$i++): ?>
                  <option value="<?= $i ?>"><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Field Worker (1-5)</label>
              <select name="worker_rating" class="form-select" required>
                <option value="">Select</option>
                <?php for($i=1;$i<=5;$i++): ?>
                  <option value="<?= $i ?>"><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Local Authority (1-5)</label>
              <select name="authority_rating" class="form-select" required>
                <option value="">Select</option>
                <?php for($i=1;$i<=5;$i++): ?>
                  <option value="<?= $i ?>"><?= $i ?></option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Feedback Text</label>
              <textarea name="feedback_text" class="form-control" rows="3" maxlength="500"></textarea>
            </div>

            <button class="btn btn-brand w-100" type="submit">Add Feedback</button>
          </form>

        <?php endif; ?>

      </div>

    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>