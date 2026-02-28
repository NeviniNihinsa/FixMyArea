<?php
declare(strict_types=1);

ini_set('display_errors', '1');          // remove later
ini_set('display_startup_errors', '1');  // remove later
error_reporting(E_ALL);                 // remove later

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'View User - Fixly';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
  echo "<div class='container py-4'><div class='alert alert-danger'>Invalid user id.</div></div>";
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}

// Fetch user + area_name
$st = $pdo->prepare("
  SELECT
    u.user_id,
    u.name,
    u.email,
    u.nic,
    u.dob,
    u.phone,
    u.gender,
    u.address,
    u.area_id,
    u.role,
    u.status,
    u.created_at,
    a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC);

if (!$u) {
  echo "<div class='container py-4'><div class='alert alert-warning'>User not found.</div></div>";
  require_once __DIR__ . '/../includes/footer.php';
  exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$roleLabel = match (strtolower((string)$u['role'])) {
  'citizen' => 'Citizen',
  'worker' => 'Field Worker',
  'field worker' => 'Field Worker',
  'authority' => 'Local Authority',
  'local authority' => 'Local Authority',
  'admin' => 'Admin',
  default => (string)$u['role'],
};

$statusLabel = (strtolower((string)$u['status']) === 'inactive') ? 'Inactive' : 'Active';
?>

<div class="container py-4">
  <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="fw-bold mb-0">Display User</h2>
    <a href="<?= BASE_URL ?>/admin/manage_users.php" class="btn btn-outline-brand btn-sm">Back</a>
  </div>

  <div class="card-dark p-4">
    <div class="row g-4">

      <!-- Left column: fields -->
      <div class="col-12 col-lg-8">
        <div class="row g-3">

          <div class="col-12 col-md-6">
            <label class="form-label">Role</label>
            <input class="form-control" value="<?= h($roleLabel) ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Status</label>
            <input class="form-control" value="<?= h($statusLabel) ?>" readonly>
          </div>

          <div class="col-12">
            <label class="form-label">Name</label>
            <input class="form-control" value="<?= h($u['name']) ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Email</label>
            <input class="form-control" value="<?= h($u['email']) ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">NIC</label>
            <input class="form-control" value="<?= h($u['nic']) ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Phone</label>
            <input class="form-control" value="<?= h($u['phone'] ?? '') ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Date of Birth</label>
            <input class="form-control" value="<?= h($u['dob'] ?? '') ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Gender</label>
            <input class="form-control" value="<?= h($u['gender'] ?? '') ?>" readonly>
          </div>

          <div class="col-12 col-md-6">
            <label class="form-label">Branch</label>
            <input class="form-control" value="<?= h($u['area_name'] ?? '') ?>" readonly>
          </div>

          <div class="col-12">
            <label class="form-label">Unit Number</label>
            <input class="form-control" value="<?= h($u['address'] ?? '') ?>" readonly>
          </div>

        </div>
      </div>

      <!-- Right column: placeholder avatar (matches low-fi circle) -->
      <div class="col-12 col-lg-4">
        <div class="d-flex flex-column align-items-center justify-content-center h-100">
          <div style="width:140px;height:140px;border-radius:50%;border:3px solid rgba(241,246,246,0.6);display:flex;align-items:center;justify-content:center;">
            <i class="bi bi-person" style="font-size:52px;opacity:.75;"></i>
          </div>
          <div class="mt-3 text-muted small">User ID: <?= (int)$u['user_id'] ?></div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>