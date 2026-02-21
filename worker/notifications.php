<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Notifications - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Fetch notifications
$st = $pdo->prepare("
  SELECT notification_id, issue_id, notification_type, title, message, action_url,
         is_read, created_at
  FROM notifications
  WHERE user_id = ?
  ORDER BY created_at DESC, notification_id DESC
  LIMIT 100
");
$st->execute([$userId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Count unread
$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$st->execute([$userId]);
$unreadCount = (int)$st->fetchColumn();

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function badgeClass(string $type): string {
    $t = strtoupper(trim($type));
    return match ($t) {
        'NEW_ISSUE' => 'bg-info',
        'STATUS' => 'bg-warning text-dark',
        'ASSIGNMENT' => 'bg-primary',
        'COMMENT' => 'bg-secondary',
        'FEEDBACK_REQUEST' => 'bg-success',
        default => 'bg-dark'
    };
}
?>

<div class="container py-4 app-container">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <div>
      <h2 class="fw-bold mb-1">Notifications</h2>
      <div class="text-muted small">Unread: <span class="fw-semibold"><?= $unreadCount ?></span></div>
    </div>

    <div class="d-flex gap-2">
      <?php if ($unreadCount > 0): ?>
        <form method="POST" action="<?= BASE_URL ?>/actions/notification_mark_read.php" class="m-0">
          <input type="hidden" name="mode" value="all">
          <button class="btn btn-outline-brand btn-sm" type="submit">
            Mark all as read
          </button>
        </form>
      <?php endif; ?>
      <a class="btn btn-brand btn-sm" href="<?= BASE_URL ?>/worker/home.php">Back to Home</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <?php if (empty($rows)): ?>
    <div class="card-dark p-4">
      <div class="text-muted">No notifications yet.</div>
    </div>
  <?php else: ?>

    <div class="card-dark p-3 p-md-4">
      <!-- Desktop table -->
      <div class="table-responsive d-none d-md-block">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:160px;">Type</th>
              <th>Title / Message</th>
              <th style="width:190px;">Date</th>
              <th style="width:190px;">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $n): ?>
            <?php
              $isRead = ((int)$n['is_read'] === 1);

              $actionUrl = trim((string)($n['action_url'] ?? ''));
              if ($actionUrl === '' && !empty($n['issue_id'])) {
                  $actionUrl = BASE_URL . "/worker/view_issue.php?issue_id=" . (int)$n['issue_id'];
              } elseif ($actionUrl !== '' && str_starts_with($actionUrl, '/')) {
                  $actionUrl = BASE_URL . $actionUrl;
              }
            ?>
            <tr class="<?= $isRead ? '' : 'fw-semibold' ?>">
              <td>
                <span class="badge <?= badgeClass((string)$n['notification_type']) ?>">
                  <?= h((string)$n['notification_type']) ?>
                </span>
                <?php if (!$isRead): ?>
                  <span class="badge bg-danger ms-2">NEW</span>
                <?php endif; ?>
              </td>

              <td>
                <div class="mb-1"><?= h((string)$n['title']) ?></div>
                <div class="text-muted small"><?= h((string)$n['message']) ?></div>
              </td>

              <td class="text-muted small"><?= h((string)$n['created_at']) ?></td>

              <td>
                <div class="d-flex flex-wrap gap-2">
                  <?php if ($actionUrl !== ''): ?>
                    <a class="btn btn-sm btn-outline-brand" href="<?= h($actionUrl) ?>">Open</a>
                  <?php endif; ?>

                  <?php if (!$isRead): ?>
                    <form method="POST" action="<?= BASE_URL ?>/actions/notification_mark_read.php" class="m-0">
                      <input type="hidden" name="mode" value="one">
                      <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                      <button class="btn btn-sm btn-outline-light" type="submit">Mark read</button>
                    </form>
                  <?php else: ?>
                    <span class="small text-muted align-self-center">Read</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <div class="d-md-none">
        <div class="d-flex flex-column gap-3">
          <?php foreach ($rows as $n): ?>
            <?php
              $isRead = ((int)$n['is_read'] === 1);

              $actionUrl = trim((string)($n['action_url'] ?? ''));
              if ($actionUrl === '' && !empty($n['issue_id'])) {
                  $actionUrl = BASE_URL . "/worker/view_issue.php?issue_id=" . (int)$n['issue_id'];
              } elseif ($actionUrl !== '' && str_starts_with($actionUrl, '/')) {
                  $actionUrl = BASE_URL . $actionUrl;
              }
            ?>
            <div class="card-dark p-3">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <span class="badge <?= badgeClass((string)$n['notification_type']) ?>">
                    <?= h((string)$n['notification_type']) ?>
                  </span>
                  <?php if (!$isRead): ?>
                    <span class="badge bg-danger ms-2">NEW</span>
                  <?php endif; ?>
                </div>
                <div class="text-muted small"><?= h((string)$n['created_at']) ?></div>
              </div>

              <div class="mt-2 <?= $isRead ? '' : 'fw-semibold' ?>"><?= h((string)$n['title']) ?></div>
              <div class="text-muted small mt-1"><?= h((string)$n['message']) ?></div>

              <div class="mt-3 d-flex flex-wrap gap-2">
                <?php if ($actionUrl !== ''): ?>
                  <a class="btn btn-sm btn-outline-brand" href="<?= h($actionUrl) ?>">Open</a>
                <?php endif; ?>

                <?php if (!$isRead): ?>
                  <form method="POST" action="<?= BASE_URL ?>/actions/notification_mark_read.php" class="m-0">
                    <input type="hidden" name="mode" value="one">
                    <input type="hidden" name="notification_id" value="<?= (int)$n['notification_id'] ?>">
                    <button class="btn btn-sm btn-outline-light" type="submit">Mark read</button>
                  </form>
                <?php else: ?>
                  <span class="small text-muted align-self-center">Read</span>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>