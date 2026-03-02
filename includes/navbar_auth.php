<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
?>

<nav class="navbar navbar-expand-lg navbar-dark">
  <div class="container-fluid px-3">

    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= BASE_URL ?>/auth/login.php">
      
      <img src="<?= BASE_URL ?>/public/assets/img/logo3.png" alt="FixMyArea"
          style="height:40px;width:auto;object-fit:contain;"
           onerror="this.style.display='none'">
    </a>

    <div class="ms-auto d-flex gap-3">
      <a class="nav-link text-light" href="<?= BASE_URL ?>/auth/login.php">Login</a>
      <a class="nav-link text-light" href="<?= BASE_URL ?>/auth/register.php">Register</a>
    </div>

  </div>
</nav>