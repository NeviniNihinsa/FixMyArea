<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);

// Load current user
$st = $pdo->prepare("SELECT user_id, name, email, nic, dob, phone, gender, area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// Areas list
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

// session flash
$errors  = $_SESSION['form_errors'] ?? [];
$success = $_SESSION['flash_success'] ?? '';
$old     = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['flash_success'], $_SESSION['old']);

function pick(array $old, array $user, string $key): string {
  if (array_key_exists($key, $old)) return (string)$old[$key];
  return (string)($user[$key] ?? '');
}

$name   = pick($old, $user, 'name');
$email  = (string)($user['email'] ?? '');
$nic    = pick($old, $user, 'nic');
$phone  = pick($old, $user, 'phone');
$dob    = pick($old, $user, 'dob');
$gender = pick($old, $user, 'gender');
$areaId = (int)($old['area_id'] ?? ($user['area_id'] ?? 0));
?>

<style>
  /* Low-fi aligned layout */
  .profile-shell{
    max-width: 980px;
    margin: 0 auto;
  }
  .profile-card{
    border-radius: 22px;
    border: 1px solid rgba(241,246,246,0.18);
    padding: 28px;
  }
  .profile-title{
    font-weight: 700;
    font-size: 1.6rem;
    margin: 0;
  }
  .avatar-wrap{
    display:flex;
    justify-content:center;
    margin-top: -10px;
    margin-bottom: 10px;
  }
  .avatar-circle{
    width: 140px;
    height: 140px;
    border-radius: 50%;
    border: 3px solid rgba(241,246,246,0.8);
    display:flex;
    align-items:center;
    justify-content:center;
    background: rgba(0,0,0,0.10);
  }
  .avatar-circle i{
    font-size: 64px;
    color: rgba(241,246,246,0.65);
  }

  .form-grid{
    display:grid;
    grid-template-columns: 140px 1fr;
    gap: 14px 18px;
    align-items:center;
    max-width: 720px;
    margin: 0 auto;
  }
  .form-grid .lbl{
    color: rgba(241,246,246,0.85);
    font-weight: 500;
  }
  .form-grid .ctrl .form-control,
  .form-grid .ctrl .form-select{
    background: rgba(255,255,255,0.06);
    border: 1px solid rgba(241,246,246,0.18);
    color: rgba(241,246,246,0.92);
  }
  .form-grid .ctrl .form-control:disabled,
  .form-grid .ctrl .form-select:disabled{
    opacity: 0.65;
  }

  .radio-row{
    display:flex;
    gap: 18px;
    align-items:center;
    flex-wrap:wrap;
  }
  .radio-row label{
    display:flex;
    gap: 8px;
    align-items:center;
    margin: 0;
    color: rgba(241,246,246,0.88);
  }

  .actions-row{
    display:flex;
    justify-content:center;
    gap: 14px;
    margin-top: 22px;
    flex-wrap: wrap;
  }


.form-select,
.form-control {
  color: rgba(241,246,246,0.95) !important;
}

/* Dropdown list items */
.form-select option {
  color: #ffffff !important;
  background-color: #606262 !important;
}

/* Disabled select still readable */
.form-select:disabled {
  opacity: 1 !important;
  color: rgba(241,246,246,0.95) !important;
}

  /* mobile */
  @media (max-width: 576px){
    .form-grid{ grid-template-columns: 1fr; }
    .form-grid .lbl{ margin-top: 6px; }
  }
</style>

<div class="container py-4 app-container">
  <div class="profile-shell">

    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card-dark profile-card">

      <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="profile-title">Profile</h2>
      </div>

      <div class="avatar-wrap">
        <div class="avatar-circle" title="Profile">
          <i class="bi bi-person"></i>
        </div>
      </div>

      <form method="POST" action="<?= BASE_URL ?>/actions/profile_update.php" id="profileForm" novalidate>

        <div class="form-grid">

          <div class="lbl">Name:</div>
          <div class="ctrl">
            <input class="form-control" name="name" value="<?= htmlspecialchars($name) ?>" required maxlength="80" disabled>
            <div class="field-error"><?= htmlspecialchars($errors['name'] ?? '') ?></div>
          </div>

          <div class="lbl">Email:</div>
          <div class="ctrl">
            <input class="form-control" value="<?= htmlspecialchars($email) ?>" disabled>
            <div class="field-error"></div>
          </div>

          <div class="lbl">NIC:</div>
          <div class="ctrl">
            <input class="form-control" name="nic" value="<?= htmlspecialchars($nic) ?>" required maxlength="20" disabled>
            <div class="field-error"><?= htmlspecialchars($errors['nic'] ?? '') ?></div>
          </div>

          <div class="lbl">Phone:</div>
          <div class="ctrl">
            <input class="form-control" name="phone" value="<?= htmlspecialchars($phone) ?>" maxlength="15" disabled>
            <div class="field-error"><?= htmlspecialchars($errors['phone'] ?? '') ?></div>
          </div>

          <div class="lbl">Date of Birth:</div>
          <div class="ctrl">
            <input type="date" class="form-control" name="dob" value="<?= htmlspecialchars($dob) ?>" disabled>
            <div class="field-error"><?= htmlspecialchars($errors['dob'] ?? '') ?></div>
          </div>

          <div class="lbl">Gender:</div>
          <div class="ctrl">
            <div class="radio-row">
              <?php
                $g = $gender;
                $maleChecked = ($g === 'male') ? 'checked' : '';
                $femaleChecked = ($g === 'female') ? 'checked' : '';
              ?>
              <label>
                <input type="radio" name="gender" value="male" <?= $maleChecked ?> disabled>
                Male
              </label>
              <label>
                <input type="radio" name="gender" value="female" <?= $femaleChecked ?> disabled>
                Female
              </label>
              <label class="d-none">
                <input type="radio" name="gender" value="other" <?= ($g === 'other') ? 'checked' : '' ?> disabled>
                Other
              </label>
            </div>
            <div class="field-error"><?= htmlspecialchars($errors['gender'] ?? '') ?></div>
          </div>

          <div class="lbl">Area:</div>
          <div class="ctrl">
            <select class="form-select" name="area_id" required disabled>
              <option value="">Area</option>
              <?php foreach ($areas as $a): ?>
                <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($a['area_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-error"><?= htmlspecialchars($errors['area_id'] ?? '') ?></div>
          </div>

        </div>

        <div class="actions-row">
          <button type="button" class="btn btn-outline-light" id="btnEdit">Edit Profile</button>
          <button type="submit" class="btn btn-light" id="btnSave" style="display:none;">Save Changes</button>
        </div>

      </form>

    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('profileForm');
  const btnEdit = document.getElementById('btnEdit');
  const btnSave = document.getElementById('btnSave');

  const setDisabled = (disabled) => {
    form.querySelectorAll('input[name="name"],input[name="nic"],input[name="phone"],input[name="dob"],select[name="area_id"]').forEach(el => {
      el.disabled = disabled;
    });
    form.querySelectorAll('input[name="gender"]').forEach(el => el.disabled = disabled);
  };

  // start locked (low-fi: view mode)
  setDisabled(true);

  btnEdit.addEventListener('click', () => {
    setDisabled(false);
    btnSave.style.display = 'inline-block';
    btnEdit.style.display = 'none';
  });

  // tiny client validation (server still validates)
  form.addEventListener('submit', (e) => {
    let ok = true;

    const name = form.name.value.trim();
    const nic  = form.nic.value.trim();
    const area = form.area_id.value;

    if (name.length < 2) ok = false;
    if (nic.length < 5) ok = false;
    if (!area) ok = false;

    if (!ok) {
      e.preventDefault();
      alert("Please fill required fields (Name, NIC, Area).");
    }
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>