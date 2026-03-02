<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);

$page_title = 'Profile - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

$st = $pdo->prepare("
  SELECT user_id, name, email, nic, phone, dob, gender, address, area_id
  FROM users
  WHERE user_id=? LIMIT 1
");
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}


$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);


$errors  = $_SESSION['form_errors'] ?? [];
$flash   = $_SESSION['flash'] ?? null;
$old     = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['flash'], $_SESSION['old']);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pick(array $old, array $user, string $key): string {
  if (array_key_exists($key, $old)) return (string)$old[$key];
  return (string)($user[$key] ?? '');
}

$name    = pick($old, $user, 'name');
$email   = (string)($user['email'] ?? '');
$nic     = pick($old, $user, 'nic');
$phone   = pick($old, $user, 'phone');
$dob     = pick($old, $user, 'dob');
$gender  = strtolower(trim(pick($old, $user, 'gender')));
$address = pick($old, $user, 'address');
$areaId  = (int)($old['area_id'] ?? ($user['area_id'] ?? 0));

$branchName = '—';
foreach ($areas as $a) {
  if ((int)$a['area_id'] === $areaId) { $branchName = (string)$a['area_name']; break; }
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

    <form method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" id="profileForm" novalidate>
      <div class="row g-3">

        <div class="col-12 col-md-6">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" value="<?= h($name) ?>" required maxlength="80" readonly>
          <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" value="<?= h($email) ?>" readonly>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">NIC</label>
          <input class="form-control" name="nic" value="<?= h($nic) ?>" required maxlength="20" readonly>
          <div class="field-error"><?= h($errors['nic'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= h($phone) ?>" maxlength="15" readonly>
          <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Date of Birth</label>
          <input type="date" class="form-control" name="dob" value="<?= h($dob) ?>" readonly>
          <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Gender</label>
          <div class="d-flex gap-3 align-items-center flex-wrap">
            <?php
              $g = $gender;
              $maleChecked   = ($g === 'male') ? 'checked' : '';
              $femaleChecked = ($g === 'female') ? 'checked' : '';
              $otherChecked  = ($g === 'other') ? 'checked' : '';
              if ($g !== 'male' && $g !== 'female' && $g !== 'other') $otherChecked = 'checked';
            ?>
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
          <label class="form-label">Branch</label>
          <input class="form-control" id="branchText" value="<?= h($branchName) ?>" readonly>

          <select class="form-select d-none" name="area_id" id="branchSelect" required>
            <option value="">Select Branch</option>
            <?php foreach ($areas as $a): ?>
              <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
                <?= h((string)$a['area_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>

          <div class="field-error"><?= h($errors['area_id'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
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

  const branchText = document.getElementById('branchText');
  const branchSelect = document.getElementById('branchSelect');

  const editable = ['name','nic','phone','dob','address'];

  function setMode(viewMode) {
    editable.forEach(n => {
      const el = form.querySelector(`[name="${n}"]`);
      if (!el) return;
      if (viewMode) el.setAttribute('readonly','readonly');
      else el.removeAttribute('readonly');
    });

    form.querySelectorAll('input[name="gender"]').forEach(r => {
      r.disabled = viewMode;
    });

    if (viewMode) {
      branchText.classList.remove('d-none');
      branchSelect.classList.add('d-none');
    } else {
      branchText.classList.add('d-none');
      branchSelect.classList.remove('d-none');
    }

    btnSave.style.display   = viewMode ? 'none' : 'inline-block';
    btnCancel.style.display = viewMode ? 'none' : 'inline-block';
    btnEdit.style.display   = viewMode ? 'inline-block' : 'none';
  }

  setMode(true);

  btnEdit.addEventListener('click', () => setMode(false));

  btnCancel.addEventListener('click', () => {
    
    window.location.reload();
  });

  form.addEventListener('submit', (e) => {
    const name = (form.querySelector('[name="name"]').value || '').trim();
    const nic  = (form.querySelector('[name="nic"]').value || '').trim();
    const area = branchSelect.value;

    if (name.length < 2 || nic.length < 5 || !area) {
      e.preventDefault();
      alert("Please fill required fields (Name, NIC, Branch).");
      return;
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>