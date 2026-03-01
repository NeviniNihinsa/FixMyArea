<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$isLoggedIn = !empty($_SESSION['user_id']);

// normalize role
$roleRaw = (string)($_SESSION['role'] ?? 'guest');
$role = strtolower(trim($roleRaw));

// support old role names too
if ($role === 'local authority') $role = 'authority';
if ($role === 'field worker')    $role = 'worker';

$userId   = (int)($_SESSION['user_id'] ?? 0);
$userName = $_SESSION['name'] ?? '';

function nav_active(string $needle): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return (strpos($uri, $needle) !== false) ? 'active fw-semibold' : '';
}

// Home link depends on role
$homeLink = BASE_URL . '/auth/login.php';
if ($role === 'citizen')   $homeLink = BASE_URL . '/citizen/home.php';
if ($role === 'worker')    $homeLink = BASE_URL . '/worker/home.php';
if ($role === 'authority') $homeLink = BASE_URL . '/authority/home.php';
if ($role === 'admin')     $homeLink = BASE_URL . '/admin/home.php';

// menus by role
$menus = [
    'citizen' => [
        ['Home', 'citizen/home.php'],
        ['Report an Issue', 'citizen/report_issue.php'],
        ['Track Issue', 'citizen/track_issue.php'],
        ['Community', 'citizen/community.php'],
        ['Profile', 'citizen/profile.php'],
    ],
    'worker' => [
        ['Home', 'worker/home.php'],
        ['Assigned Issues', 'worker/assigned_issues.php'],
        ['Community', 'worker/community.php'],
        ['Profile', 'worker/profile.php'],
    ],
    'authority' => [
        ['Home', 'authority/home.php'],
        ['Area Issues', 'authority/area_issues.php'],
        ['Manage Users', 'authority/manage_users.php'],
        ['Community', 'authority/community.php'],
        ['Profile', 'authority/profile.php'],
    ],
    'admin' => [
        ['Home', 'admin/home.php'],
        ['Issues', 'admin/manage_issues.php'],
        ['Users', 'admin/manage_users.php'],
        ['Analytics', 'admin/analytics.php'],
        ['Community', 'admin/community.php'],
        ['Profile', 'admin/profile.php'],
    ],
];

// Notifications: citizen + worker + authority (admin optional; keep off unless you build it)
$notifLink = '';
if ($role === 'citizen')   $notifLink = BASE_URL . '/citizen/notifications.php';
if ($role === 'worker')    $notifLink = BASE_URL . '/worker/notifications.php';
if ($role === 'authority') $notifLink = BASE_URL . '/authority/notifications.php'; // ✅ add

// unread count (safe)
$unreadCount = 0;
if ($isLoggedIn && $notifLink !== '' && $userId > 0) {
    try {
        require_once __DIR__ . '/../config/db.php';
        //  FIX table name: notifications (not notification)
        $st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $st->execute([$userId]);
        $unreadCount = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        $unreadCount = 0;
    }
}
?>

<nav class="navbar navbar-expand-lg navbar-dark"
     style="background: rgba(0,0,0,0.15); border-bottom: 1px solid rgba(255,255,255,0.08);">
  <div class="container-fluid px-3">

    <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $homeLink ?>">
      <img src="<?= BASE_URL ?>/public/assets/img/logo2.png"
           alt="FixMyArea"
           style="height:40px;width:auto;object-fit:contain;"
           onerror="this.style.display='none'">
    </a>

    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavbar"
            aria-controls="mainNavbar" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="mainNavbar">
      <ul class="navbar-nav ms-auto align-items-lg-center">

        <?php if (!$isLoggedIn): ?>
          <li class="nav-item"><a class="nav-link <?= nav_active('/auth/login.php') ?>" href="<?= BASE_URL ?>/auth/login.php">Login</a></li>
          <li class="nav-item"><a class="nav-link <?= nav_active('/auth/register.php') ?>" href="<?= BASE_URL ?>/auth/register.php">Register</a></li>

        <?php else: ?>

          <?php $i = 0; foreach (($menus[$role] ?? []) as [$label, $path]): ?>
            <?php if ($i > 0): ?>
              <li class="nav-item d-none d-lg-flex align-items-center px-1 text-muted">|</li>
            <?php endif; ?>
            <li class="nav-item">
              <a class="nav-link <?= nav_active('/' . $path) ?>" href="<?= BASE_URL . '/' . $path ?>">
                <?= htmlspecialchars($label) ?>
              </a>
            </li>
          <?php $i++; endforeach; ?>

          <li class="nav-item d-none d-lg-flex align-items-center px-2 text-muted">|</li>

          <!-- Bell for citizen + worker + authority -->
          <?php if ($notifLink !== ''): ?>
            <li class="nav-item d-flex align-items-center ms-lg-2 mt-2 mt-lg-0">
              <a class="nav-link position-relative px-2"
                 href="<?= $notifLink ?>" title="Notifications" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>

                <?php if ($unreadCount > 0): ?>
                  <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                    <?= $unreadCount ?>
                  </span>
                <?php endif; ?>
              </a>
            </li>

            <li class="nav-item d-none d-lg-flex align-items-center px-2 text-muted">|</li>
          <?php endif; ?>

          <li class="nav-item d-flex align-items-center gap-2 ms-lg-2 mt-2 mt-lg-0">
            <a class="btn btn-sm btn-outline-light" href="<?= BASE_URL ?>/auth/logout.php">Logout</a>
          </li>

        <?php endif; ?>

      </ul>
    </div>
  </div>
</nav>