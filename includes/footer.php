<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
?>

<!-- auto pushes footer to bottom -->
<footer class="mt-auto" style="border-top: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.12);">
  <div class="container-fluid px-3 py-3 d-flex flex-column flex-md-row align-items-center justify-content-between gap-2">
    <div class="small text-muted">© <?= date('Y') ?> FixMyArea</div>
    <div class="small text-muted">
      Hotline: <span style="color: var(--accent-500, #22c3a6); font-weight:600;">119</span>
    </div>
  </div>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>