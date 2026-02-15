<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'View User - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_GET['id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . BASE_URL . "/admin/manage_users.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.nic,
        u.phone,
        u.dob,
        u.gender,
        u.address,
        u.role,
        u.status,
        u.created_at,
        a.area_name
    FROM users u
    LEFT JOIN areas a ON a.area_id = u.area_id
    WHERE u.user_id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo '<div class="container py-4"><div class="alert alert-danger">User not found.</div></div>';
    require_once __DIR__ . '/../includes/footer_internal.php';
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0">User Details</h2>
    <a class="btn btn-outline-light btn-sm" href="<?= BASE_URL ?>/admin/manage_users.php">Back</a>
  </div>

  <div class="card-dark p-4">

    <div class="row g-3">

      <div class="col-md-6">
        <strong>Name:</strong><br>
        <?= h($user['name']) ?>
      </div>

      <div class="col-md-6">
        <strong>Email:</strong><br>
        <?= h($user['email']) ?>
      </div>

      <div class="col-md-6">
        <strong>NIC:</strong><br>
        <?= h($user['nic']) ?>
      </div>

      <div class="col-md-6">
        <strong>Phone:</strong><br>
        <?= h($user['phone'] ?? '-') ?>
      </div>

      <div class="col-md-6">
        <strong>Date of Birth:</strong><br>
        <?= h($user['dob'] ?? '-') ?>
      </div>

      <div class="col-md-6">
        <strong>Gender:</strong><br>
        <?= h($user['gender'] ?? '-') ?>
      </div>

      <div class="col-md-6">
        <strong>Role:</strong><br>
        <?= h($user['role']) ?>
      </div>

      <div class="col-md-6">
        <strong>Status:</strong><br>
        <span class="badge <?= $user['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>">
            <?= h($user['status']) ?>
        </span>
      </div>

      <div class="col-md-6">
        <strong>Area:</strong><br>
        <?= h($user['area_name'] ?? '-') ?>
      </div>

      <div class="col-md-6">
        <strong>Registered On:</strong><br>
        <?= h($user['created_at']) ?>
      </div>

      <div class="col-12">
        <strong>Address:</strong><br>
        <?= h($user['address'] ?? '-') ?>
      </div>

    </div>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>