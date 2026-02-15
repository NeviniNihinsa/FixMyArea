<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

if (session_status() === PHP_SESSION_NONE) session_start();

$targetUserId = (int)($_POST['user_id'] ?? 0);
$to = strtolower(trim((string)($_POST['to'] ?? ''))); // 'active' or 'inactive'
$currentAdminId = (int)($_SESSION['user_id'] ?? 0);

$back = BASE_URL . '/admin/manage_users.php';

if ($targetUserId <= 0 || !in_array($to, ['active', 'inactive'], true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid request.'];
    header("Location: " . $back);
    exit;
}

// Don’t allow admin to disable self (avoids locking yourself out)
if ($targetUserId === $currentAdminId) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'You cannot change your own status.'];
    header("Location: " . $back);
    exit;
}

try {
    // Get target user role + current status
    $st = $pdo->prepare("SELECT role, status FROM users WHERE user_id = ? LIMIT 1");
    $st->execute([$targetUserId]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'User not found.'];
        header("Location: " . $back);
        exit;
    }

    // Never disable an admin account
    if ((string)$user['role'] === 'admin') {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Admin accounts cannot be disabled.'];
        header("Location: " . $back);
        exit;
    }

    // If already same status, no need update (still OK)
    if (strtolower((string)$user['status']) === $to) {
        $_SESSION['flash'] = ['type' => 'info', 'msg' => 'User is already ' . $to . '.'];
        header("Location: " . $back);
        exit;
    }

    // Update status
    $st = $pdo->prepare("UPDATE users SET status = ? WHERE user_id = ? LIMIT 1");
    $st->execute([$to, $targetUserId]);

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User status updated to ' . $to . '.'];
    header("Location: " . $back);
    exit;

} catch (Throwable $e) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Please try again.'];
    header("Location: " . $back);
    exit;
}