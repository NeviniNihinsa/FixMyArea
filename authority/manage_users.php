<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

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

/* Get current authority area */
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

/* POST: Toggle user */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user') {
  $targetId = (int)($_POST['user_id'] ?? 0);

  if ($targetId > 0) {
    $st = $pdo->prepare("
      SELECT user_id, status
      FROM users
      WHERE user_id = ?
        AND area_id = ?
        AND TRIM(LOWER(role)) IN ('field worker','worker','field_worker','fieldworker')
      LIMIT 1
    ");
    $st->execute([$targetId, $myAreaId]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if ($u) {
      $cur = strtolower(trim((string)$u['status']));
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

/* Filters */
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
$allowedStatus = ['all','active','inactive'];
if (!in_array($statusFilter, $allowedStatus, true)) $statusFilter = 'all';

$qRaw = trim((string)($_GET['q'] ?? ''));

/**
 * Support searching "FW0003" format too.
 * If user types FW0003 -> extract 3
 * If user types 3 -> treat as id too
 */
$uidExact = null;
if ($qRaw !== '') {
  if (preg_match('/^FW0*([0-9]+)$/i', $qRaw, $m)) {
    $uidExact = (int)$m[1];
  } elseif (ctype_digit($qRaw)) {
    $uidExact = (int)$qRaw;
  }
}

/* Build SQL safely (no HY093) */
$sql = "
  SELECT u.user_id, u.name, u.email, u.nic, u.dob, u.phone, u.gender, u.status, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.area_id = :area_id
    AND TRIM(LOWER(u.role)) IN ('field worker','worker','field_worker','fieldworker')
";
$params = [':area_id' => $myAreaId];

if ($statusFilter !== 'all') {
  $sql .= " AND TRIM(LOWER(u.status)) = :status ";
  $params[':status'] = $statusFilter;
}

if ($qRaw !== '') {
  $sql .= " AND ( ";
  $sql .= "   LOWER(TRIM(u.name))  LIKE :q_like ";
  $sql .= "   OR LOWER(TRIM(u.email)) LIKE :q_like ";
  $sql .= "   OR CAST(u.user_id AS CHAR) LIKE :q_raw ";
  if ($uidExact !== null) {
    $sql .= "   OR u.user_id = :uid_exact ";
    $params[':uid_exact'] = $uidExact;
  }
  $sql .= " ) ";

  $params[':q_like'] = '%' . mb_strtolower($qRaw, 'UTF-8') . '%';
  $params[':q_raw']  = '%' . $qRaw . '%';
}

$sql .= " ORDER BY u.created_at DESC, u.user_id DESC";

$st = $pdo->prepare($sql);
$st->execute($params);
$users = $st->fetchAll(PDO::FETCH_ASSOC);

/* Flash */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* Render */
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-container">
  <div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1">Manage Users</h2>
        <div class="text-muted">Branch: <?= h($myAreaName ?: 'My Area') ?></div>
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

        <div class="col-12 col-md-4">
          <label class="form-label">Search</label>
          <input class="form-control" name="q" value="<?= h($qRaw) ?>" placeholder="Search name / email / ID ">
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="all" <?= $statusFilter==='all'?'selected':'' ?>>All</option>
            <option value="active" <?= $statusFilter==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $statusFilter==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>

     

        <div class="col-12 col-md-2 d-flex gap-2">
          <button class="btn btn-brand w-100" type="submit">Apply</button>
          <a class="btn btn-outline-brand w-100" href="<?= BASE_URL ?>/authority/manage_users.php">Reset</a>
        </div>

      </form>
    </div>

    <div class="card-dark p-3">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:140px;">Technician ID</th>
              <th>Name</th>
              <th>Email</th>
              <th>NIC</th>
              <th>DOB</th>
              <th>Phone</th>
              <th>Gender</th>
              <th style="width:100px;">Status</th>
              <th style="width:160px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($users)): ?>
            <tr><td colspan="9" class="text-muted">No users found.</td></tr>
          <?php else: ?>
            <?php foreach ($users as $u): ?>
              <?php
                $fwId = 'FW' . str_pad((string)$u['user_id'], 4, '0', STR_PAD_LEFT);
                $isActive = (strtolower(trim((string)$u['status'])) === 'active');
              ?>
              <tr>
                <td><?= h($fwId) ?></td>
                <td><?= h($u['name']) ?></td>
                <td><?= h($u['email']) ?></td>
                <td><?= h($u['nic'] ?? '—') ?></td>
                <td><?= h($u['dob'] ?? '—') ?></td>
                <td><?= h($u['phone'] ?? '—') ?></td>
                <td><?= h(ucfirst((string)($u['gender'] ?? '—'))) ?></td>
                <td><?= $isActive ? 'Active' : 'Inactive' ?></td>
                <td class="d-flex gap-2">
                  

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

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>