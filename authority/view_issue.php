<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/notify.php';

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
function isAjaxRequest(): bool {
  $hdr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  return $hdr === 'xmlhttprequest';
}
function jsonOut(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$userId]);
$myAreaId = (int)($st->fetchColumn() ?: 0);

if ($myAreaId <= 0) {
  http_response_code(403);
  echo "<div style='padding:20px;font-family:Arial'>403 Forbidden (Authority has no assigned area)</div>";
  exit;
}

$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) $issueId = (int)($_GET['id'] ?? 0);

if ($issueId <= 0) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger'>Invalid issue.</div></div>";
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'assign_worker') {

  $postIssueId = (int)($_POST['issue_id'] ?? 0);
  $workerId    = (int)($_POST['field_worker_id'] ?? 0);
  $ajax        = isAjaxRequest();

  if ($postIssueId <= 0 || $workerId <= 0) {
    $msg = 'Invalid assignment request.';
    if ($ajax) jsonOut(['ok' => false, 'type' => 'danger', 'msg' => $msg], 422);

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $msg];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }

  $st = $pdo->prepare("SELECT issue_id FROM issues WHERE issue_id=? AND area_id=? LIMIT 1");
  $st->execute([$postIssueId, $myAreaId]);
  $okIssue = $st->fetchColumn();
  if (!$okIssue) {
    $msg = '403 Forbidden (Issue not in your area)';
    if ($ajax) jsonOut(['ok' => false, 'type' => 'danger', 'msg' => $msg], 403);

    http_response_code(403);
    echo $msg;
    exit;
  }

  $st = $pdo->prepare("
    SELECT user_id
    FROM users
    WHERE user_id = ?
      AND area_id = ?
      AND LOWER(status) = 'active'
      AND TRIM(LOWER(role)) IN ('field worker','worker','field_worker','fieldworker')
    LIMIT 1
  ");
  $st->execute([$workerId, $myAreaId]);
  $okWorker = $st->fetchColumn();

  if (!$okWorker) {
    $msg = 'Selected worker is not active or not in your area.';
    if ($ajax) jsonOut(['ok' => false, 'type' => 'danger', 'msg' => $msg], 422);

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $msg];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }

  try {
    $pdo->beginTransaction();

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
      $msg = 'This issue already has an active assignment.';
      if ($ajax) jsonOut(['ok' => false, 'type' => 'warning', 'msg' => $msg], 409);

      $_SESSION['flash'] = ['type' => 'warning', 'msg' => $msg];
      header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
      exit;
    }

    $st = $pdo->prepare("
      SELECT i.reporter_user_id, u.name AS worker_name, u.email AS worker_email
      FROM issues i
      JOIN users u ON u.user_id = ?
      WHERE i.issue_id = ?
      LIMIT 1
    ");
    $st->execute([$workerId, $postIssueId]);
    $tmp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $reporterId  = (int)($tmp['reporter_user_id'] ?? 0);
    $workerName  = (string)($tmp['worker_name'] ?? 'Field Worker');
    $workerEmail = (string)($tmp['worker_email'] ?? '');

    $ins = $pdo->prepare("
      INSERT INTO assignments (issue_id, field_worker_id, assigned_by_authority_id, assignment_status, assigned_at)
      VALUES (?, ?, ?, 'ASSIGNED', NOW())
    ");
    $ins->execute([$postIssueId, $workerId, $userId]);

    $upd = $pdo->prepare("
      UPDATE issues
      SET status = CASE WHEN status = 'PENDING' THEN 'ASSIGNED' ELSE status END
      WHERE issue_id = ? AND area_id = ?
      LIMIT 1
    ");
    $upd->execute([$postIssueId, $myAreaId]);

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

    $msg = 'Worker assigned successfully.';
    if ($ajax) {
      jsonOut([
        'ok' => true,
        'type' => 'success',
        'msg' => $msg,
        'worker' => [
          'name' => $workerName,
          'email' => $workerEmail,
          'status' => 'ASSIGNED'
        ]
      ]);
    }

    $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $msg = 'Failed to assign worker: ' . $e->getMessage();

    if ($ajax) jsonOut(['ok' => false, 'type' => 'danger', 'msg' => $msg], 500);

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $msg];
    header("Location: " . BASE_URL . "/authority/view_issue.php?issue_id=" . $issueId);
    exit;
  }
}

$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.description, i.status, i.created_at, i.area_id,
    u.name AS reporter_name,
    u.email AS reporter_email,
    u.address AS unit_number,
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

$st = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE issue_id=?");
$st->execute([$issueId]);
$upvotes = (int)$st->fetchColumn();

/* REPORT photos */
$st = $pdo->prepare("
  SELECT file_path
  FROM issue_photos
  WHERE issue_id=? AND photo_type='REPORT'
  ORDER BY photo_id DESC
");
$st->execute([$issueId]);
$reportPhotos = $st->fetchAll(PDO::FETCH_COLUMN);

$st = $pdo->prepare("
  SELECT file_path
  FROM issue_photos
  WHERE issue_id=? AND photo_type='PROOF'
  ORDER BY photo_id DESC
");
$st->execute([$issueId]);
$proofPhotos = $st->fetchAll(PDO::FETCH_COLUMN);

$st = $pdo->prepare("
  SELECT c.comment_text, c.created_at, u.name
  FROM comments c
  JOIN users u ON u.user_id = c.user_id
  WHERE c.issue_id=?
  ORDER BY c.created_at DESC, c.comment_id DESC
");
$st->execute([$issueId]);
$comments = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("
  SELECT user_id, name, email
  FROM users
  WHERE area_id = ?
    AND TRIM(LOWER(role)) IN ('field worker','worker','field_worker','fieldworker')
    AND LOWER(status) = 'active'
  ORDER BY name
");
$st->execute([$myAreaId]);
$fieldWorkers = $st->fetchAll(PDO::FETCH_ASSOC);

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

$allowedStatuses = ['PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED'];
$currentStatus = (string)$issue['status'];

/* Timeline */
$st = $pdo->prepare("
  SELECT status, note, created_at
  FROM issue_status_history
  WHERE issue_id = ?
  ORDER BY created_at ASC
");
$st->execute([$issueId]);
$timeline = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'View Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-4 app-container">

  <div id="flashArea">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>">
        <?= h($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card-dark p-3 p-md-4">

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
      <div>
        <h3 class="fw-bold mb-2">
          Issue ID: #<?= (int)$issue['issue_id'] ?> - &lt;<?= h($issue['title']) ?>&gt;
        </h3>

        <div class="meta-row">
          <div class="meta"><span class="fw-semibold text-light">Reported by:</span> <?= h($issue['reporter_name']) ?></div>
          <div class="meta"><span class="fw-semibold text-light">Category:</span> <?= h($issue['category_name'] ?? '—') ?></div>
          <div class="meta"><span class="fw-semibold text-light">Branch:</span> <?= h($issue['area_name'] ?? '—') ?></div>
          <div class="meta"><span class="fw-semibold text-light">Unit Number:</span> <?= h($issue['unit_number'] ?? '—') ?></div>
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

        <div id="currentAssignBlock" class="text-muted small mb-2" style="<?= $activeAssign ? '' : 'display:none;' ?>">
          Currently assigned to:
          <span class="text-light fw-semibold" id="assignedWorkerName"><?= h($activeAssign['worker_name'] ?? '') ?></span>
          <span id="assignedWorkerEmail"><?= !empty($activeAssign['worker_email']) ? ' (' . h($activeAssign['worker_email']) . ')' : '' ?></span>
          (<span id="assignedStatus"><?= h($activeAssign['assignment_status'] ?? 'ASSIGNED') ?></span>)
        </div>

        <form id="assignForm" method="POST" class="d-flex flex-wrap gap-2 align-items-center">
          <input type="hidden" name="action" value="assign_worker">
          <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

          <select id="workerSelect" name="field_worker_id" class="form-select" style="max-width:360px;"
                  <?= $activeAssign ? 'disabled' : '' ?> required>
            <option value="">Select worker</option>
            <?php foreach ($fieldWorkers as $w): ?>
              <option value="<?= (int)$w['user_id'] ?>">
                <?= h($w['name'] . ' (' . $w['email'] . ')') ?>
              </option>
            <?php endforeach; ?>
          </select>

          <button id="assignBtn" class="btn btn-brand btn-sm" type="submit" <?= $activeAssign ? 'disabled' : '' ?>>
            Assign
          </button>

          <span id="assignSpinner" class="text-muted small" style="display:none;">Assigning…</span>
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
              <div class="photo text-muted small ph-empty">
                <i class="bi bi-card-image"></i>
              </div>
            <?php endfor; ?>
          <?php else: ?>
            <?php foreach ($slice as $p): ?>
              <div class="photo"><img src="<?= h(fileUrl((string)$p)) ?>" alt="Issue photo"></div>
            <?php endforeach; ?>
            <?php for ($i=$count; $i<3; $i++): ?>
              <div class="photo text-muted small ph-empty">
                <i class="bi bi-card-image"></i>
              </div>
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
          <?php
            $pslice = array_slice($proofPhotos, 0, 3);
            $pcount = count($pslice);
          ?>

          <?php if ($pcount === 0): ?>
            <?php for ($i=0; $i<3; $i++): ?>
              <div class="photo text-muted small ph-empty">
                <i class="bi bi-card-image"></i>
              </div>
            <?php endfor; ?>
          <?php else: ?>
            <?php foreach ($pslice as $p): ?>
              <div class="photo"><img src="<?= h(fileUrl((string)$p)) ?>" alt="Proof photo"></div>
            <?php endforeach; ?>
            <?php for ($i=$pcount; $i<3; $i++): ?>
              <div class="photo text-muted small ph-empty">
                <i class="bi bi-card-image"></i>
              </div>
            <?php endfor; ?>
          <?php endif; ?>
        </div>

        <?php if (empty($proofPhotos)): ?>
          <div class="text-muted small mt-2">No proof photos uploaded yet.</div>
        <?php endif; ?>
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

    <div class="card-dark p-3 mb-3">
      <div class="fw-semibold mb-2">Status Timeline</div>

      <?php if (empty($timeline)): ?>
        <div class="text-muted small">No history yet.</div>
      <?php else: ?>
        <ul class="mb-0">
          <?php foreach ($timeline as $t): ?>
            <li class="small mb-2">
              <strong><?= h($t['status'] ?? '') ?></strong>
              <span class="text-muted"> — <?= h($t['created_at'] ?? '') ?></span>
              <?php if (!empty($t['note'])): ?>
                <div class="text-muted"><?= h($t['note']) ?></div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <div class="fw-semibold mb-2">Comments</div>

    <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="mb-3">
      <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
      <input type="hidden" name="return_to" value="authority/view_issue.php?issue_id=<?= (int)$issueId ?>">
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

<script>
(function(){
  const form = document.getElementById('assignForm');
  if (!form) return;

  const btn = document.getElementById('assignBtn');
  const sel = document.getElementById('workerSelect');
  const spn = document.getElementById('assignSpinner');

  const flashArea = document.getElementById('flashArea');

  const currentBlock = document.getElementById('currentAssignBlock');
  const wName = document.getElementById('assignedWorkerName');
  const wEmail = document.getElementById('assignedWorkerEmail');
  const wStatus = document.getElementById('assignedStatus');

  function esc(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  function showFlash(type, msg){
    if (!flashArea) return;
    flashArea.innerHTML = `
      <div class="alert alert-${esc(type)}">
        ${esc(msg)}
      </div>
    `;
    flashArea.scrollIntoView({behavior:'smooth', block:'start'});
  }

  form.addEventListener('submit', async function(e){
    e.preventDefault();

    if (!sel.value) {
      showFlash('warning', 'Please select a worker.');
      return;
    }
    if (!confirm('Assign this field worker to the issue?')) return;

    btn.disabled = true;
    spn.style.display = 'inline';
    sel.disabled = true;

    try {
      const fd = new FormData(form);
      fd.set('field_worker_id', sel.value);

      const res = await fetch(window.location.href, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
      });

      let data = null;
      try { data = await res.json(); } catch (e) {}

      if (!data || !data.ok) {
        const msg = (data && data.msg) ? data.msg : 'Failed to assign (server error).';
        showFlash((data && data.type) ? data.type : 'danger', msg);

        btn.disabled = false;
        sel.disabled = false;
        spn.style.display = 'none';
        return;
      }

      showFlash(data.type || 'success', data.msg || 'Assigned.');

      if (data.worker) {
        currentBlock.style.display = '';
        wName.textContent = data.worker.name || 'Field Worker';
        wEmail.textContent = data.worker.email ? (' (' + data.worker.email + ')') : '';
        wStatus.textContent = data.worker.status || 'ASSIGNED';
      }

      btn.disabled = true;
      sel.disabled = true;
      spn.style.display = 'none';

    } catch(err){
      showFlash('danger', 'Unexpected error while assigning.');
      btn.disabled = false;
      sel.disabled = false;
      spn.style.display = 'none';
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>