<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Leaderboard - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);
if ($meId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

/* Get authority area */
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$areaId   = (int)($me['area_id'] ?? 0);
$areaName = (string)($me['area_name'] ?? 'Designated Area');

if ($areaId <= 0) {
  http_response_code(403);
  require_once __DIR__ . '/../includes/header.php';
  require_once __DIR__ . '/../includes/navbar.php';
  echo "<div class='container py-4 app-container'>
          <div class='alert alert-warning'>
            Your account is not assigned to an area yet.
          </div>
        </div>";
  require_once __DIR__ . '/../includes/footer_internal.php';
  exit;
}

/* ──────────────────────────────────────────────
   1) FIELD WORKERS — ranked by completed jobs + avg rating
   (Authority view = only their area)
────────────────────────────────────────────── */
$sqlWorkers = "
SELECT
  u.user_id,
  u.name,
  u.email,
  COUNT(DISTINCT a.assignment_id)        AS completed_jobs,
  ROUND(AVG(f.worker_rating), 1)         AS avg_rating,
  COUNT(DISTINCT f.feedback_id)          AS rated_count
FROM assignments a
JOIN issues i  ON i.issue_id  = a.issue_id
JOIN users  u  ON u.user_id   = a.field_worker_id
LEFT JOIN feedback_ratings f ON f.field_worker_id = u.user_id
WHERE i.area_id = ?
  AND UPPER(TRIM(i.status)) = 'COMPLETED'
GROUP BY u.user_id, u.name, u.email
ORDER BY completed_jobs DESC, avg_rating DESC
LIMIT 10
";
$st = $pdo->prepare($sqlWorkers);
$st->execute([$areaId]);
$workers = $st->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────
   2) AUTHORITIES — ranked by actions + avg authority_rating
────────────────────────────────────────────── */
$sqlAuthority = "
SELECT
  u.user_id,
  u.name,
  u.email,
  COUNT(DISTINCT h.history_id)           AS actions_count,
  ROUND(AVG(f.authority_rating), 1)      AS avg_rating,
  COUNT(DISTINCT f.feedback_id)          AS rated_count
FROM issue_status_history h
JOIN issues i ON i.issue_id = h.issue_id
JOIN users  u ON u.user_id  = h.changed_by_user_id
LEFT JOIN feedback_ratings f ON f.authority_user_id = u.user_id
WHERE i.area_id = ?
  AND TRIM(LOWER(u.role)) IN ('authority','local authority')
GROUP BY u.user_id, u.name, u.email
ORDER BY actions_count DESC, avg_rating DESC
LIMIT 10
";
$st = $pdo->prepare($sqlAuthority);
$st->execute([$areaId]);
$authorities = $st->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────
   3) CITIZENS — ranked by reports filed + votes received on their issues
────────────────────────────────────────────── */
$sqlCitizens = "
SELECT
  u.user_id,
  u.name,
  u.email,
  COUNT(DISTINCT i.issue_id)             AS reports_count,
  COALESCE(SUM(v.value), 0)              AS votes_received,
  ROUND(AVG(f.overall_rating), 1)        AS avg_rating
FROM issues i
JOIN users u ON u.user_id = i.reporter_user_id
LEFT JOIN votes v ON v.issue_id = i.issue_id
LEFT JOIN feedback_ratings f ON f.citizen_user_id = u.user_id
WHERE i.area_id = ?
  AND TRIM(LOWER(u.role)) = 'citizen'
GROUP BY u.user_id, u.name, u.email
ORDER BY reports_count DESC, votes_received DESC
LIMIT 10
";
$st = $pdo->prepare($sqlCitizens);
$st->execute([$areaId]);
$citizens = $st->fetchAll(PDO::FETCH_ASSOC);

/* ──────────────────────────────────────────────
   HELPERS 
────────────────────────────────────────────── */
function h(?string $s): string {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function starRating(?float $rating): string {
  if ($rating === null || $rating == 0) return '<span class="text-muted">—</span>';
  $full  = (int)floor($rating);
  $half  = ($rating - $full) >= 0.4 ? 1 : 0;
  $empty = 5 - $full - $half;
  $html  = '<span class="text-warning" title="' . number_format($rating, 1) . '/5">';
  for ($i = 0; $i < $full;  $i++) $html .= '★';
  if ($half)                        $html .= '⯨';
  for ($i = 0; $i < $empty; $i++) $html .= '☆';
  $html .= '</span> <small class="text-muted">' . number_format($rating, 1) . '</small>';
  return $html;
}

function medalBadge(int $rank): string {
  return match ($rank) {
    1 => '<span class="fs-5" title="Gold"><i class="bi bi-1-circle-fill"></i></span>',
    2 => '<span class="fs-5" title="Silver"><i class="bi bi-2-circle-fill"></i></span>',
    3 => '<span class="fs-5" title="Bronze"><i class="bi bi-3-circle-fill"></i></span>',
    default => '<span class="text-muted fw-bold">#' . $rank . '</span>',
  };
}

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="container py-4 app-container">

  <!-- Header -->
  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3 mb-4">
    <div>
      <h2 class="fw-bold mb-0">Leaderboard</h2>
      <div class="text-muted small mt-1">
        Showing: <span class="fw-semibold"><?= h($areaName) ?></span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════
       SECTION 1: Maintenance Technicians (Workers)
  ════════════════════════════════════════════ -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="fs-4"><i class="bi bi-gear-wide-connected"></i></span>
      <h5 class="fw-bold mb-0">Top Maintenance Technicians</h5>
      <span class="text-muted small ms-1">: ranked by completed jobs</span>
    </div>

    <?php if (empty($workers)): ?>
      <div class="text-muted small">No completed assignments for this area.</div>
    <?php else: ?>
      <?php $top3w = array_slice($workers, 0, 3); ?>
      <div class="row g-3 mb-4">
        <?php foreach ($top3w as $rank => $w): ?>
          <div class="col-12 col-md-4">
            <div class="card-dark p-3 text-center" style="border:1px solid rgba(241,246,246,0.13); border-radius:18px;">
              <div style="font-size:2.8rem; line-height:1; margin-bottom:6px;"><?= medalBadge($rank + 1) ?></div>
              <div class="lb-avatar mx-auto mb-2" style="width:64px;height:64px;border-radius:50%;border:2px solid rgba(255,145,76,0.35);display:flex;align-items:center;justify-content:center;background:rgba(255,173,82,0.12);">
                <i class="bi bi-person-fill" style="font-size:32px;color:var(--accent-600);"></i>
              </div>
              <div class="fw-bold"><?= h($w['name']) ?></div>
              <div class="text-muted small"><?= h($w['email']) ?></div>
              <div class="mt-2">
                <span class="badge bg-success bg-opacity-75"><?= (int)$w['completed_jobs'] ?> jobs</span>
              </div>
              <div class="mt-1 small"><?= starRating($w['avg_rating'] !== null ? (float)$w['avg_rating'] : null) ?></div>
              <div class="text-muted" style="font-size:0.75rem;"><?= (int)$w['rated_count'] ?> rating<?= (int)$w['rated_count'] === 1 ? '' : 's' ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($workers) > 3): ?>
        <div class="table-responsive">
          <table class="table table-dark-custom align-middle mb-0">
            <thead>
              <tr>
                <th style="width:60px;">Rank</th>
                <th>Name</th>
                <th>Email</th>
                <th style="width:140px;">Completed Jobs</th>
                <th style="width:160px;">Avg Rating</th>
                <th style="width:100px;">Ratings</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($workers as $rank => $w): ?>
                <tr>
                  <td><?= medalBadge($rank + 1) ?></td>
                  <td class="fw-semibold"><?= h($w['name']) ?></td>
                  <td class="text-muted small"><?= h($w['email']) ?></td>
                  <td><span class="badge bg-success bg-opacity-75"><?= (int)$w['completed_jobs'] ?></span></td>
                  <td><?= starRating($w['avg_rating'] !== null ? (float)$w['avg_rating'] : null) ?></td>
                  <td class="text-muted small"><?= (int)$w['rated_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════
       SECTION 2: LOCAL AUTHORITIES
  ════════════════════════════════════════════ -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="fs-4"><i class="bi bi-building-fill"></i></span>
      <h5 class="fw-bold mb-0">Top Property Managers</h5>
      <span class="text-muted small ms-1">: ranked by issue actions taken</span>
    </div>

    <?php if (empty($authorities)): ?>
      <div class="text-muted small">No authority activity for this area.</div>
    <?php else: ?>
      <?php $top3a = array_slice($authorities, 0, 3); ?>
      <div class="row g-3 mb-4">
        <?php foreach ($top3a as $rank => $auth): ?>
          <div class="col-12 col-md-4">
            <div class="card-dark p-3 text-center" style="border:1px solid rgba(241,246,246,0.13); border-radius:18px;">
              <div style="font-size:2.8rem; line-height:1; margin-bottom:6px;"><?= medalBadge($rank + 1) ?></div>
              <div class="lb-avatar mx-auto mb-2" style="width:64px;height:64px;border-radius:50%;border:2px solid rgba(255,145,76,0.35);display:flex;align-items:center;justify-content:center;background:rgba(255,173,82,0.12);">
                <i class="bi bi-building" style="font-size:28px;color:var(--accent-600);"></i>
              </div>
              <div class="fw-bold"><?= h($auth['name']) ?></div>
              <div class="text-muted small"><?= h($auth['email']) ?></div>
              <div class="mt-2">
                <span class="badge bg-info text-dark bg-opacity-75"><?= (int)$auth['actions_count'] ?> actions</span>
              </div>
              <div class="mt-1 small"><?= starRating($auth['avg_rating'] !== null ? (float)$auth['avg_rating'] : null) ?></div>
              <div class="text-muted" style="font-size:0.75rem;"><?= (int)$auth['rated_count'] ?> rating<?= (int)$auth['rated_count'] === 1 ? '' : 's' ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($authorities) > 3): ?>
        <div class="table-responsive">
          <table class="table table-dark-custom align-middle mb-0">
            <thead>
              <tr>
                <th style="width:60px;">Rank</th>
                <th>Name</th>
                <th>Email</th>
                <th style="width:140px;">Actions Taken</th>
                <th style="width:160px;">Avg Rating</th>
                <th style="width:100px;">Ratings</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($authorities as $rank => $auth): ?>
                <tr>
                  <td><?= medalBadge($rank + 1) ?></td>
                  <td class="fw-semibold"><?= h($auth['name']) ?></td>
                  <td class="text-muted small"><?= h($auth['email']) ?></td>
                  <td><span class="badge bg-info text-dark bg-opacity-75"><?= (int)$auth['actions_count'] ?></span></td>
                  <td><?= starRating($auth['avg_rating'] !== null ? (float)$auth['avg_rating'] : null) ?></td>
                  <td class="text-muted small"><?= (int)$auth['rated_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ═══════════════════════════════════════════
       SECTION 3: CITIZENS
  ════════════════════════════════════════════ -->
  <div class="card-dark p-3 p-md-4 mb-4">
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="fs-4"><i class="bi bi-person-fill"></i></span>
      <h5 class="fw-bold mb-0">Most Responsible Tenants</h5>
      <span class="text-muted small ms-1">: ranked by reports filed &amp; community votes received</span>
    </div>

    <?php if (empty($citizens)): ?>
      <div class="text-muted small">No citizen reports for this area.</div>
    <?php else: ?>
      <?php $top3c = array_slice($citizens, 0, 3); ?>
      <div class="row g-3 mb-4">
        <?php foreach ($top3c as $rank => $cit): ?>
          <div class="col-12 col-md-4">
            <div class="card-dark p-3 text-center" style="border:1px solid rgba(241,246,246,0.13); border-radius:18px;">
              <div style="font-size:2.8rem; line-height:1; margin-bottom:6px;"><?= medalBadge($rank + 1) ?></div>
              <div class="lb-avatar mx-auto mb-2" style="width:64px;height:64px;border-radius:50%;border:2px solid rgba(255,145,76,0.35);display:flex;align-items:center;justify-content:center;background:rgba(255,173,82,0.12);">
                <i class="bi bi-person-circle" style="font-size:32px;color:var(--accent-600);"></i>
              </div>
              <div class="fw-bold"><?= h($cit['name']) ?></div>
              <div class="text-muted small"><?= h($cit['email']) ?></div>
              <div class="mt-2 d-flex justify-content-center gap-2">
                <span class="badge bg-primary bg-opacity-75"><?= (int)$cit['reports_count'] ?> reports</span>
                <span class="badge bg-secondary bg-opacity-75">👍 <?= (int)$cit['votes_received'] ?></span>
              </div>
              <div class="mt-1 small"><?= starRating($cit['avg_rating'] !== null ? (float)$cit['avg_rating'] : null) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($citizens) > 3): ?>
        <div class="table-responsive">
          <table class="table table-dark-custom align-middle mb-0">
            <thead>
              <tr>
                <th style="width:60px;">Rank</th>
                <th>Name</th>
                <th>Email</th>
                <th style="width:130px;">Reports Filed</th>
                <th style="width:130px;">Votes Received</th>
                <th style="width:160px;">Avg Rating</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($citizens as $rank => $cit): ?>
                <tr>
                  <td><?= medalBadge($rank + 1) ?></td>
                  <td class="fw-semibold"><?= h($cit['name']) ?></td>
                  <td class="text-muted small"><?= h($cit['email']) ?></td>
                  <td><span class="badge bg-primary bg-opacity-75"><?= (int)$cit['reports_count'] ?></span></td>
                  <td class="text-muted">👍 <?= (int)$cit['votes_received'] ?></td>
                  <td><?= starRating($cit['avg_rating'] !== null ? (float)$cit['avg_rating'] : null) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>