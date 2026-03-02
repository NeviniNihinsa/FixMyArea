<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Allow all roles that can see notifications.
 * (Your authority also has notifications now)
 */
require_roles(['citizen','worker','authority','admin']);

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleRaw = (string)($_SESSION['role'] ?? 'guest');
$role = strtolower(trim($roleRaw));
if ($role === 'local authority') $role = 'authority';
if ($role === 'field worker')    $role = 'worker';

/** Only accept POST (prevents direct URL open) */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: " . BASE_URL);
    exit;
}

$mode = (string)($_POST['mode'] ?? 'one');

/**
 * Redirect back safely:
 * - Prefer the page where user clicked (HTTP_REFERER)
 * - Fallback to role-based notifications page
 */
$back = $_SERVER['HTTP_REFERER'] ?? '';
if ($back === '') {
    if ($role === 'worker') {
        $back = BASE_URL . '/worker/notifications.php';
    } elseif ($role === 'authority') {
        $back = BASE_URL . '/authority/notifications.php';
    } elseif ($role === 'admin') {
        $back = BASE_URL . '/admin/notifications.php';
    } else {
        $back = BASE_URL . '/citizen/notifications.php';
    }
}

try {
    if ($mode === 'all') {
        $st = $pdo->prepare("
          UPDATE notifications
          SET is_read=1, read_at=NOW()
          WHERE user_id=? AND is_read=0
        ");
        $st->execute([$userId]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'All notifications marked as read.'];
        header("Location: " . $back);
        exit;
    }

    $notificationId = (int)($_POST['notification_id'] ?? 0);
    if ($notificationId <= 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid notification.'];
        header("Location: " . $back);
        exit;
    }

    $st = $pdo->prepare("
      UPDATE notifications
      SET is_read=1, read_at=NOW()
      WHERE notification_id=? AND user_id=?
      LIMIT 1
    ");
    $st->execute([$notificationId, $userId]);

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Notification marked as read.'];
    header("Location: " . $back);
    exit;

} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Please try again.'];
    header("Location: " . $back);
    exit;
}