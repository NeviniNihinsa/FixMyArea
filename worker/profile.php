<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

$flash  = $_SESSION['flash'] ?? null;
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['flash'], $_SESSION['form_errors'], $_SESSION['old']);

$st = $pdo->prepare("
  SELECT user_id, name, email, nic, dob, phone, gender, address, area_id, role
  FROM users
  WHERE user_id=?
  LIMIT 1
");
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'User not found.'];
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// Areas for dropdown
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

// helper
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// values
$name    = (string)($old['name'] ?? $user['name'] ?? '');
$email   = (string)($user['email'] ?? '');
$nic     = (string)($user['nic'] ?? '');
$dob     = (string)($old['dob'] ?? $user['dob'] ?? '');
$phone   = (string)($old['phone'] ?? $user['phone'] ?? '');
$gender  = (string)($old['gender'] ?? $user['gender'] ?? '');
$address = (string)($old['address'] ?? $user['address'] ?? '');
$areaId  = (int)($old['area_id'] ?? $user['area_id'] ?? 0);

// used only for display
$areaName = 'Not set';
foreach ($areas as $a) {
  if ((int)$a['area_id'] === (int)$user['area_id']) { $areaName = (string)$a['area_name']; break; }
}
?>

<style>
  .profile-wrap{
    max-width: 980px;
    margin: 0 auto;
  }
  .avatar-circle{
    width: 140px;
    height: 140px;
    border-radius: 50%;
    border: 3px solid rgba(241,246,246,0.7);
    display:flex;
    align-items:center;
    justify-content:center;
    margin: 0 auto;
  }
  .avatar-circle i{ font-size: 54px; opacity: .85; }
  .profile-label{ color: rgba(241,246,246,0.9); font-weight: 500; }
  .profile-value{ color: rgba(241,246,246,0.95); }
  .readonly-box{ background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); }
</style>

<div class="container py-4 app-container">
  <div class="profile-wrap">

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <div class="card-dark p-4 p-md-5">
      <div class="row g-4 align-items-start">

        <!-- LEFT details -->
        <div class="col-12 col-lg-6">
          <h2 class="fw-bold mb-2">Profile</h2>
          <div class="text-muted mb-4">Role: <span class="fw-semibold">Field Worker</span></div>

          <form method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" novalidate>
            <input type="hidden" name="role_redirect" value="worker">

            <!-- Full Name -->
            <div class="mb-3">
              <div class="profile-label mb-1">Full Name:</div>
              <input type="text" name="name" class="form-control"
                     value="<?= h($name) ?>" maxlength="150" required>
              <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
            </div>

            <!-- Worker ID (display only) -->
            <div class="mb-3">
              <div class="profile-label mb-1">Field Worker ID</div>
              <input type="text" class="form-control readonly-box" value="<?= 'WORK' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT) ?>" readonly>
              <div class="text-muted small mt-1">This is a display ID (not stored separately).</div>
            </div>

            <!-- Assigned Area (settable from dropdown) -->
            <div class="mb-3">
              <div class="profile-label mb-1">Assigned Area</div>
              <select name="area_id" class="form-select" required>
                <option value="0">Select area</option>
                <?php foreach ($areas as $a): ?>
                  <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === (int)$areaId) ? 'selected' : '' ?>>
                    <?= h((string)$a['area_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-error"><?= h($errors['area_id'] ?? '') ?></div>
            </div>

            <!-- Email -->
            <div class="mb-3">
              <div class="profile-label mb-1">Email :</div>
              <input type="text" class="form-control readonly-box" value="<?= h($email) ?>" readonly>
            </div>

            <!-- Address -->
            <div class="mb-3">
              <div class="profile-label mb-1">Address:</div>
              <input type="text" name="address" class="form-control"
                     value="<?= h($address) ?>" maxlength="255" required>
              <div class="field-error"><?= h($errors['address'] ?? '') ?></div>
            </div>

            <!-- Phone -->
            <div class="mb-3">
              <div class="profile-label mb-1">Phone Number:</div>
              <input type="text" name="phone" class="form-control"
                     value="<?= h($phone) ?>" maxlength="20" required>
              <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
            </div>

            <!-- Optional: DOB + Gender-->
            <div class="row g-3">
              <div class="col-12 col-md-6">
                <div class="profile-label mb-1">Date of Birth:</div>
                <input type="date" name="dob" class="form-control" value="<?= h($dob) ?>">
                <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
              </div>

              <div class="col-12 col-md-6">
                <div class="profile-label mb-1">Gender:</div>
                <select name="gender" class="form-select">
                  <option value="">Select</option>
                  <option value="male"   <?= ($gender === 'male') ? 'selected' : '' ?>>Male</option>
                  <option value="female" <?= ($gender === 'female') ? 'selected' : '' ?>>Female</option>
                  <option value="other"  <?= ($gender === 'other') ? 'selected' : '' ?>>Other</option>
                </select>
                <div class="field-error"><?= h($errors['gender'] ?? '') ?></div>
              </div>
            </div>

            <div class="d-flex justify-content-center justify-content-lg-end gap-3 mt-4">
              <a class="btn btn-outline-light" href="<?= BASE_URL ?>/worker/home.php">Edit Profile</a>
              <button class="btn btn-brand" type="submit">Save Changes</button>
            </div>

          </form>
        </div>

        <!-- RIGHT avatar -->
        <div class="col-12 col-lg-6 text-center">
          <div class="avatar-circle mb-3">
            <i class="bi bi-person"></i>
          </div>
        </div>

      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>