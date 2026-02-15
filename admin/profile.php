<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Admin Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

$st = $pdo->prepare("
  SELECT u.user_id, u.name, u.email, u.nic, u.phone, u.role
  FROM users u
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

if (!$me) {
  echo "<div class='container py-5'><div class='alert alert-danger'>Admin user not found.</div></div>";
  require_once __DIR__ . '/../includes/footer_internal.php';
  exit;
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="container py-4 app-container">

  <h2 class="fw-bold mb-4">Profile</h2>

  <div class="card-dark p-4">
    <div class="row g-4 align-items-center">
      <div class="col-12 col-lg-4 text-center">
        <div style="width:140px;height:140px;border-radius:50%;border:3px solid rgba(241,246,246,0.65);margin:0 auto;"></div>
        <div class="mt-3 text-muted small">Role: <span class="fw-semibold"><?= h(ucfirst($me['role'])) ?></span></div>
      </div>

      <div class="col-12 col-lg-8">
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <label class="text-muted small">Admin Name</label>
            <div class="fw-semibold"><?= h($me['name']) ?></div>
          </div>

          <div class="col-12 col-md-6">
            <label class="text-muted small">Admin ID</label>
            <div class="fw-semibold">#<?= (int)$me['user_id'] ?></div>
          </div>

          <div class="col-12 col-md-6">
            <label class="text-muted small">Email</label>
            <div class="fw-semibold"><?= h($me['email']) ?></div>
          </div>

          <div class="col-12 col-md-6">
            <label class="text-muted small">Phone Number</label>
            <div class="fw-semibold"><?= h($me['phone'] ?? '—') ?></div>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2 justify-content-end">
          <a href="<?= BASE_URL ?>/admin/profile.php" class="btn btn-outline-light btn-sm">Edit Profile</a>
          <button class="btn btn-brand btn-sm" type="button" disabled>Save Changes</button>
        </div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>