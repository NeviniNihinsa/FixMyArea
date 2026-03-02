<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
$page_title = 'Fixly – Building Maintenance System';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar_auth.php';
?>

<style>
  /* ── Hero ── */
  .landing-wrap {
    min-height: calc(100vh - 140px);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 0 24px;
  }
  .landing-card {
    width: 100%;
    max-width: 700px;
    padding: 52px 32px;
    text-align: center;
  }
  .landing-logo-wrap {
    display: flex;
    justify-content: center;
    margin-bottom: 20px;
  }
  .landing-logo {
    max-width: 220px;
    width: 100%;
    height: auto;
    object-fit: contain;
  }
  .landing-headline {
    font-size: clamp(1.5rem, 3vw, 2.2rem);
    font-weight: 700;
    color: var(--text-100);
    margin-bottom: 10px;
  }
  .landing-sub {
    color: var(--muted-400);
    font-size: 1rem;
    margin-bottom: 28px;
    line-height: 1.6;
  }
  .landing-actions {
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
  }
  .btn-landing-primary {
    background: linear-gradient(135deg, var(--accent-300), var(--accent-600));
    border: none;
    color: #1a1005;
    font-weight: 700;
    border-radius: 14px;
    padding: 12px 44px;
    min-width: 150px;
    font-size: 1rem;
    box-shadow: 0 4px 14px rgba(255,145,76,0.3);
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
  }
  .btn-landing-primary:hover {
    box-shadow: 0 6px 20px rgba(255,145,76,0.45);
    color: #1a1005;
    transform: translateY(-1px);
  }
  .btn-landing-outline {
    border: 1.5px solid var(--accent-500);
    color: var(--accent-600);
    font-weight: 600;
    border-radius: 14px;
    padding: 12px 44px;
    min-width: 150px;
    font-size: 1rem;
    background: transparent;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
  }
  .btn-landing-outline:hover {
    background: rgba(255,173,82,0.10);
    color: var(--accent-600);
  }

  /* ── Sections ── */
  .section { padding: 64px 0; }
  .section-alt { background: #fff8ee; }
  .section-title {
    font-size: 1.7rem;
    font-weight: 700;
    color: var(--text-100);
    margin-bottom: 8px;
  }
  .section-sub {
    color: var(--muted-400);
    margin-bottom: 40px;
    font-size: 0.97rem;
  }

  /* ── How It Works ── */
  .step-card {
    text-align: center;
    padding: 32px 20px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: 0 2px 16px rgba(255,145,76,0.06);
    height: 100%;
  }
  .step-number {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--accent-300), var(--accent-600));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; font-weight: 700;
    color: #1a1005;
    margin: 0 auto 16px;
    box-shadow: 0 4px 12px rgba(255,145,76,0.25);
  }
  .step-title { font-weight: 600; color: var(--text-100); margin-bottom: 8px; font-size: 1.05rem; }
  .step-desc  { color: var(--muted-400); font-size: 0.9rem; line-height: 1.6; }

  /* ── Features ── */
  .feature-card {
    display: flex;
    gap: 16px;
    align-items: flex-start;
    padding: 24px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(255,145,76,0.05);
    height: 100%;
  }
  .feature-icon {
    font-size: 1.8rem;
    flex-shrink: 0;
    margin-top: 2px;
  }
  .feature-title { font-weight: 600; color: var(--text-100); margin-bottom: 4px; }
  .feature-desc  { color: var(--muted-400); font-size: 0.88rem; line-height: 1.6; margin: 0; }

  /* ── Categories ── */
  .cat-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 100px;
    padding: 8px 18px;
    font-size: 0.9rem;
    font-weight: 500;
    color: var(--text-700);
    box-shadow: 0 1px 6px rgba(255,145,76,0.06);
  }
  .cat-pill span { font-size: 1rem; }

  /* ── Who Uses It ── */
  .role-card {
    text-align: center;
    padding: 28px 20px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: 0 2px 16px rgba(255,145,76,0.06);
    height: 100%;
  }
  .role-icon { font-size: 2.4rem; margin-bottom: 12px; display: block; }
  .role-title { font-weight: 700; color: var(--text-100); margin-bottom: 6px; font-size: 1rem; }
  .role-desc  { color: var(--muted-400); font-size: 0.875rem; line-height: 1.6; margin: 0; }

  /* ── CTA Banner ── */
  .cta-banner {
    background: linear-gradient(135deg, var(--accent-300), var(--accent-600));
    border-radius: 24px;
    padding: 52px 32px;
    text-align: center;
    box-shadow: 0 8px 32px rgba(255,145,76,0.25);
  }
  .cta-banner h2 { font-weight: 700; color: #1a1005; margin-bottom: 10px; }
  .cta-banner p  { color: rgba(26,16,5,0.7); margin-bottom: 28px; }
  .btn-cta {
    background: #1a1005;
    color: var(--accent-300);
    font-weight: 700;
    border-radius: 14px;
    padding: 12px 44px;
    font-size: 1rem;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s;
  }
  .btn-cta:hover { background: #2e1a08; color: var(--accent-300); transform: translateY(-1px); }
</style>

<!-- ═══════════════════════════════════════════
     HERO
════════════════════════════════════════════ -->
<div class="container landing-wrap app-container">
  <div class="card-dark landing-card">
    <div class="landing-logo-wrap">
      <img src="<?= BASE_URL ?>/public/assets/img/logo3.png"
           alt="Fixly Logo" class="landing-logo">
    </div>
    <h1 class="landing-headline">Smart Maintenance for Your Building</h1>
    <p class="landing-sub">
      Report, track and resolve maintenance issues in your apartment or housing scheme —
      fast, transparent, and fully digital.
    </p>
    <div class="landing-actions">
      <a class="btn-landing-primary" href="<?= BASE_URL ?>/auth/login.php">Login</a>
      <a class="btn-landing-outline" href="<?= BASE_URL ?>/auth/register.php">Sign Up</a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════
     HOW IT WORKS
════════════════════════════════════════════ -->
<section class="section section-alt" id="how-it-works">
  <div class="container text-center">
    <h2 class="section-title">How It Works</h2>
    <p class="section-sub">Three simple steps from issue to resolution</p>

    <div class="row g-4 justify-content-center">
      <div class="col-12 col-md-4">
        <div class="step-card">
          <div class="step-number">1</div>
          <div class="step-title">Report the Issue</div>
          <p class="step-desc">Tenants log in and submit a maintenance request with a description, photo, and location within the building.</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="step-card">
          <div class="step-number">2</div>
          <div class="step-title">Assign a Worker</div>
          <p class="step-desc">Management reviews the issue, assigns the right field worker, and tracks progress in real time.</p>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="step-card">
          <div class="step-number">3</div>
          <div class="step-title">Resolved & Rated</div>
          <p class="step-desc">Once fixed, the worker uploads proof. Tenants get notified and can rate the quality of the repair.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════
     FEATURES
════════════════════════════════════════════ -->
<section class="section">
  <div class="container">
    <div class="text-center">
      <h2 class="section-title">Why Fixly?</h2>
      <p class="section-sub">Everything your building needs in one platform</p>
    </div>

    <div class="row g-4">
      <div class="col-12 col-md-6">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-camera-fill"></i></div>
          <div>
            <div class="feature-title">Photo Evidence</div>
            <p class="feature-desc">Submit before & after photos with every report. Workers upload proof-of-fix so nothing is left unverified.</p>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-bell-fill"></i></div>
          <div>
            <div class="feature-title">Real-Time Notifications</div>
            <p class="feature-desc">Tenants receive instant updates whenever their issue status changes — from pending to resolved.</p>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
          <div>
            <div class="feature-title">Analytics Dashboard</div>
            <p class="feature-desc">Management gets full visibility with KPI dashboards — resolution times, issue trends, and worker performance.</p>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-6">
        <div class="feature-card">
          <div class="feature-icon"><i class="bi bi-star-fill"></i></div>
          <div>
            <div class="feature-title">Ratings & Feedback</div>
            <p class="feature-desc">Tenants rate every completed job, keeping maintenance standards high and workers accountable.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════
     ISSUE CATEGORIES
════════════════════════════════════════════ -->
<section class="section section-alt">
  <div class="container text-center">
    <h2 class="section-title">What We Handle</h2>
    <p class="section-sub">From plumbing to pest control — all building issues in one place</p>

    <div class="d-flex flex-wrap gap-3 justify-content-center">
      <div class="cat-pill">Plumbing &amp; Water</div>
      <div class="cat-pill">Electrical &amp; Power</div>
      <div class="cat-pill">Lift / Elevator</div>
      <div class="cat-pill">Cleaning &amp; Hygiene</div>
      <div class="cat-pill">Building &amp; Structure</div>
      <div class="cat-pill">Pest Control</div>
      <div class="cat-pill">Security &amp; CCTV</div>
      <div class="cat-pill">Air Conditioning</div>
      <div class="cat-pill">Landscaping</div>
      <div class="cat-pill">Fire Safety</div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════
     WHO USES IT
════════════════════════════════════════════ -->
<section class="section">
  <div class="container">
    <div class="text-center">
      <h2 class="section-title">Built for Everyone in Your Building</h2>
      <p class="section-sub">Different roles, one unified system</p>
    </div>

    <div class="row g-4">
      <div class="col-6 col-md-3">
        <div class="role-card">
          <span class="role-icon"><i class="bi bi-house-door-fill"></i></span>
          <div class="role-title">Tenant</div>
          <p class="role-desc">Report issues, track progress, and rate completed work from any device.</p>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="role-card">
          <span class="role-icon"><i class="bi bi-building-fill"></i></span>
          <div class="role-title">Property Management</div>
          <p class="role-desc">Review incoming issues, assign workers, and monitor overall building health.</p>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="role-card">
          <span class="role-icon"><i class="bi bi-person-standing"></i></span>
          <div class="role-title">Maintenance Technician</div>
          <p class="role-desc">View assigned tasks, update job status, and upload proof-of-fix photos.</p>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="role-card">
          <span class="role-icon"><i class="bi bi-person-workspace"></i></span>
          <div class="role-title">Admin</div>
          <p class="role-desc">Full system access — manage users, settings, analytics, and reports.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════
     CTA BANNER
════════════════════════════════════════════ -->
<section class="section">
  <div class="container">
    <div class="cta-banner">
      <h2>Ready to fix your building faster?</h2>
      <p>Join Fixly and bring transparency to your building's maintenance today.</p>
      <a class="btn-cta" href="<?= BASE_URL ?>/auth/register.php">Get Started Free</a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>