<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['authority']); 

$page_title = 'View Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header('Location: ' . BASE_URL . '/auth/login.php');
  exit;
}


$issueId = (int)($_GET['issue_id'] ?? 0);
if ($issueId <= 0) $issueId = (int)($_GET['id'] ?? 0);

if ($issueId <= 0) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger'>Invalid issue.</div></div>";
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}

$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$userId]);
$myAreaId = (int)($st->fetchColumn() ?: 0);

$sql = "
SELECT
  i.issue_id, i.title, i.description, i.status, i.created_at, i.area_id, i.reporter_user_id,
  u.name AS reporter_name,
  u.email AS reporter_email,
  a.area_name,
  c.category_name
FROM issues i
JOIN users u ON u.user_id = i.reporter_user_id
JOIN areas a ON a.area_id = i.area_id
LEFT JOIN issue_categories c ON c.category_id = i.category_id
WHERE i.issue_id = ?
";
$params = [$issueId];

if ($myAreaId > 0) {
  $sql .= " AND i.area_id = ? ";
  $params[] = $myAreaId;
}

$st = $pdo->prepare($sql);
$st->execute($params);
$issue = $st->fetch(PDO::FETCH_ASSOC);

if (!$issue) {
  http_response_code(404);
  echo "<div class='container py-4'><div class='alert alert-danger'>Issue not found (or not in your area).</div></div>";
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}

$st = $pdo->prepare("SELECT COUNT(*) FROM votes WHERE issue_id=?");
$st->execute([$issueId]);
$upvotes = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT photo_id, photo_type, file_path
  FROM issue_photos
  WHERE issue_id=? AND photo_type='REPORT'
  ORDER BY photo_id DESC
");
$st->execute([$issueId]);
$reportPhotos = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("
  SELECT c.comment_id, c.comment_text, c.created_at, u.name
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
  WHERE role='field worker' AND status='active'
  ORDER BY name
");
$st->execute();
$fieldWorkers = $st->fetchAll(PDO::FETCH_ASSOC);


$st = $pdo->prepare("
  SELECT a.assignment_status, u.name AS worker_name
  FROM assignments a
  JOIN users u ON u.user_id = a.field_worker_id
  WHERE a.issue_id = ?
    AND a.assignment_status IN ('ASSIGNED','ACCEPTED')
  ORDER BY a.assigned_at DESC
  LIMIT 1
");
$st->execute([$issueId]);
$activeAssign = $st->fetch(PDO::FETCH_ASSOC);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fileUrl(string $path): string {
  $path = trim($path);
  if ($path === '') return '';
  if (str_starts_with($path, 'http')) return $path;
  if (str_starts_with($path, '/')) return BASE_URL . $path;
  return BASE_URL . '/' . $path;
}


$allowedStatuses = ['PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED'];
$currentStatus = (string)$issue['status'];
?>

<style>
  .meta-row{
    display:flex;
    flex-wrap:wrap;
    gap:18px;
    align-items:center;
  }
  .meta-row .meta{ color: var(--muted-400); font-size:0.95rem; }
  .upvote-box{
    display:flex; align-items:center; gap:10px;
    justify-content:flex-end; min-width:140px;
  }
  .tri{
    width:0; height:0;
    border-left:14px solid transparent;
    border-right:14px solid transparent;
    border-bottom:22px solid rgba(241,246,246,0.6);
  }
  .photo-grid{
    display:grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap:12px;
  }
  .photo{
    width:100%; height:110px;
    border-radius:12px;
    border:1px solid var(--border);
    background: rgba(0,0,0,0.12);
    overflow:hidden;
    display:flex; align-items:center; justify-content:center;
  }
  .photo img{ width:100%; height:100%; object-fit:cover; display:block; }
  .desc-box{
    border:1px solid var(--border);
    border-radius:14px;
    padding:12px;
    background: rgba(0,0,0,0.08);
    min-height:110px;
  }
  .comment-box{ min-height:160px; resize: vertical; }

  @media (max-width:768px){
    .photo-grid{ grid-template-columns: repeat(2, minmax(0,1fr)); }
    .upvote-box{ justify-content:flex-start; }
  }
</style>

<div class="container py-4 app-container">

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
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
        <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between">

          <div style="min-width:260px;">
            <label class="form-label mb-1">Assign Field Worker</label>

            <?php if ($activeAssign): ?>
              <div class="text-muted small mb-2">
                Currently assigned to: <span class="text-light fw-semibold"><?= h($activeAssign['worker_name']) ?></span>
                (<?= h($activeAssign['assignment_status']) ?>)
              </div>
            <?php endif; ?>

            <form id="assignWorkerForm" method="POST" action="<?= BASE_URL ?>/actions/authority_assign_worker.php">
              <input type="hidden" name="issue_id" value="<?= (int)$issueId ?>">

              <select name="field_worker_id" class="form-select" style="max-width:320px;" <?= $activeAssign ? 'disabled' : '' ?> required>
                <option value="">Select worker</option>
                <?php foreach ($fieldWorkers as $w): ?>
                  <option value="<?= (int)$w['user_id'] ?>">
                    <?= h($w['name'] . ' (' . $w['email'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="d-flex justify-content-end mt-2">
                <button type="submit" class="btn btn-brand btn-sm" <?= $activeAssign ? 'disabled' : '' ?>>
                  Assign
                </button>
              </div>
            </form>

          </div>

        </div>
      </div>
    </div>

    <hr style="border-color: var(--border);" class="my-3">

    <div class="row g-3">
      <div class="col-12 col-lg-7">
        <div class="fw-semibold mb-2">Issue Photos &amp; Description:</div>

        <div class="photo-grid mb-3">
          <?php if (empty($reportPhotos)): ?>
            <?php for ($i=0; $i<3; $i++): ?>
              <div class="photo text-muted small">No Photo</div>
            <?php endfor; ?>
          <?php else: ?>
            <?php foreach (array_slice($reportPhotos, 0, 3) as $p): ?>
              <div class="photo">
                <img src="<?= h(fileUrl((string)$p['file_path'])) ?>" alt="Issue photo">
              </div>
            <?php endforeach; ?>
            <?php if (count($reportPhotos) < 3): ?>
              <?php for ($i=count($reportPhotos); $i<3; $i++): ?>
                <div class="photo text-muted small">No Photo</div>
              <?php endfor; ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>

        <div class="desc-box">
          <?= nl2br(h($issue['description'])) ?>
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
                <div class="fw-semibold"><?= h($c['name']) ?></div>
                <div class="text-muted small"><?= h($c['created_at']) ?></div>
              </div>
              <div class="text-muted"><?= nl2br(h($c['comment_text'])) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>