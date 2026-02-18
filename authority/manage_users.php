<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Manage Users - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);

// ✅ get authority area
$myAreaId = null;
$myAreaName = '';

$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId = isset($me['area_id']) ? (int)$me['area_id'] : null;
$myAreaName = (string)($me['area_name'] ?? '');

// If authority has no area, block safely
if (!$myAreaId) {
  http_response_code(403);
  echo "403 Forbidden (Authority has no assigned area)";
  exit;
}

// -------------------------
// Handle Toggle (Enable/Disable) in SAME file (no extra action file)
// -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user') {
  $targetId = (int)($_POST['user_id'] ?? 0);

  if ($targetId > 0) {
    // Only field workers in my area can be toggled
    $st = $pdo->prepare("
      SELECT user_id, status
      FROM users
      WHERE user_id = ? AND role='field worker' AND area_id = ?
      LIMIT 1
    ");
    $st->execute([$targetId, $myAreaId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if ($u) {
      $newStatus = ($u['status'] === 'active') ? 'inactive' : 'active';
      $up = $pdo->prepare("UPDATE users SET status=? WHERE user_id=? LIMIT 1");
      $up->execute([$newStatus, $targetId]);

      $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User status updated.'];
    } else {
      $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid user.'];
    }
  }

  header("Location: " . BASE_URL . "/authority/manage_users.php");
  exit;
}

// -------------------------
// Filters (low-fi: Status + Area)
// Area is fixed to authority area -> show as disabled “grey column”
// -------------------------
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedStatus = ['all','active','inactive'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'all';

// Build query
$sql = "
  SELECT u.user_id, u.name, u.email, u.status, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.role='field worker' AND u.area_id = :area_id
";
$params = [':area_id' => $myAreaId];

if ($statusFilter !== 'all') {
  $sql .= " AND u.status = :status ";
  $params[':status'] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC, u.user_id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

// Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php'; // ✅ as you requested everywhere
?>

<div class="app-container">
  <div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1">Manage Users</h2>
        <div class="text-muted">Area: <?= htmlspecialchars($myAreaName ?: 'My Area') ?></div>
      </div>
      <a class="btn btn-brand" href="<?= BASE_URL ?>/authority/create_user.php">Create New User</a>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Filters (like low-fi) -->
    <div class="card-dark p-3 mb-3">
      <form method="GET" class="row g-3 align-items-end">

        <div class="col-12 col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option>
            <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>

        <div class="col-12 col-md-4">
          <label class="form-label">Area</label>
          <!-- ✅ fixed area, show as disabled grey (no dropdown) -->
          <input class="form-control" value="<?= htmlspecialchars($myAreaName ?: 'My Area') ?>" readonly>
        </div>

        <div class="col-12 col-md-5 d-flex gap-2">
          <button class="btn btn-brand" type="submit">Apply</button>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/authority/manage_users.php">Reset</a>
        </div>

      </form>
    </div>

    <!-- Table -->
    <div class="card-dark p-3">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:140px;">Field Worker ID</th>
              <th>Name</th>
              <th style="min-width:180px;">Area</th>
              <th style="width:120px;">Status</th>
              <th style="width:180px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$users): ?>
            <tr><td colspan="5" class="text-muted">No users found.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u): ?>
              <?php
                $fwId = 'FW' . str_pad((string)$u['user_id'], 4, '0', STR_PAD_LEFT);
                $isActive = ($u['status'] === 'active');
              ?>
              <tr>
                <td><?= htmlspecialchars($fwId) ?></td>
                <td>
                  <div class="fw-semibold"><?= htmlspecialchars($u['name']) ?></div>
                  <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                </td>
                <td><?= htmlspecialchars($u['area_name'] ?? $myAreaName) ?></td>
                <td><?= $isActive ? 'Active' : 'Inactive' ?></td>
                <td class="d-flex gap-2">
                  <!-- “View” (no separate view_user.php in your required list, so keep it simple) -->
                  <button class="btn btn-sm btn-outline-brand" type="button" disabled>View</button>

                  <form method="POST" class="m-0">
                    <input type="hidden" name="action" value="toggle_user">
                    <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                    <button class="btn btn-sm <?= $isActive ? 'btn-outline-danger' : 'btn-outline-brand' ?>"
                            type="submit"
                            onclick="return confirm('Are you sure?');">
                      <?= $isActive ? 'Disable' : 'Enable' ?>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>