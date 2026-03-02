<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Create User - FixMyArea';

$meId = (int)($_SESSION['user_id'] ?? 0);


$st = $pdo->prepare("
  SELECT u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId = isset($me['area_id']) ? (int)$me['area_id'] : null;
$myAreaName = (string)($me['area_name'] ?? '');

if (!$myAreaId) {
  http_response_code(403);
  echo "403 Forbidden (Authority has no assigned area)";
  exit;
}

$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>

<div class="app-container">
  <div class="container py-4">

    <h2 class="fw-bold mb-3">Add New User</h2>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
    <?php endif; ?>

    <div class="card-dark p-3 p-md-4">
      <form method="POST" action="<?= BASE_URL ?>/actions/authority_create_user.php" novalidate id="createUserForm">
        <div class="row g-4">

    
          <div class="col-12 col-lg-7">

            <div class="mb-3">
              <label class="form-label">Name</label>
              <input class="form-control" name="name" value="<?= htmlspecialchars($old['name'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['name'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Work Email</label>
              <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">NIC</label>
              <input class="form-control" name="nic" value="<?= htmlspecialchars($old['nic'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['nic'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Phone</label>
              <input class="form-control" name="phone" value="<?= htmlspecialchars($old['phone'] ?? '') ?>" placeholder="07XXXXXXXX">
              <div class="field-error"><?= htmlspecialchars($errors['phone'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Date of Birth</label>
              <input type="date" class="form-control" name="dob" value="<?= htmlspecialchars($old['dob'] ?? '') ?>">
              <div class="field-error"><?= htmlspecialchars($errors['dob'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label d-block">Gender</label>
              <?php $g = (string)($old['gender'] ?? ''); ?>
              <div class="d-flex gap-4">
                <label class="form-check">
                  <input class="form-check-input" type="radio" name="gender" value="male" <?= $g==='male'?'checked':'' ?>>
                  <span class="form-check-label">Male</span>
                </label>
                <label class="form-check">
                  <input class="form-check-input" type="radio" name="gender" value="female" <?= $g==='female'?'checked':'' ?>>
                  <span class="form-check-label">Female</span>
                </label>
                <label class="form-check">
                  <input class="form-check-input" type="radio" name="gender" value="other" <?= $g==='other'?'checked':'' ?>>
                  <span class="form-check-label">Other</span>
                </label>
              </div>
              <div class="field-error"><?= htmlspecialchars($errors['gender'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Address</label>
              <input class="form-control" name="address" value="<?= htmlspecialchars($old['address'] ?? '') ?>">
              <div class="field-error"><?= htmlspecialchars($errors['address'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <input type="password" class="form-control" name="password" minlength="6" required>
              <div class="field-error"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Confirm Password</label>
              <input type="password" class="form-control" name="confirm_password" minlength="6" required>
              <div class="field-error"><?= htmlspecialchars($errors['confirm_password'] ?? '') ?></div>
            </div>

          </div>

    
          <div class="col-12 col-lg-5">
            <div class="mb-3">
              <label class="form-label">Role</label>

              <select class="form-select" name="role" disabled>
                <option selected>Field Worker</option>
              </select>
              <input type="hidden" name="role" value="field worker">
            </div>

            <div class="mb-3">
              <label class="form-label">Designated Branch</label>
              <input class="form-control" value="<?= htmlspecialchars($myAreaName ?: 'My Area') ?>" readonly>
              <input type="hidden" name="area_id" value="<?= (int)$myAreaId ?>">
              <div class="field-error"><?= htmlspecialchars($errors['area_id'] ?? '') ?></div>
            </div>

            <div class="mt-4">
              <button type="submit" class="btn btn-brand px-4">Register User</button>
              <a href="<?= BASE_URL ?>/authority/manage_users.php" class="btn btn-outline-brand ms-2">Back</a>
            </div>
          </div>

        </div>
      </form>
    </div>

  </div>
</div>

<script>
(() => {
  const form = document.getElementById('createUserForm');

  form.addEventListener('submit', function(e){
    const errs = form.querySelectorAll('.field-error');
    errs.forEach(el => el.textContent = '');

    let ok = true;

    const name = form.name.value.trim();
    const email = form.email.value.trim();
    const nic = form.nic.value.trim();
    const pass = form.password.value;
    const cpass = form.confirm_password.value;

    const setErr = (inputName, msg) => {
      const input = form.querySelector(`[name="${inputName}"]`);
      const err = input ? input.parentElement.querySelector('.field-error') : null;
      if (err) err.textContent = msg;
    };

    if (!name) { setErr('name', 'Name is required.'); ok = false; }

    if (!email) { setErr('email', 'Email is required.'); ok = false; }
    else if (!/^\S+@\S+\.\S+$/.test(email)) { setErr('email', 'Enter a valid email.'); ok = false; }

    if (!nic) { setErr('nic', 'NIC is required.'); ok = false; }

    if (!pass) { setErr('password', 'Password is required.'); ok = false; }
    else if (pass.length < 6) { setErr('password', 'Minimum 6 characters.'); ok = false; }

    if (!cpass) { setErr('confirm_password', 'Confirm password is required.'); ok = false; }
    else if (pass !== cpass) { setErr('confirm_password', 'Passwords do not match.'); ok = false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>