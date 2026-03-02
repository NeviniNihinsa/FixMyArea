<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/constants.php';
?>

<footer class="mt-auto py-3" style="background:#fff8ee; border-top: 2px solid rgba(255,145,76,0.15);">
  <div class="container">

    <!-- Top row: Logo + support info horizontal -->
    <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mb-2">
      <img src="<?= BASE_URL ?>/public/assets/img/logo3.png" alt="Fixly" style="height:36px;width:auto;">

      <div class="d-flex flex-wrap gap-3 align-items-center justify-content-center small" style="color:var(--muted-400);">
        <span>📞 <strong style="color:var(--accent-600);">+94 11 234 5678</strong></span>
        <span style="color:var(--border);">|</span>
        <span>✉️ support@fixly.lk</span>
        <span style="color:var(--border);">|</span>
        <span>🕐 Mon–Fri, 8am–6pm</span>
        <span style="color:var(--border);">|</span>
        <span>Emergencies: 24/7</span>
      </div>
    </div>

    <!-- Bottom bar -->
    <div class="border-top pt-2 d-flex flex-column flex-md-row justify-content-between align-items-center gap-1"
         style="border-color: rgba(255,145,76,0.15) !important;">
      <div class="small" style="color:var(--muted-400);">© <?= date('Y') ?> Fixly. All rights reserved.</div>
      <div class="small" style="color:var(--muted-400);">Built for smarter building management.</div>
    </div>

  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>