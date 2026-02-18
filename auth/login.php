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

<!-- wrapper makes footer stick correctly -->
<div class="app-container">
  <div class="container login-wrap py-4">
    <div class="row w-100 justify-content-center align-items-center g-4">


      <!-- RIGHT (form) -->
      <div class="col-12 col-md-8 col-lg-5">
        <div class="auth-card p-4 p-md-5">

          <h2 class="fw-semibold mb-4 text-center">Sign In</h2>

          <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
          <?php endif; ?>

          <form method="POST" action="<?= BASE_URL ?>/actions/auth_login.php" novalidate id="loginForm">

            <div class="mb-3">
              <label class="form-label" for="email">Email</label>
              <input
                id="email"
                type="email"
                name="email"
                class="form-control"
                value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                required
                autocomplete="username"
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
  if (!form) return;

  // Show/Hide password
  const pw = document.getElementById('password');
  const btn = document.getElementById('togglePasswordBtn');
  if (pw && btn) {
    btn.addEventListener('click', () => {
      const isHidden = pw.type === 'password';
      pw.type = isHidden ? 'text' : 'password';
      btn.textContent = isHidden ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
    });
  }

  // Client-side validation (fixed to work with input-group)
  function setFieldError(inputName, msg) {
    const input = form.querySelector(`[name="${inputName}"]`);
    if (!input) return;
    const wrap = input.closest('.mb-3');
    const err = wrap ? wrap.querySelector('.field-error') : null;
    if (err) err.textContent = msg;
  }

  form.addEventListener('submit', function (e) {
    const email = (form.email?.value || '').trim();
    const password = form.password?.value || '';

    let ok = true;
    form.querySelectorAll('.field-error').forEach(el => el.textContent = '');

    if (!email) { setFieldError('email', "Email is required."); ok = false; }
    else if (!/^\S+@\S+\.\S+$/.test(email)) { setFieldError('email', "Enter a valid email."); ok = false; }

    if (!password) { setFieldError('password', "Password is required."); ok = false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>