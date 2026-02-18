<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/constants.php';

guest_only();

$page_title = 'Login - FixMyArea';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);
?>

<div class="app-container">
  <div class="container login-wrap py-4">
    <div class="row w-100 justify-content-center align-items-center g-4">

      <!-- LEFT (logo + brand) hidden on small -->
      <div class="col-lg-6 d-none d-lg-flex justify-content-center">
        <div class="text-center">
          <img src="<?= BASE_URL ?>/public/assets/img/logo.png" alt="FixMyArea" style="max-width: 260px; height:auto;">
          <h1 class="mt-3 fw-bold">FixMyArea</h1>
          <p class="text-muted">Report issues. Track progress. Improve your area.</p>
        </div>
      </div>

      <!-- RIGHT (form) -->
      <div class="col-12 col-md-8 col-lg-5">
        <div class="auth-card p-4 p-md-5">

          <h2 class="fw-semibold mb-4 text-center">Sign In</h2>

          <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
          <?php endif; ?>

          <form method="POST" action="<?= BASE_URL ?>/actions/auth_login.php" novalidate id="loginForm">

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input
                type="email"
                name="email"
                class="form-control"
                value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                required
              >
              <div class="field-error"><?= htmlspecialchars($errors['email'] ?? '') ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <input
                type="password"
                name="password"
                class="form-control"
                required
              >
              <div class="field-error"><?= htmlspecialchars($errors['password'] ?? '') ?></div>
            </div>

            <button class="btn btn-brand w-100 py-2" type="submit">Login</button>

            <div class="text-center mt-3">
              <span class="text-muted">Don't have an account?</span>
              <a href="<?= BASE_URL ?>/auth/register.php">Sign Up</a>
            </div>

          </form>

        </div>
      </div>

    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('loginForm');

  form.addEventListener('submit', function (e) {
    const email = form.email.value.trim();
    const password = form.password.value;

    let ok = true;
    form.querySelectorAll('.field-error').forEach(el => el.textContent = '');

    const emailErr = form.querySelector('[name="email"]').nextElementSibling;
    const passErr  = form.querySelector('[name="password"]').nextElementSibling;

    if (!email) { emailErr.textContent = "Email is required."; ok = false; }
    else if (!/^\S+@\S+\.\S+$/.test(email)) { emailErr.textContent = "Enter a valid email."; ok = false; }

    if (!password) { passErr.textContent = "Password is required."; ok = false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>