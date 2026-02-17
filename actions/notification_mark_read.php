<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen','worker']); // citizen now, worker later can use same action

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$roleRaw = (string)($_SESSION['role'] ?? 'guest');
$role = strtolower(trim($roleRaw));
if ($role === 'local authority') $role = 'authority';
if ($role === 'field worker')    $role = 'worker';

$mode = (string)($_POST['mode'] ?? 'one');

$back = ($role === 'worker')
    ? (BASE_URL . '/worker/notifications.php')
    : (BASE_URL . '/citizen/notifications.php');

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

    // only update if it belongs to current user
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