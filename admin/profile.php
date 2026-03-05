<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

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

/** Areas list */
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

$nic = (string)($me['nic'] ?? '');

/** Area display name (admin may not have area) */
$areaName = '—';
$areaId = (int)($me['area_id'] ?? 0);
if ($areaId > 0) {
  foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = (string)$a['area_name']; break; }
  }
}
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
      Admin ID: #<?= (int)$me['user_id'] ?>
    </div>

    <form id="profileForm" method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" novalidate>

      
      <input type="hidden" name="return_to" value="/admin/profile.php">

      <div class="row g-3">

        <div class="col-12 col-md-6">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" value="<?= h($name) ?>" required maxlength="150" readonly>
          <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= h($email) ?>" required maxlength="190" readonly>
          <div class="field-error"><?= h($errors['email'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">NIC</label>
          <input class="form-control" value="<?= h($nic) ?>" readonly>
          <div class="field-error"></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= h($phone) ?>" maxlength="20" placeholder="07xxxxxxxx" readonly>
          <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Date of Birth</label>
          <input class="form-control" type="date" name="dob" value="<?= h($dob) ?>" readonly>
          <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Branch</label>
          <input class="form-control" value="<?= h($areaName) ?>" readonly>
          <div class="field-error"></div>
        </div>

        <div class="col-12">
          <label class="form-label d-block">Gender</label>
          <?php
            $g = strtolower(trim($gender));
            $checked = fn(string $x) => ($g === $x) ? 'checked' : '';
            if ($g !== 'male' && $g !== 'female' && $g !== 'other') $g = 'other';
          ?>
          <div class="d-flex flex-wrap gap-3">
            <label class="d-flex align-items-center gap-2 m-0">
              <input type="radio" name="gender" value="male" <?= $checked('male') ?> disabled>
              <span>Male</span>
            </label>
            <label class="d-flex align-items-center gap-2 m-0">
              <input type="radio" name="gender" value="female" <?= $checked('female') ?> disabled>
              <span>Female</span>
            </label>
            <label class="d-flex align-items-center gap-2 m-0">
              <input type="radio" name="gender" value="other" <?= $checked('other') ?> disabled>
              <span>Other</span>
            </label>
          </div>
          <div class="field-error"><?= h($errors['gender'] ?? '') ?></div>
        </div>


        <div class="col-12">
          <label class="form-label">Address</label>
          <input class="form-control" name="address" value="<?= h($address) ?>" maxlength="120" readonly>
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

  const editableInputs = [
    'input[name="name"]',
    'input[name="email"]',
    'input[name="phone"]',
    'input[name="dob"]',
    'input[name="address"]'
  ];

  function setMode(viewMode) {
    editableInputs.forEach(sel => {
      const el = form.querySelector(sel);
      if (!el) return;
      if (viewMode) el.setAttribute('readonly', 'readonly');
      else el.removeAttribute('readonly');
    });

    
    form.querySelectorAll('input[name="gender"]').forEach(r => r.disabled = viewMode);

    btnSave.style.display   = viewMode ? 'none' : 'inline-block';
    btnCancel.style.display = viewMode ? 'none' : 'inline-block';
    btnEdit.style.display   = viewMode ? 'inline-block' : 'none';
  }

  setMode(true);

  btnEdit.addEventListener('click', () => setMode(false));

  btnCancel.addEventListener('click', () => {
    window.location.reload(); // cancel => reset values
  });

  // basic client validation (server still validates)
  form.addEventListener('submit', (e) => {
    const name  = (form.querySelector('input[name="name"]').value || '').trim();
    const email = (form.querySelector('input[name="email"]').value || '').trim();

    if (name.length < 2 || email.length < 5) {
      e.preventDefault();
      alert("Please fill required fields (Name, Email).");
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>