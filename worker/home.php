<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Worker Dashboard - FixMyArea';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

/** helper */
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function niceStatus(string $s): string { return strtoupper(trim($s)); }

/** safe: check if a column exists (so we can join authority only if your DB has it) */
function columnExists(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$column]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

/** get worker details + area */
$st = $pdo->prepare("
  SELECT u.user_id, u.name, u.email, u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$areaName = (string)($me['area_name'] ?? 'Not set');
$areaId   = (int)($me['area_id'] ?? 0);

/** stats */
$st = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE field_worker_id=?");
$st->execute([$userId]);
$totalAssigned = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM assignments
  WHERE field_worker_id=?
    AND assignment_status IN ('COMPLETED','CLOSED','DONE')
");
$st->execute([$userId]);
$completed = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM assignments
  WHERE field_worker_id=?
    AND assignment_status IN ('PENDING','ASSIGNED','IN_PROGRESS')
");
$st->execute([$userId]);
$pending = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$st->execute([$userId]);
$newNotifs = (int)$st->fetchColumn();

/**
 * Recently Assigned / Updated Issues
 * New LFD needs Authority Name column.
 *
 * We try to get authority name from issues table if you have a column for it.
 * Supported columns (we detect automatically):
 * - issues.authority_user_id
 * - issues.local_authority_user_id
 * - issues.assigned_authority_user_id
 *
 * If none exist -> fallback shows "Municipal Council <Area>" (safe + matches LFD style)
 */
$authorityCol = null;
$possible = ['authority_user_id', 'local_authority_user_id', 'assigned_authority_user_id'];
foreach ($possible as $col) {
  if (columnExists($pdo, 'issues', $col)) { $authorityCol = $col; break; }
}

$authoritySelect = "CONCAT('Municipal Council ', COALESCE(a.area_name,'—')) AS authority_name";
$authorityJoin   = "";
if ($authorityCol !== null) {
  $authoritySelect = "COALESCE(au.name, CONCAT('Municipal Council ', COALESCE(a.area_name,'—'))) AS authority_name";
  $authorityJoin = "LEFT JOIN users au ON au.user_id = i.`$authorityCol`";
}

$sqlRecent = "
  SELECT
    i.issue_id, i.title, i.status, i.created_at,
    c.category_name,
    a.area_name,
    u.email AS reporter_email,
    $authoritySelect
  FROM assignments x
  JOIN issues i ON i.issue_id = x.issue_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN users u ON u.user_id = i.reporter_user_id
  $authorityJoin
  WHERE x.field_worker_id = ?
  ORDER BY i.created_at DESC, i.issue_id DESC
  LIMIT 6
";
$st = $pdo->prepare($sqlRecent);
$st->execute([$userId]);
$recent = $st->fetchAll(PDO::FETCH_ASSOC);

$techName = (string)($_SESSION['name'] ?? 'Field');
?>

<div class="container py-4 app-container">

  <!-- ✅ LFD text change -->
  <h1 class="fw-bold mb-4">Welcome Field <?= h($techName) ?></h1>

  <div class="row g-4">
    <!-- LEFT: Map Placeholder -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-3 h-100">
        <div class="ratio ratio-1x1" style="border-radius: 14px; overflow:hidden;">
          <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
            <div class="text-center">
              <div class="text-muted">Map Placeholder</div>
              <div class="small text-muted">OpenStreetMap will be added later</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Stats -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">
        <div class="mb-3">
          <div class="text-muted">Assigned Area / Council</div>
          <div class="fw-semibold fs-5"><?= h($areaName) ?></div>
        </div>

        <div class="row g-3">
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Assigned</div>
              <div class="fs-3 fw-bold"><?= (int)$totalAssigned ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Completed</div>
              <div class="fs-3 fw-bold"><?= (int)$completed ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Pending Issues</div>
              <div class="fs-3 fw-bold"><?= (int)$pending ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">New Notifications</div>
              <div class="fs-3 fw-bold"><?= (int)$newNotifs ?></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="mt-4">
    <h4 class="fw-semibold mb-3">Recently Assigned / Updated Issues</h4>

    <div class="card-dark p-3 p-md-4">
      <div class="table-responsive d-none d-md-block">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th>Issue ID</th>
              <th>Title</th>
              <th>Category</th>

              <!-- ✅ LFD rename -->
              <th> branch</th>

              <th>Reported By</th>

              <!-- ✅ LFD new column -->
              <th>Authority Name</th>

              <th>Status</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="8" class="text-muted">No assigned issues yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['area_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td><?= h($r['authority_name'] ?? '—') ?></td>
                <td><span class="badge bg-secondary"><?= h(niceStatus((string)$r['status'])) ?></span></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <div class="d-md-none d-flex flex-column gap-3">
        <?php if (!$recent): ?>
          <div class="text-muted">No assigned issues yet.</div>
        <?php else: ?>
          <?php foreach ($recent as $r): ?>
            <div class="card-dark p-3">
              <div class="d-flex justify-content-between">
                <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
                <span class="badge bg-secondary"><?= h(niceStatus((string)$r['status'])) ?></span>
              </div>

              <div class="text-muted small mt-1">
                <?= h($r['category_name'] ?? '—') ?> • <?= h($r['area_name'] ?? '—') ?>
              </div>
              <div class="text-muted small">Reported by: <?= h($r['reporter_email'] ?? '—') ?></div>
              <div class="text-muted small">Authority: <?= h($r['authority_name'] ?? '—') ?></div>

              <div class="mt-2">
                <a class="btn btn-sm btn-outline-brand"
                   href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>