<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Add User - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);

$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="container py-4 app-container">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="fw-bold mb-0">Add User</h2>
    <a class="btn btn-outline-light btn-sm" href="<?= BASE_URL ?>/admin/manage_users.php">Back</a>
  </div>

  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= h($errors['general']) ?></div>
  <?php endif; ?>

  <div class="card-dark p-4">
    <form method="POST" action="<?= BASE_URL ?>/actions/admin_create_user.php" id="createUserForm" novalidate>

      <div class="row g-3">

        <div class="col-12 col-md-6">
          <label class="form-label">Full Name</label>
          <input type="text" name="name" class="form-control" required maxlength="150"
                 value="<?= h($old['name'] ?? '') ?>">
          <div class="field-error"><?= h($errors['name'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required maxlength="190"
                 value="<?= h($old['email'] ?? '') ?>">
          <div class="field-error"><?= h($errors['email'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">NIC</label>
          <input type="text" name="nic" class="form-control" required maxlength="20"
                 value="<?= h($old['nic'] ?? '') ?>" placeholder="123456789V or 200012345678">
          <div class="field-error"><?= h($errors['nic'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Phone</label>
          <input type="text" name="phone" class="form-control" maxlength="20"
                 value="<?= h($old['phone'] ?? '') ?>" placeholder="07XXXXXXXX">
          <div class="field-error"><?= h($errors['phone'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Date of Birth</label>
          <input type="date" name="dob" class="form-control"
                 value="<?= h($old['dob'] ?? '') ?>">
          <div class="field-error"><?= h($errors['dob'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Gender</label>
          <select name="gender" class="form-select">
            <option value="">Select</option>
            <?php
              $g = (string)($old['gender'] ?? '');
              foreach (['male'=>'Male','female'=>'Female','other'=>'Other'] as $k=>$label):
            ?>
              <option value="<?= $k ?>" <?= $g===$k?'selected':'' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-error"><?= h($errors['gender'] ?? '') ?></div>
        </div>

        <div class="col-12">
          <label class="form-label">Address</label>
          <input type="text" name="address" class="form-control" maxlength="255"
                 value="<?= h($old['address'] ?? '') ?>">
          <div class="field-error"><?= h($errors['address'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Area</label>
          <select name="area_id" class="form-select">
            <option value="">Select area</option>
            <?php $aid = (string)($old['area_id'] ?? ''); ?>
            <?php foreach ($areas as $a): ?>
              <option value="<?= (int)$a['area_id'] ?>" <?= $aid===(string)$a['area_id']?'selected':'' ?>>
                <?= h($a['area_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field-error"><?= h($errors['area_id'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Role</label>
          <select name="role" class="form-select" required>
            <option value="">Select role</option>
            <?php $r = (string)($old['role'] ?? ''); ?>
            <option value="local authority" <?= $r==='local authority'?'selected':'' ?>>Local Authority</option>
            <option value="field worker" <?= $r==='field worker'?'selected':'' ?>>Field Worker</option>
            <!-- (Optional) allow admin create citizens too -->
            <option value="citizen" <?= $r==='citizen'?'selected':'' ?>>Citizen</option>
          </select>
          <div class="field-error"><?= h($errors['role'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required minlength="6">
          <div class="field-error"><?= h($errors['password'] ?? '') ?></div>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Status</label>
          <?php $st = (string)($old['status'] ?? 'active'); ?>
          <select name="status" class="form-select" required>
            <option value="active" <?= $st==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $st==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
          <div class="field-error"><?= h($errors['status'] ?? '') ?></div>
        </div>

      </div>

      <div class="mt-4 d-flex justify-content-end gap-2">
        <a class="btn btn-outline-light" href="<?= BASE_URL ?>/admin/manage_users.php">Cancel</a>
        <button class="btn btn-brand" type="submit">Save</button>
      </div>

    </form>
  </div>

</div>

<script>
(() => {
  const form = document.getElementById('createUserForm');

  function setErr(name, msg){
    const el = form.querySelector(`[name="${name}"]`);
    if (!el) return;
    const err = el.parentElement.querySelector('.field-error');
    if (err) err.textContent = msg;
  }

  form.addEventListener('submit', (e) => {
    let ok = true;
    form.querySelectorAll('.field-error').forEach(x => x.textContent = '');

    const name = form.name.value.trim();
    const email = form.email.value.trim();
    const nic = form.nic.value.trim();
    const role = form.role.value;
    const password = form.password.value;

    if (!name) { setErr('name', 'Name is required.'); ok=false; }
    if (!email) { setErr('email', 'Email is required.'); ok=false; }
    else if (!/^\S+@\S+\.\S+$/.test(email)) { setErr('email', 'Enter a valid email.'); ok=false; }

    if (!nic) { setErr('nic', 'NIC is required.'); ok=false; }
    else if (!/^(\d{9}[vVxX]|\d{12})$/.test(nic)) { setErr('nic', 'NIC must be 9 digits + V/X OR 12 digits.'); ok=false; }

    if (!role) { setErr('role', 'Role is required.'); ok=false; }

    if (!password) { setErr('password', 'Password is required.'); ok=false; }
    else if (password.length < 6) { setErr('password', 'Password must be at least 6 characters.'); ok=false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>