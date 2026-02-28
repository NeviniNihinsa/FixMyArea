<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Manage Users - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Filters
$q = trim((string)($_GET['q'] ?? ''));
$role = trim((string)($_GET['role'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.nic LIKE ? OR u.phone LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($role !== '') {
    $where[] = "u.role = ?";
    $params[] = $role;
}
if ($status !== '') {
    $where[] = "u.status = ?";
    $params[] = $status;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$st = $pdo->prepare("
  SELECT
    u.user_id, u.role, u.name, u.email, u.phone, u.nic, u.status,
    a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  $whereSql
  ORDER BY u.user_id DESC
  LIMIT 200
");
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function displayRole(string $r): string {
    return match(strtolower(trim($r))) {
        'authority' => 'Property Manager',
        'worker'    => 'Maintenance Technician',
        'citizen'   => 'Tenant',
        'admin'     => 'Admin',
        default     => ucfirst($r),
    };
}
?>

<div class="container py-4 app-container">

  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
    <h2 class="fw-bold mb-0">Manage Users</h2>
    <a class="btn btn-brand" href="<?= BASE_URL ?>/admin/add_user.php">+ Add User</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?> mb-3"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <div class="card-dark p-3 p-md-4 mb-4">
    <form class="row g-2 align-items-end" method="GET">
      <div class="col-12 col-md-5">
        <label class="form-label text-muted small">Search</label>
        <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Name / Email / NIC / Phone">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label text-muted small">Role</label>
        <select class="form-select" name="role">
          <option value="">All</option>
          <option value="citizen" <?= $role==='citizen'?'selected':'' ?>>Tenant</option>
          <option value="worker" <?= $role==='worker'?'selected':'' ?>>Maintenance Technician</option>
          <option value="authority" <?= $role==='authority'?'selected':'' ?>>Property Manager</option>
          <option value="admin" <?= $role==='admin'?'selected':'' ?>>Admin</option>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label text-muted small">Status</label>
        <select class="form-select" name="status">
          <option value="">All</option>
          <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-12 col-md-2 d-grid">
        <button class="btn btn-outline-brand" type="submit">Apply</button>
      </div>
    </form>
  </div>

  <div class="card-dark p-3 p-md-4">
    <?php if (empty($users)): ?>
      <div class="text-muted">No users found.</div>
    <?php else: ?>
      <div class="table-responsive d-none d-md-block">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:90px;">UserID</th>
              <th style="width:160px;">User Role</th>
              <th>Name</th>
              <th style="width:160px;">Branch</th>
              <th style="width:120px;">Status</th>
              <th style="width:190px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php
                $stTxt = strtolower((string)$u['status']);
                $badge = $stTxt === 'active' ? 'bg-success' : 'bg-secondary';
              ?>
              <tr>
                <td>#<?= (int)$u['user_id'] ?></td>
                <td><?= h(displayRole((string)$u['role'])) ?></td>
                <td><?= h((string)$u['name']) ?></td>
                <td><?= h((string)($u['area_name'] ?? '—')) ?></td>
                <td><span class="badge <?= $badge ?>"><?= h($stTxt) ?></span></td>
                <td>
                  <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-sm btn-outline-brand"
                       href="<?= BASE_URL ?>/admin/view_user.php?user_id=<?= (int)$u['user_id'] ?>">View</a>

                    <?php if ((string)$u['role'] !== 'admin'): ?>
                      <form method="POST" action="<?= BASE_URL ?>/actions/admin_toggle_user.php" class="m-0">
                        <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                        <input type="hidden" name="to" value="<?= $stTxt === 'active' ? 'inactive' : 'active' ?>">
                        <button class="btn btn-sm btn-outline-light" type="submit">
                          <?= $stTxt === 'active' ? 'Disable' : 'Enable' ?>
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <div class="d-md-none d-flex flex-column gap-3">
        <?php foreach ($users as $u): ?>
          <?php
            $stTxt = strtolower((string)$u['status']);
            $badge = $stTxt === 'active' ? 'bg-success' : 'bg-secondary';
          ?>
          <div class="card-dark p-3">
            <div class="d-flex justify-content-between">
              <div class="fw-semibold">#<?= (int)$u['user_id'] ?> — <?= h((string)$u['name']) ?></div>
              <span class="badge <?= $badge ?>"><?= h($stTxt) ?></span>
            </div>
            <div class="text-muted small mt-1">
              Role: <?= h((string)$u['role']) ?> · Branch: <?= h((string)($u['area_name'] ?? '—')) ?>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-3">
              <a class="btn btn-sm btn-outline-brand"
                 href="<?= BASE_URL ?>/admin/view_user.php?user_id=<?= (int)$u['user_id'] ?>">View</a>

              <?php if ((string)$u['role'] !== 'admin'): ?>
                <form method="POST" action="<?= BASE_URL ?>/actions/admin_toggle_user.php" class="m-0">
                  <input type="hidden" name="user_id" value="<?= (int)$u['user_id'] ?>">
                  <input type="hidden" name="to" value="<?= $stTxt === 'active' ? 'inactive' : 'active' ?>">
                  <button class="btn btn-sm btn-outline-light" type="submit">
                    <?= $stTxt === 'active' ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>