<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Assigned Issues - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

/**
 * Local Authority Name logic:
 * We take the latest status change done by an AUTHORITY user for each issue.
 * This works with your existing tables without adding new columns.
 */
$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.status, i.created_at,
    c.category_name,
    a.area_name AS branch_name,
    u.email AS reporter_email,
    x.assignment_status,

    (
      SELECT au.name
      FROM issue_status_history h
      JOIN users au ON au.user_id = h.changed_by_user_id
      WHERE h.issue_id = i.issue_id
        AND au.role = 'authority'
      ORDER BY h.created_at DESC
      LIMIT 1
    ) AS authority_name

  FROM assignments x
  JOIN issues i ON i.issue_id = x.issue_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN users u ON u.user_id = i.reporter_user_id
  WHERE x.field_worker_id = ?
  ORDER BY i.created_at DESC, i.issue_id DESC
");
$st->execute([$userId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function niceStatus(string $s): string { return strtoupper(trim($s)); }
?>

<div class="container py-4 app-container">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="fw-bold mb-0">Assigned Issue</h2>
  </div>

  <div class="card-dark p-3 p-md-4">

    <!-- Desktop table -->
    <div class="table-responsive d-none d-md-block">
      <table class="table table-dark-custom align-middle mb-0">
        <thead>
          <tr>
            <th>Issue ID</th>
            <th>Title</th>
            <th>Category</th>
            <th> branch</th>
            <th>Reported By</th>
            <th> Authority Name</th>
            <th>Status</th>
            <th style="width:110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="8" class="text-muted">No assigned issues found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['branch_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td><?= h($r['authority_name'] ?? '—') ?></td>
                <td>
                  <span class="badge bg-secondary"><?= h(niceStatus((string)($r['status'] ?? ''))) ?></span>
                </td>
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
      <?php if (!$rows): ?>
        <div class="text-muted">No assigned issues found.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <div class="card-dark p-3">
            <div class="d-flex justify-content-between gap-2">
              <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
              <span class="badge bg-secondary"><?= h(niceStatus((string)($r['status'] ?? ''))) ?></span>
            </div>

            <div class="text-muted small mt-2">
              <div><span class="fw-semibold">Category:</span> <?= h($r['category_name'] ?? '—') ?></div>
              <div><span class="fw-semibold"> branch:</span> <?= h($r['branch_name'] ?? '—') ?></div>
              <div><span class="fw-semibold">Reported By:</span> <?= h($r['reporter_email'] ?? '—') ?></div>
              <div><span class="fw-semibold"> Authority:</span> <?= h($r['authority_name'] ?? '—') ?></div>
            </div>

            <div class="mt-3">
              <a class="btn btn-sm btn-outline-brand"
                 href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>