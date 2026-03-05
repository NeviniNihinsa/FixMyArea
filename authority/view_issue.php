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
function roleLabel(string $role): string {
  return match(strtolower(trim($role))) {
    'citizen'                              => 'Tenant',
    'worker', 'field worker',
    'field_worker', 'fieldworker'          => 'Maintenance Technician',
    'authority', 'local authority'         => 'Property Manager',
    'admin'                                => 'Admin',
    default                                => ucfirst($role),
  };
}
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

    // Cancel any existing active assignments so authority can reassign freely
    $pdo->prepare("
      UPDATE assignments
      SET assignment_status = 'CANCELLED'
      WHERE issue_id = ?
        AND assignment_status IN ('ASSIGNED','ACCEPTED')
    ")->execute([$postIssueId]);

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
  SELECT
    h.status,
    h.note,
    h.created_at,
    u.name  AS changed_by_name,
    u.role  AS changed_by_role
  FROM issue_status_history h
  LEFT JOIN users u ON u.user_id = h.changed_by_user_id
  WHERE h.issue_id = ?
  ORDER BY h.created_at ASC
");
$st->execute([$issueId]);
$timeline = $st->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'View Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-4 app-container">

  <div class="mb-3">
    <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/authority/area_issues.php">← Back to Issues</a>
  </div>

  <div id="flashArea">
    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>">
        <?= h($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Header card: title + meta + upvotes ── -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
      <div>
        <h3 class="fw-bold mb-2">
          Issue ID: #<?= (int)$issue['issue_id'] ?> &mdash; <?= h($issue['title']) ?>
        </h3>
        <div class="text-muted small">
          Reported by: <span class="fw-semibold text-body"><?= h($issue['reporter_name']) ?></span>
          &nbsp;|&nbsp; Category: <span class="fw-semibold text-body"><?= h($issue['category_name'] ?? '—') ?></span>
          &nbsp;|&nbsp; Branch: <span class="fw-semibold text-body"><?= h($issue['area_name'] ?? '—') ?></span>
          &nbsp;|&nbsp; Unit: <span class="fw-semibold text-body"><?= h($issue['unit_number'] ?? '—') ?></span>
          &nbsp;|&nbsp; Status: <span class="badge bg-secondary"><?= h($currentStatus) ?></span>
        </div>
      </div>
      <div class="text-end flex-shrink-0">
        <div class="text-muted small">Upvotes</div>
        <div class="fs-4 fw-bold"><?= (int)$upvotes ?></div>
      </div>
    </div>

    <!-- Update Status -->
    <div class="mt-3">
      <form class="d-flex flex-wrap gap-2 align-items-center" method="POST" action="<?= BASE_URL ?>/actions/authority_update_status.php">
        <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
        <select name="status" class="form-select" style="max-width:200px;" required>
          <?php foreach ($allowedStatuses as $s): ?>
            <option value="<?= h($s) ?>" <?= ($s === $currentStatus) ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-brand btn-sm" type="submit">Update Status</button>
      </form>
    </div>

    <!-- Assign Field Worker -->
    <div class="mt-3">

      <div class="text-muted small mb-2" id="currentAssignBlock">
        <?php if ($activeAssign): ?>
          Assigned to: <span class="fw-semibold text-body" id="assignedWorkerName"><?= h($activeAssign['worker_name'] ?? '') ?></span>
        <?php else: ?>
          <span id="assignedWorkerName">Unassigned</span>
        <?php endif; ?>
      </div>

      <form id="assignForm" method="POST" action="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$issueId ?>"
            class="d-flex flex-wrap gap-2 align-items-center">
        <input type="hidden" name="action" value="assign_worker">
        <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

        <select id="workerSelect" name="field_worker_id" class="form-select" style="max-width:360px;" required>
          <option value="">Select worker…</option>
          <?php foreach ($fieldWorkers as $w): ?>
            <option value="<?= (int)$w['user_id'] ?>">
              <?= h($w['name'] . ' (' . $w['email'] . ')') ?>
            </option>
          <?php endforeach; ?>
        </select>

        <button id="assignBtn" class="btn btn-brand btn-sm" type="submit">
          <?= $activeAssign ? 'Reassign' : 'Assign' ?>
        </button>
        <span id="assignSpinner" class="text-muted small" style="display:none;">Assigning…</span>
      </form>

      <?php if (empty($fieldWorkers)): ?>
        <div class="text-muted small mt-1">No active field workers found in this area.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Main two-column layout ── -->
  <div class="row g-4">

    <!-- LEFT col: Photos + Description + Comments -->
    <div class="col-12 col-lg-8">

      <!-- Issue Photos & Description -->
      <div class="card-dark p-4 mb-4">
        <h5 class="fw-semibold mb-3">Issue Photos &amp; Description</h5>

        <?php if (!empty($reportPhotos)): ?>
          <div class="d-flex flex-wrap gap-3 mb-3">
            <?php foreach ($reportPhotos as $p): ?>
              <a href="<?= h(fileUrl((string)$p)) ?>" target="_blank" rel="noreferrer" class="text-decoration-none">
                <img src="<?= h(fileUrl((string)$p)) ?>" alt="Report photo"
                     style="width:130px;height:100px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted small mb-3">No report photos uploaded.</div>
        <?php endif; ?>

        <div class="text-body" style="white-space:pre-wrap;"><?= nl2br(h($issue['description'] ?? '')) ?></div>
      </div>

      <!-- Proof of Fix -->
      <div class="card-dark p-4 mb-4">
        <h5 class="fw-semibold mb-3">Proof of Fix</h5>

        <?php if (!empty($proofPhotos)): ?>
          <div class="d-flex flex-wrap gap-3">
            <?php foreach ($proofPhotos as $p): ?>
              <a href="<?= h(fileUrl((string)$p)) ?>" target="_blank" rel="noreferrer" class="text-decoration-none">
                <img src="<?= h(fileUrl((string)$p)) ?>" alt="Proof photo"
                     style="width:130px;height:100px;object-fit:cover;border-radius:10px;border:1px solid var(--border);">
              </a>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-muted small">No proof photos uploaded yet.</div>
        <?php endif; ?>
      </div>

      <!-- Comments -->
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Comments</h5>

        <form method="POST" action="<?= BASE_URL ?>/actions/comment_add.php" class="mb-4">
          <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">
          <input type="hidden" name="return_to" value="authority/view_issue.php?issue_id=<?= (int)$issueId ?>">
          <textarea name="comment_text" class="form-control" rows="3"
                    placeholder="Write a comment…" required></textarea>
          <div class="d-flex justify-content-end mt-2">
            <button class="btn btn-brand btn-sm" type="submit">Add Comment</button>
          </div>
        </form>

        <?php if (empty($comments)): ?>
          <div class="text-muted small">No comments yet.</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($comments as $c): ?>
              <div class="p-3" style="border:1px solid var(--border);border-radius:12px;">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div class="fw-semibold"><?= h($c['name'] ?? '') ?></div>
                  <div class="text-muted small"><?= h($c['created_at'] ?? '') ?></div>
                </div>
                <div class="text-muted mt-1"><?= nl2br(h($c['comment_text'] ?? '')) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- RIGHT col: Timeline + Ratings -->
    <div class="col-12 col-lg-4">

      <!-- Timeline -->
      <div class="card-dark p-4 mb-4">
        <h5 class="fw-semibold mb-3">Timeline</h5>

        <?php if (empty($timeline)): ?>
          <div class="text-muted small">No history yet.</div>
        <?php else: ?>
          <div class="d-flex flex-column gap-3">
            <?php foreach ($timeline as $t): ?>
              <?php
                $byName = !empty($t['changed_by_name']) ? $t['changed_by_name'] : null;
                $byRole = !empty($t['changed_by_role']) ? roleLabel($t['changed_by_role']) : null;
                // Strip generic trailing "by <role>" phrases that will be replaced with the real name
                $noteText = trim((string)($t['note'] ?? ''));
                $noteText = preg_replace('/\s+by\s+(citizen|field worker|local authority|authority|worker|admin)\.?$/i', '', $noteText);
                $noteText = trim($noteText);
                if ($byName) {
                  $suffix = h($byName) . ($byRole ? ' <span class="text-muted">(' . h($byRole) . ')</span>' : '');
                  $displayNote = $noteText !== '' ? h($noteText) . ' by ' . $suffix : 'Updated by ' . $suffix;
                } else {
                  $displayNote = $noteText !== '' ? h($noteText) : null;
                }
              ?>
              <div class="p-3" style="border:1px solid var(--border);border-radius:12px;">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <span class="badge bg-secondary"><?= h($t['status'] ?? '') ?></span>
                  <span class="text-muted small"><?= h($t['created_at'] ?? '') ?></span>
                </div>
                <?php if ($displayNote): ?>
                  <div class="small mt-2"><?= $displayNote ?></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Service Ratings -->
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Service Ratings</h5>
        <div class="d-flex flex-column gap-3">
          <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">Overall Issue Fixation:</div>
            <div><?= stars($overall) ?></div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">Maintenance Technician:</div>
            <div><?= stars($worker) ?></div>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted">Property Manager:</div>
            <div><?= stars($authority) ?></div>
          </div>
        </div>
      </div>

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
    const isReassign = currentBlock && currentBlock.style.display !== 'none' && document.getElementById('assignedWorkerName')?.textContent?.trim();
    if (!confirm(isReassign ? 'Reassign this issue to the selected worker? The current assignment will be replaced.' : 'Assign this field worker to the issue?')) return;

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
        // Update "Assigned to: Name" line
        currentBlock.innerHTML = 'Assigned to: <span class="fw-semibold text-body" id="assignedWorkerName">' + esc(data.worker.name || 'Maintenance Technician') + '</span>';
      }

      // Keep enabled — authority can reassign at any time
      btn.disabled = false;
      sel.disabled = false;
      btn.textContent = 'Reassign';
      sel.value = '';
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

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>