<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

/**
 * Support both role names (some DBs use "local authority", some use "authority")
 */
require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Manage Users - FixMyArea';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* -----------------------------
   1) Load authority's assigned area
------------------------------ */
$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? '');

if ($myAreaId <= 0) {
  http_response_code(403);
  echo "<div style='padding:20px;font-family:Arial'>403 Forbidden (Authority has no assigned area)</div>";
  exit;
}

/* -----------------------------
   2) Toggle user (Enable/Disable)
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user') {
  $targetId = (int)($_POST['user_id'] ?? 0);

  if ($targetId > 0) {

    // Ensure user belongs to this authority area and is a field worker
    $st = $pdo->prepare("
      SELECT user_id, status
      FROM users
      WHERE user_id = ?
        AND area_id = ?
        AND LOWER(role) IN ('field worker','worker')
      LIMIT 1
    ");
    $st->execute([$targetId, $myAreaId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if ($u) {
      $cur = strtolower((string)$u['status']);
      $newStatus = ($cur === 'active') ? 'inactive' : 'active';

      $up = $pdo->prepare("UPDATE users SET status=? WHERE user_id=? LIMIT 1");
      $up->execute([$newStatus, $targetId]);

      $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User status updated.'];
    } else {
      $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid user (not a field worker in your area).'];
    }
  }

  header("Location: " . BASE_URL . "/authority/manage_users.php");
  exit;
}

/* -----------------------------
   3) Filters
------------------------------ */
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedStatus = ['all','active','inactive'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'all';

/* -----------------------------
   4) Load users list
------------------------------ */
$sql = "
  SELECT u.user_id, u.name, u.email, u.status, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.area_id = :area_id
    AND LOWER(u.role) IN ('field worker','worker')
";
$params = [':area_id' => $myAreaId];

if ($statusFilter !== 'all') {
  $sql .= " AND LOWER(u.status) = :status ";
  $params[':status'] = $statusFilter;
}

$sql .= " ORDER BY u.created_at DESC, u.user_id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

/* -----------------------------
   5) Flash
------------------------------ */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* -----------------------------
   6) Page layout
------------------------------ */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php'; // ✅ logged-in navbar
?>

<div class="app-container">
  <div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1">Manage Users</h2>
        <div class="text-muted">Area: <?= h($myAreaName ?: 'My Area') ?></div>
      </div>
      <a class="btn btn-brand" href="<?= BASE_URL ?>/authority/create_user.php">Create New User</a>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>">
        <?= h($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>

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
          <input class="form-control" value="<?= h($myAreaName ?: 'My Area') ?>" readonly>
        </div>

        <div class="col-12 col-md-5 d-flex gap-2">
          <button class="btn btn-brand" type="submit">Apply</button>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/authority/manage_users.php">Reset</a>
        </div>

      </form>
    </div>

    <div class="card-dark p-3">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:160px;">Field Worker ID</th>
              <th>Name</th>
              <th style="min-width:180px;">Area</th>
              <th style="width:120px;">Status</th>
              <th style="width:190px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="5" class="text-muted">No users found.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u): ?>
              <?php
                $fwId = 'FW' . str_pad((string)$u['user_id'], 4, '0', STR_PAD_LEFT);
                $isActive = (strtolower((string)$u['status']) === 'active');
              ?>
              <tr>
                <td><?= h($fwId) ?></td>
                <td>
                  <div class="fw-semibold"><?= h($u['name']) ?></div>
                  <div class="text-muted small"><?= h($u['email']) ?></div>
                </td>
                <td><?= h($u['area_name'] ?? $myAreaName) ?></td>
                <td><?= $isActive ? 'Active' : 'Inactive' ?></td>
                <td class="d-flex gap-2">
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