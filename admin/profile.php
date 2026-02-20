<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Admin Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php'; // dashboard navbar

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

/** Load admin user data */
$st = $pdo->prepare("
  SELECT user_id, name, email, nic, phone, dob, gender, address, role, status, area_id
  FROM users
  WHERE user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) {
  header("Location: " . BASE_URL . "/auth/logout.php");
  exit;
}

/** Areas list (optional display) */
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

/** Flash + validation errors */
$flash  = $_SESSION['flash'] ?? null;
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['flash'], $_SESSION['form_errors'], $_SESSION['old']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Resolve old values (if validation failed) */
$name    = (string)($old['name'] ?? $me['name'] ?? '');
$email   = (string)($old['email'] ?? $me['email'] ?? '');
$phone   = (string)($old['phone'] ?? $me['phone'] ?? '');
$dob     = (string)($old['dob'] ?? ($me['dob'] ?? ''));
$gender  = (string)($old['gender'] ?? ($me['gender'] ?? ''));
$address = (string)($old['address'] ?? ($me['address'] ?? ''));

$roleRaw = (string)($me['role'] ?? 'admin');
$role = strtolower(trim($roleRaw));
$status = (string)($me['status'] ?? 'active');

/** Area display name (admin may not have area) */
$areaName = '—';
$areaId = (int)($me['area_id'] ?? 0);
if ($areaId > 0) {
  foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = (string)$a['area_name']; break; }
  }
}
?>

<style>
  /* matches citizen profile style (safe, page-local) */
  .profile-shell{
    max-width: 980px;
    margin: 0 auto;
  }
  .avatar-ring{
    width: 120px; height: 120px;
    border-radius: 50%;
    border: 3px solid rgba(241,246,246,0.55);
    display:flex; align-items:center; justify-content:center;
    margin: 0 auto 10px auto;
  }
  .avatar-ring i{
    font-size: 56px;
    color: rgba(241,246,246,0.55);
  }
</style>

<div class="container py-4 app-container">
  <div class="profile-shell">

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
      <div>
        <h2 class="fw-bold mb-1">Profile</h2>

      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-outline-brand" type="button" id="btnEdit">Edit Profile</button>
        <button class="btn btn-brand" type="submit" form="profileForm" id="btnSave" disabled>Save Changes</button>
      </div>
    </div>

    <?php if ($flash): ?>
      <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger"><?= h($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card-dark p-4">
      <div class="row g-4 align-items-start">

        <div class="col-12 col-lg-4 text-center">
          <div class="avatar-ring">
            <i class="bi bi-person"></i>
          </div>
          <div class="text-muted small">Admin Profile</div>
        </div>

        <div class="col-12 col-lg-8">

          <form id="profileForm" method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" novalidate>
            <!-- tell common action where to return -->
            <input type="hidden" name="return_to" value="/admin/profile.php">

            <div class="row g-3">

              <div class="col-12">
                <label class="form-label">Name</label>
                <input class="form-control" name="name" value="<?= h($name) ?>" disabled required maxlength="150">
                <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">Email</label>
                <input class="form-control" type="email" name="email" value="<?= h($email) ?>" disabled required maxlength="190">
                <div class="field-error"><?= h($errors['email'] ?? '') ?></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">NIC</label>
                <input class="form-control" value="<?= h((string)$me['nic']) ?>" disabled>
                <div class="field-error"></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">Phone</label>
                <input class="form-control" name="phone" value="<?= h($phone) ?>" disabled maxlength="20" placeholder="07xxxxxxxx">
                <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">Date of Birth</label>
                <input class="form-control" type="date" name="dob" value="<?= h($dob) ?>" disabled>
                <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
              </div>

              <div class="col-12">
                <label class="form-label d-block">Gender</label>
                <?php
                  $g = strtolower(trim($gender));
                  $checked = fn(string $x) => ($g === $x) ? 'checked' : '';
                ?>
                <div class="d-flex flex-wrap gap-3">
                  <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="gender" value="male" <?= $checked('male') ?> disabled>
                    <span>Male</span>
                  </label>
                  <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="gender" value="female" <?= $checked('female') ?> disabled>
                    <span>Female</span>
                  </label>
                  <label class="d-flex align-items-center gap-2">
                    <input type="radio" name="gender" value="other" <?= $checked('other') ?> disabled>
                    <span>Other</span>
                  </label>
                </div>
                <div class="field-error"><?= h($errors['gender'] ?? '') ?></div>
              </div>

              <div class="col-12">
                <label class="form-label">Address</label>
                <input class="form-control" name="address" value="<?= h($address) ?>" disabled maxlength="255" placeholder="Optional">
                <div class="field-error"><?= h($errors['address'] ?? '') ?></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">Area (info)</label>
                <input class="form-control" value="<?= h($areaName) ?>" disabled>
                <div class="field-error"></div>
              </div>

              <div class="col-12 col-md-6">
                <label class="form-label">Role (info)</label>
                <input class="form-control" value="<?= h(ucwords($roleRaw)) ?>" disabled>
                <div class="field-error"></div>
              </div>

            </div>
          </form>

        </div>
      </div>
    </div>

  </div>
</div>

<script>
(() => {
  const form = document.getElementById('profileForm');
  const btnEdit = document.getElementById('btnEdit');
  const btnSave = document.getElementById('btnSave');

  const editableSelector = [
    'input[name="name"]',
    'input[name="email"]',
    'input[name="phone"]',
    'input[name="dob"]',
    'input[name="address"]',
    'input[name="gender"]'
  ].join(',');

  const setEditable = (on) => {
    form.querySelectorAll(editableSelector).forEach(el => el.disabled = !on);
    btnSave.disabled = !on;
    btnEdit.textContent = on ? 'Cancel' : 'Edit Profile';
  };

  let editing = false;
  btnEdit.addEventListener('click', () => {
    editing = !editing;
    if (!editing) window.location.reload(); // cancel -> reload to reset values
    else setEditable(true);
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>