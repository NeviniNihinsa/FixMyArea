<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

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

$branchName = '—';
$areaId = (int)($user['area_id'] ?? 0);
if ($areaId > 0) {
  $st = $pdo->prepare("SELECT area_name FROM areas WHERE area_id=? LIMIT 1");
  $st->execute([$areaId]);
  $branchName = (string)($st->fetchColumn() ?: '—');
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pick(array $old, array $user, string $key): string {
  if (array_key_exists($key, $old)) return (string)$old[$key];
  return (string)($user[$key] ?? '');
}

$name    = pick($old, $user, 'name');
$email   = (string)($user['email'] ?? '');
$nic     = (string)($user['nic'] ?? '');
$dob     = pick($old, $user, 'dob');
$phone   = pick($old, $user, 'phone');
$gender  = strtolower(trim(pick($old, $user, 'gender')));
$address = pick($old, $user, 'address');

$workerDisplayId = 'WORK' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT);
?>

<div class="container py-4 app-container" style="max-width: 980px;">

  <?php if ($flash && is_array($flash)): ?>
    <div class="alert alert-<?= h($flash['type'] ?? 'info') ?>"><?= h($flash['msg'] ?? '') ?></div>
  <?php endif; ?>

  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= h($errors['general']) ?></div>
  <?php endif; ?>

  <div class="card-dark p-4 p-md-5">

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2 class="fw-bold m-0">Profile</h2>
    </div>

    <div class="d-flex justify-content-center mb-3">
      <div class="rounded-circle d-flex align-items-center justify-content-center"
           style="width:120px;height:120px;border:2px solid rgba(255,255,255,0.15);">
        <i class="bi bi-person" style="font-size:56px;opacity:.75;"></i>
      </div>
    </div>

    <div class="text-center text-muted small mb-4">
      Field Worker ID: <?= h($workerDisplayId) ?>
    </div>

    <form method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" id="profileForm" novalidate>
      <input type="hidden" name="role_redirect" value="worker">

      <div class="row g-3">

        <div class="col-12 col-md-6">
          <label class="form-label">Full Name</label>
          <input class="form-control" name="name" value="<?= h($name) ?>" required maxlength="150" readonly>
          <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" value="<?= h($email) ?>" readonly>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">NIC</label>
          <input class="form-control" value="<?= h($nic) ?>" readonly>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Phone Number</label>
          <input class="form-control" name="phone" value="<?= h($phone) ?>" maxlength="20" required readonly>
          <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Date of Birth</label>
          <input type="date" class="form-control" name="dob" value="<?= h($dob) ?>" readonly>
          <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Gender</label>
          <?php
            $g = $gender;
            $maleChecked   = ($g === 'male') ? 'checked' : '';
            $femaleChecked = ($g === 'female') ? 'checked' : '';
            $otherChecked  = ($g === 'other') ? 'checked' : '';
            if ($g !== 'male' && $g !== 'female' && $g !== 'other') $otherChecked = 'checked';
          ?>
          <div class="d-flex gap-3 align-items-center flex-wrap">
            <label class="d-flex gap-2 align-items-center m-0">
              <input type="radio" name="gender" value="male" <?= $maleChecked ?> disabled>
              <span>Male</span>
            </label>
            <label class="d-flex gap-2 align-items-center m-0">
              <input type="radio" name="gender" value="female" <?= $femaleChecked ?> disabled>
              <span>Female</span>
            </label>
            <label class="d-flex gap-2 align-items-center m-0">
              <input type="radio" name="gender" value="other" <?= $otherChecked ?> disabled>
              <span>Other</span>
            </label>
          </div>
          <div class="field-error"><?= h($errors['gender'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Assigned Branch</label>
          <input class="form-control" value="<?= h($branchName) ?>" readonly>
          <div class="text-muted small mt-1">Branch is assigned & managed by the Authority</div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Address</label>
          <input class="form-control" name="address" value="<?= h($address) ?>" maxlength="255" required readonly>
          <div class="field-error"><?= h($errors['address'] ?? '') ?></div>
        </div>

      </div>

      <div class="d-flex justify-content-center gap-2 mt-4 flex-wrap">
        <button type="button" class="btn btn-outline-brand" id="btnEdit">Edit Profile</button>
        <button type="submit" class="btn btn-brand" id="btnSave" style="display:none;">Save Changes</button>
        <button type="button" class="btn btn-outline-brand" id="btnCancel" style="display:none;">Cancel</button>
      </div>
    </form>

  </div>
</div>

<script>
(() => {
  const form = document.getElementById('profileForm');
  const btnEdit = document.getElementById('btnEdit');
  const btnSave = document.getElementById('btnSave');
  const btnCancel = document.getElementById('btnCancel');

  const editable = ['name','phone','dob','address'];

  function setMode(viewMode) {
    editable.forEach(n => {
      const el = form.querySelector(`[name="${n}"]`);
      if (!el) return;
      if (viewMode) el.setAttribute('readonly','readonly');
      else el.removeAttribute('readonly');
    });

    form.querySelectorAll('input[name="gender"]').forEach(r => r.disabled = viewMode);

    btnSave.style.display   = viewMode ? 'none' : 'inline-block';
    btnCancel.style.display = viewMode ? 'none' : 'inline-block';
    btnEdit.style.display   = viewMode ? 'inline-block' : 'none';
  }

  setMode(true);

  btnEdit.addEventListener('click', () => setMode(false));

  btnCancel.addEventListener('click', () => window.location.reload());

  form.addEventListener('submit', (e) => {
    const name = (form.querySelector('[name="name"]').value || '').trim();
    const phone = (form.querySelector('[name="phone"]').value || '').trim();
    const address = (form.querySelector('[name="address"]').value || '').trim();

    if (name.length < 2 || phone.length < 7 || address.length < 2) {
      e.preventDefault();
      alert("Please fill required fields (Name, Phone, Address).");
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
