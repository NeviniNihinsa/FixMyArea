<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/constants.php';
?>

<footer class="mt-auto py-3" style="background:#fff8ee; border-top: 2px solid rgba(255,145,76,0.15);">
  <div class="container-fluid px-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-1">
      <div class="small" style="color:var(--muted-400);">© <?= date('Y') ?> Fixly. All rights reserved.</div>
      <div class="small" style="color:var(--muted-400);">Built for smarter building management.</div>
    </div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>