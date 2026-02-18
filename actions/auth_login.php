<?php
declare(strict_types=1);

>>>>>>> e099a1a94cc9ace3150d82262466810ca12917d2
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function back_to_login(): void {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

function set_error(string $key, string $msg): void {
    $_SESSION['form_errors'][$key] = $msg;
}

function set_old(array $data): void {
    $_SESSION['old'] = $data;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_to_login();
}

$email = trim((string)($_POST['email'] ?? ''));
$password = (string)($_POST['password'] ?? '');

set_old(['email' => $email]);

$_SESSION['form_errors'] = [];

if ($email === '') {
    set_error('email', 'Email is required.');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_error('email', 'Enter a valid email.');
}
if ($password === '') {
    set_error('password', 'Password is required.');
}

if (!empty($_SESSION['form_errors'])) {
    back_to_login();
}

try {
    $st = $pdo->prepare("
        SELECT user_id, name, email, role, status, area_id, password_hash
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        set_error('general', 'Invalid email or password.');
        back_to_login();
    }

    if ((string)$user['status'] !== 'active') {
        set_error('general', 'Your account is inactive. Please contact admin.');
        back_to_login();
    }

    // ✅ set session
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['name']    = (string)$user['name'];
    $_SESSION['role']    = (string)$user['role']; // raw DB role: citizen/field worker/local authority/admin
    $_SESSION['area_id'] = (int)($user['area_id'] ?? 0);

    // ✅ correct redirect mapping
    $role = strtolower(trim((string)$user['role']));

    $redirect = BASE_URL . '/citizen/home.php';
    if ($role === 'admin') {
        $redirect = BASE_URL . '/admin/home.php';
    } elseif ($role === 'local authority') {
        $redirect = BASE_URL . '/authority/home.php';
    } elseif ($role === 'field worker') {
        $redirect = BASE_URL . '/worker/home.php';
    } elseif ($role === 'citizen') {
        $redirect = BASE_URL . '/citizen/home.php';
    }

    header("Location: " . $redirect);
    exit;

} catch (Throwable $e) {
    set_error('general', 'Server error. Please try again.');
    back_to_login();
}