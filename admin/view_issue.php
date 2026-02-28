<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'View Issue - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$adminId = (int)($_SESSION['user_id'] ?? 0);

$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) {
    echo '<div class="container py-4"><div class="alert alert-danger">Invalid issue id.</div></div>';
    require_once __DIR__ . '/../includes/footer_internal.php';
    exit;
}

// flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// 1) Load issue + reporter + area + category
$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.description, i.status, i.created_at,
    i.lat, i.lng,
    u.user_id AS reporter_id, u.name AS reporter_name, u.address AS unit_number,
    a.area_name,
    c.category_name
  FROM issues i
  JOIN users u ON u.user_id = i.reporter_user_id
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  WHERE i.issue_id = ?
  LIMIT 1
");
$st->execute([$issueId]);
$issue = $st->fetch(PDO::FETCH_ASSOC);

if (!$issue) {
    echo '<div class="container py-4"><div class="alert alert-danger">Issue not found.</div></div>';
    require_once __DIR__ . '/../includes/footer_internal.php';
    exit;
}

// 2) Upvotes count (if table exists)
$upvotes = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM issue_votes WHERE issue_id=?");
    $st->execute([$issueId]);
    $upvotes = (int)$st->fetchColumn();
} catch (Throwable $e) {
    // if you don't have issue_votes table yet, ignore
    $upvotes = 0;
}

// 3) Photos (your ENUM: REPORT, PROOF_BEFORE, PROOF_AFTER)
$photos = [
    'REPORT' => [],
    'PROOF_BEFORE' => [],
    'PROOF_AFTER' => [],
];

$st = $pdo->prepare("
  SELECT photo_id, photo_type, file_path, created_at
  FROM issue_photos
  WHERE issue_id=?
  ORDER BY photo_id DESC
");
$st->execute([$issueId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $r) {
    $type = strtoupper(trim((string)$r['photo_type']));
    if (!isset($photos[$type])) continue;
    $photos[$type][] = $r;
}

// 4) Timeline
$timeline = [];
try {
    $st = $pdo->prepare("
      SELECT h.status, h.note, h.created_at, u.name AS by_name, u.role AS by_role
      FROM issue_status_history h
      LEFT JOIN users u ON u.user_id = h.changed_by_user_id
      WHERE h.issue_id = ?
      ORDER BY h.created_at DESC, h.history_id DESC
    ");
    $st->execute([$issueId]);
    $timeline = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $timeline = [];
}

// 5) Comments (read-only)
$comments = [];
try {
    $st = $pdo->prepare("
      SELECT c.comment_text, c.created_at, u.name AS user_name, u.role AS user_role
      FROM issue_comments c
      JOIN users u ON u.user_id = c.user_id
      WHERE c.issue_id = ?
      ORDER BY c.created_at DESC
      LIMIT 100
    ");
    $st->execute([$issueId]);
    $comments = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $comments = [];
}

// 6) Citizen Feedback / Ratings
$feedbacks = [];
try {
    $st = $pdo->prepare("
      SELECT f.overall_rating, f.worker_rating, f.authority_rating,
             f.feedback_text, f.created_at,
             u.name AS citizen_name
      FROM feedback_ratings f
      JOIN users u ON u.user_id = f.citizen_user_id
      WHERE f.issue_id = ?
      ORDER BY f.created_at DESC
    ");
    $st->execute([$issueId]);
    $feedbacks = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $feedbacks = [];
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function stars(int $n): string {
    $n = max(0, min(5, $n));
    $out = '';
    for ($i = 1; $i <= 5; $i++) $out .= ($i <= $n) ? '★' : '☆';
    return $out;
}

$allowedStatuses = ['PENDING','IN_PROGRESS','RESOLVED','COMPLETED','CLOSED','REJECTED'];
?>
<div class="app-container">
  <div class="container py-4">

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <!-- Header row -->
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3 mb-4">
      <div>
        <h2 class="fw-bold mb-1">Issue ID: #<?= (int)$issue['issue_id'] ?> - <?= h($issue['title']) ?></h2>
        <div class="text-muted small">
          Reported by: <span class="fw-semibold"><?= h($issue['reporter_name']) ?></span>
          &nbsp;|&nbsp; Category: <span class="fw-semibold"><?= h($issue['category_name'] ?? '-') ?></span>
          &nbsp;|&nbsp; Branch: <span class="fw-semibold"><?= h($issue['area_name'] ?? '-') ?></span>
          &nbsp;|&nbsp; Unit Number: <span class="fw-semibold"><?= h($issue['unit_number'] ?? '-') ?></span>
          &nbsp;|&nbsp; Status: <span class="badge bg-secondary"><?= h($issue['status']) ?></span>
        </div>
      </div>

      <div class="text-end">
        <div class="text-muted small">Upvotes</div>
        <div class="fs-4 fw-bold"><?= (int)$upvotes ?></div>
      </div>
    </div>

    <div class="row g-4">

      <!-- LEFT: Photos + Description + Comments -->
      <div class="col-12 col-lg-8">

        <!-- Issue Photos & Description -->
        <div class="card-dark p-4 mb-4">
          <h5 class="fw-semibold mb-3">Issue Photos &amp; Description</h5>

          <?php if (!empty($photos['REPORT'])): ?>
            <div class="d-flex flex-wrap gap-3 mb-3">
              <?php foreach ($photos['REPORT'] as $p): ?>
                <a href="<?= h(BASE_URL . $p['file_path']) ?>" target="_blank" class="text-decoration-none">
                  <img src="<?= h(BASE_URL . $p['file_path']) ?>"
                       alt="Report photo"
                       style="width:120px;height:90px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,0.15);">
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="text-muted">No report photo found.</div>
          <?php endif; ?>

          <div class="mt-3">
            <div class="text-muted small mb-1">Description</div>
            <div><?= nl2br(h($issue['description'])) ?></div>
          </div>
        </div>

        <!-- Proof of Fix -->
        <div class="card-dark p-4 mb-4">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <h5 class="fw-semibold mb-3">Proof of Fix</h5>

            <!-- Upload proof button -->
            <button class="btn btn-outline-brand btn-sm" data-bs-toggle="collapse" data-bs-target="#proofUpload">
              Upload Proof
            </button>
          </div>

          <div class="collapse" id="proofUpload">
            <div class="mt-3 p-3" style="border:1px solid rgba(255,255,255,0.12); border-radius:12px;">
              <form method="POST" action="<?= BASE_URL ?>/actions/admin_issue_upload_proof.php" enctype="multipart/form-data" class="row g-3">
                <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

                <div class="col-12 col-md-5">
                  <label class="form-label">Proof Type</label>
                  <select name="photo_type" class="form-select" required>
                    <option value="PROOF_BEFORE">PROOF_BEFORE</option>
                    <option value="PROOF_AFTER">PROOF_AFTER</option>
                  </select>
                </div>

                <div class="col-12 col-md-7">
                  <label class="form-label">Upload Image (JPG/PNG/WebP, max 5MB)</label>
                  <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
                </div>

                <div class="col-12 d-flex justify-content-end">
                  <button class="btn btn-brand" type="submit">Upload</button>
                </div>
              </form>
            </div>
          </div>

          <div class="row g-4 mt-1">
            <div class="col-12 col-md-6">
              <div class="text-muted small mb-2">Before</div>
              <?php if (!empty($photos['PROOF_BEFORE'])): ?>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($photos['PROOF_BEFORE'] as $p): ?>
                    <a href="<?= h(BASE_URL . $p['file_path']) ?>" target="_blank">
                      <img src="<?= h(BASE_URL . $p['file_path']) ?>" alt="Proof before"
                           style="width:120px;height:90px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,0.15);">
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-muted">No proof before uploaded.</div>
              <?php endif; ?>
            </div>

            <div class="col-12 col-md-6">
              <div class="text-muted small mb-2">After</div>
              <?php if (!empty($photos['PROOF_AFTER'])): ?>
                <div class="d-flex flex-wrap gap-2">
                  <?php foreach ($photos['PROOF_AFTER'] as $p): ?>
                    <a href="<?= h(BASE_URL . $p['file_path']) ?>" target="_blank">
                      <img src="<?= h(BASE_URL . $p['file_path']) ?>" alt="Proof after"
                           style="width:120px;height:90px;object-fit:cover;border-radius:12px;border:1px solid rgba(255,255,255,0.15);">
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="text-muted">No proof after uploaded.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Comments -->
        <div class="card-dark p-4">
          <h5 class="fw-semibold mb-3">Comments</h5>

          <?php if (empty($comments)): ?>
            <div class="text-muted">No comments yet.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($comments as $c): ?>
                <div class="p-3" style="border:1px solid rgba(255,255,255,0.10); border-radius:12px;">
                  <div class="small text-muted mb-1">
                    <?= h($c['user_name'] ?? 'User') ?> (<?= h($c['user_role'] ?? '-') ?>) • <?= h($c['created_at'] ?? '') ?>
                  </div>
                  <div><?= nl2br(h($c['comment_text'] ?? '')) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

                        <!-- Citizen Ratings & Feedback -->
                <div class="card-dark p-4 mt-4">
                  <h5 class="fw-semibold mb-3">Citizen Ratings &amp; Feedback</h5>

                  <?php if (empty($feedbacks)): ?>
                    <div class="text-muted">No feedback submitted yet.</div>
                  <?php else: ?>
                    <div class="d-flex flex-column gap-3">
                      <?php foreach ($feedbacks as $fb): ?>
                        <div class="p-3" style="border:1px solid rgba(255,255,255,0.10); border-radius:12px;">

                          <div class="small text-muted mb-2">
                            <?= h($fb['citizen_name']) ?> &nbsp;·&nbsp; <?= h($fb['created_at']) ?>
                          </div>

                          <div class="row g-2 mb-2">
                            <div class="col-12 col-sm-4">
                              <div class="small text-muted">Overall Issue Fixation</div>
                              <div style="color:#ffd36b; font-size:1.1rem;">
                                <?= stars((int)$fb['overall_rating']) ?>
                                <span class="small text-muted">(<?= (int)$fb['overall_rating'] ?>/5)</span>
                              </div>
                            </div>
                            <div class="col-12 col-sm-4">
                              <div class="small text-muted">Field Worker</div>
                              <div style="color:#ffd36b; font-size:1.1rem;">
                                <?= stars((int)$fb['worker_rating']) ?>
                                <span class="small text-muted">(<?= (int)$fb['worker_rating'] ?>/5)</span>
                              </div>
                            </div>
                            <div class="col-12 col-sm-4">
                              <div class="small text-muted">Local Authority</div>
                              <div style="color:#ffd36b; font-size:1.1rem;">
                                <?= stars((int)$fb['authority_rating']) ?>
                                <span class="small text-muted">(<?= (int)$fb['authority_rating'] ?>/5)</span>
                              </div>
                            </div>
                          </div>

                          <?php if (!empty($fb['feedback_text'])): ?>
                            <div class="small text-muted mt-2">Feedback:</div>
                            <div><?= nl2br(h($fb['feedback_text'])) ?></div>
                          <?php endif; ?>

                        </div>
                      <?php endforeach; ?>
                    </div>

                    <!-- Average summary -->
                    <?php
                      $avgOverall   = round(array_sum(array_column($feedbacks, 'overall_rating'))   / count($feedbacks), 1);
                      $avgWorker    = round(array_sum(array_column($feedbacks, 'worker_rating'))    / count($feedbacks), 1);
                      $avgAuthority = round(array_sum(array_column($feedbacks, 'authority_rating')) / count($feedbacks), 1);
                    ?>
                    <div class="mt-3 p-3" style="background:rgba(255,255,255,0.04); border-radius:12px;">
                      <div class="small fw-semibold mb-2">Average Ratings (<?= count($feedbacks) ?> response<?= count($feedbacks) > 1 ? 's' : '' ?>)</div>
                      <div class="d-flex gap-4 flex-wrap small">
                        <span>Overall: <strong style="color:#ffd36b;"><?= $avgOverall ?>/5</strong></span>
                        <span>Field Worker: <strong style="color:#ffd36b;"><?= $avgWorker ?>/5</strong></span>
                        <span>Local Authority: <strong style="color:#ffd36b;"><?= $avgAuthority ?>/5</strong></span>
                      </div>
                    </div>

                  <?php endif; ?>
                </div>

      </div>

      <!-- RIGHT: Status update + timeline -->
      <div class="col-12 col-lg-4">

        <!-- Update Status -->
        <div class="card-dark p-4 mb-4">
          <h5 class="fw-semibold mb-3">Update Status</h5>

          <form method="POST" action="<?= BASE_URL ?>/actions/admin_issue_update_status.php" class="d-flex flex-column gap-3">
            <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

            <div>
              <label class="form-label">New Status</label>
              <select name="status" class="form-select" required>
                <?php foreach ($allowedStatuses as $s): ?>
                  <option value="<?= h($s) ?>" <?= ($issue['status'] === $s) ? 'selected' : '' ?>>
                    <?= h($s) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label class="form-label">Note (optional)</label>
              <textarea name="note" class="form-control" rows="3" placeholder="Reason or update..."></textarea>
            </div>

            <button class="btn btn-brand w-100" type="submit">Update Status</button>
          </form>
        </div>

        <!-- Timeline -->
        <div class="card-dark p-4">
          <h5 class="fw-semibold mb-3">Timeline</h5>

          <?php if (empty($timeline)): ?>
            <div class="text-muted">No status history yet.</div>
          <?php else: ?>
            <div class="d-flex flex-column gap-3">
              <?php foreach ($timeline as $t): ?>
                <div class="p-3" style="border:1px solid rgba(255,255,255,0.10); border-radius:12px;">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <span class="badge bg-secondary"><?= h((string)$t['status']) ?></span>
                    <span class="small text-muted"><?= h((string)$t['created_at']) ?></span>
                  </div>
                  <div class="small text-muted mt-2">
                    By: <?= h((string)($t['by_name'] ?? 'System')) ?> (<?= h((string)($t['by_role'] ?? '-')) ?>)
                  </div>
                  <?php if (!empty($t['note'])): ?>
                    <div class="mt-2"><?= nl2br(h((string)$t['note'])) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <div class="mt-4">
      <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_issues.php">← Back to Manage Issues</a>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>