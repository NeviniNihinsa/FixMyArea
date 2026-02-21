<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
$page_title = 'FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php';
?>

<style>
  
  .landing-wrap{
    min-height: calc(100vh - 140px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding: 24px 0;
  }

  .landing-card{
    width: 100%;
    max-width: 980px;
    padding: 48px 18px;
    text-align:center;
  }

 .landing-logo-wrap{
  display:flex;
  justify-content:center;
  margin-bottom: 24px;
}

.landing-logo{
  max-width: 280px;  
  width: 100%;
  height: auto;
  object-fit: contain;
}

  .landing-title{
    font-weight: 700;
    letter-spacing: 0.2px;
    font-size: clamp(2rem, 4vw, 3.2rem);
    margin-bottom: 12px;
  }

  .landing-sub{
    color: var(--text-300);
    font-weight: 500;
    margin-bottom: 8px;
  }

  .landing-dot{
    color: var(--text-300);
    margin: 8px 0 28px 0;
    font-size: 20px;
    line-height: 1;
  }

  .landing-actions{
    display:flex;
    gap: 28px;
    justify-content:center;
    flex-wrap:wrap;
    margin-top: 12px;
  }


  .btn-landing{
    background: rgba(241,246,246,0.92);
    border: 1px solid rgba(241,246,246,0.55);
    color: #0b1f23;
    font-weight: 700;
    border-radius: 14px;
    padding: 10px 42px;
    min-width: 170px;
  }
  .btn-landing:hover{
    background: rgba(241,246,246,1);
    color: #07181b;
  }

  .landing-forgot{
    display:inline-block;
    margin-top: 16px;
    color: rgba(241,246,246,0.85);
    text-decoration: underline;
  }
  .landing-forgot:hover{
    color: var(--accent-500);
  }
</style>

<div class="container landing-wrap app-container">
  <div class="card-dark landing-card">

    <div class="landing-logo-wrap">
  <img 
    src="<?= BASE_URL ?>/public/assets/img/logo2.png" 
    alt="FixMyArea Logo"
    class="landing-logo"
  >
</div>

    <div class="landing-sub">See an issue? Report it and track progress</div>
    <div class="landing-dot">•</div>

    <div class="landing-actions">
      <a class="btn btn-landing" href="<?= BASE_URL ?>/auth/login.php">Login</a>
      <a class="btn btn-landing" href="<?= BASE_URL ?>/auth/register.php">Sign Up</a>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>