<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
guest_only();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php';


$errors = $_SESSION['form_errors'] ?? [];
$old = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);

$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll();
?>

<div class="container auth-wrap py-4">
  <div class="row w-100 justify-content-center align-items-center g-4">

    <div class="col-12 col-lg-9">
      <div class="auth-card p-4 p-md-5">

        <h2 class="fw-semibold mb-4 text-center">Register</h2>

        <?php if (!empty($errors['general'])): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/actions/auth_register.php" novalidate id="regForm">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Name *</label>
              <input name="name" class="form-control" value="<?= htmlspecialchars($old['name'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['name'] ?? '') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">NIC *</label>
              <input name="nic" class="form-control" value="<?= htmlspecialchars($old['nic'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['nic'] ?? '') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Email *</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Date of Birth</label>
              <input type="date" name="dob" class="form-control" value="<?= htmlspecialchars($old['dob'] ?? '') ?>">
              <div class="field-error"><?= htmlspecialchars($errors['dob'] ?? '') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input name="phone" class="form-control" value="<?= htmlspecialchars($old['phone'] ?? '') ?>">
              <div class="field-error"></div>
            </div>

            <div class="col-md-6">
              <label class="form-label"> Branch </label>
              <select name="area_id" class="form-select" required>
                <option value="0">Select Branch </option>
                <?php foreach ($areas as $a): ?>
                  <option value="<?= (int)$a['area_id'] ?>" <?= ((int)($old['area_id'] ?? 0) === (int)$a['area_id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['area_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <div class="field-error"><?= htmlspecialchars($errors['area_id'] ?? '') ?></div>
            </div>

            <div class="col-12">
              <label class="form-label"> Apartment Number </label>
              <input name="address" class="form-control" value="<?= htmlspecialchars($old['address'] ?? '') ?>">
              <div class="field-error"></div>
            </div>

            <div class="col-md-6">
              <label class="form-label d-block">Gender</label>
              <?php $g = $old['gender'] ?? ''; ?>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="gender" value="male" <?= $g==='male'?'checked':'' ?>>
                <label class="form-check-label">Male</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="gender" value="female" <?= $g==='female'?'checked':'' ?>>
                <label class="form-check-label">Female</label>
              </div>
              <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="gender" value="other" <?= $g==='other'?'checked':'' ?>>
                <label class="form-check-label">Other</label>
              </div>
              <div class="field-error"><?= htmlspecialchars($errors['gender'] ?? '') ?></div>
            </div>

            <div class="col-md-6"></div>

            <div class="col-md-6">
              <label class="form-label">Password *</label>
              <input type="password" name="password" class="form-control" required minlength="6">
              <div class="field-error"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Confirm Password *</label>
              <input type="password" name="confirm_password" class="form-control" required minlength="6">
              <div class="field-error"><?= htmlspecialchars($errors['confirm_password'] ?? '') ?></div>
            </div>

          </div>

          <button class="btn btn-brand w-100 py-2 mt-4" type="submit">Sign Up</button>

          <div class="text-center mt-3">
            <span class="text-muted">Already have an account?</span>
            <a href="<?= BASE_URL ?>/auth/login.php">Sign In</a>
          </div>

        </form>

      </div>
    </div>

  </div>
</div>

<script>
(() => {
  const form = document.getElementById('regForm');

  function setErr(name, msg){
    const el = form.querySelector(`[name="${name}"]`);
    if(!el) return;
    const err = el.closest('.col-md-6, .col-12')?.querySelector('.field-error') || el.parentElement.querySelector('.field-error');
    if(err) err.textContent = msg;
  }

  form.addEventListener('submit', function(e){
    let ok = true;
    form.querySelectorAll('.field-error').forEach(el => el.textContent='');

    const name = form.name.value.trim();
    const nic = form.nic.value.trim();
    const email = form.email.value.trim();
    const area = parseInt(form.area_id.value, 10);
    const pass = form.password.value;
    const conf = form.confirm_password.value;

    if(!name){ setErr('name','Name is required.'); ok=false; }
    if(!nic){ setErr('nic','NIC is required.'); ok=false; }
    else if(!/^(\d{9}[vVxX]|\d{12})$/.test(nic)){ setErr('nic','Enter valid NIC.'); ok=false; }

    if(!email){ setErr('email','Email is required.'); ok=false; }
    else if(!/^\S+@\S+\.\S+$/.test(email)){ setErr('email','Enter valid email.'); ok=false; }

    if(!area || area<=0){ setErr('area_id','Select your area.'); ok=false; }

    if(!pass){ setErr('password','Password is required.'); ok=false; }
    else if(pass.length < 6){ setErr('password','Minimum 6 characters.'); ok=false; }

    if(!conf){ setErr('confirm_password','Confirm password is required.'); ok=false; }
    else if(pass !== conf){ setErr('confirm_password','Passwords do not match.'); ok=false; }

    if(!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
