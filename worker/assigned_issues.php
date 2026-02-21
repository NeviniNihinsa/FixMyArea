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

$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.status, i.created_at,
    c.category_name,
    a.area_name,
    u.email AS reporter_email,
    x.assignment_status
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
    <div class="table-responsive d-none d-md-block">
      <table class="table table-dark-custom align-middle mb-0">
        <thead>
          <tr>
            <th>Issue ID</th>
            <th>Title</th>
            <th>Category</th>
            <th>Area</th>
            <th>Reported By</th>
            <th>Status</th>
            <th style="width:110px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-muted">No assigned issues found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['area_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
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
      <?php if (!$rows): ?>
        <div class="text-muted">No assigned issues found.</div>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <div class="card-dark p-3">
            <div class="d-flex justify-content-between gap-2">
              <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
              <span class="badge bg-secondary"><?= h(niceStatus((string)$r['status'])) ?></span>
            </div>
            <div class="text-muted small mt-1">
              <?= h($r['category_name'] ?? '—') ?> • <?= h($r['area_name'] ?? '—') ?>
            </div>
            <div class="text-muted small">Reported by: <?= h($r['reporter_email'] ?? '—') ?></div>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>