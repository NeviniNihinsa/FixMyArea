<?php
declare(strict_types=1);

/*validation | password verify|session creation| role redirect*/

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/auth.php';

ensure_session();

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

$errors = [];

/*Validation*/

if ($email === '') {
    $errors['email'] = "Email is required.";
}
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = "Enter a valid email.";
}

if ($password === '') {
    $errors['password'] = "Password is required.";
}

/*If validation fails -> back to login*/

if (!empty($errors)) {

    $_SESSION['form_errors'] = $errors;
    $_SESSION['old'] = ['email' => $email];

    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

/*Fetch User*/

$stmt = $pdo->prepare("
    SELECT user_id, name, email, role, password_hash, status
    FROM users
    WHERE email = ?
    LIMIT 1
");

$stmt->execute([$email]);

$user = $stmt->fetch();

/*Verify User*/

if (
    !$user ||
    $user['status'] !== 'active' ||
    !password_verify($password, $user['password_hash'])
) {

    $_SESSION['form_errors'] = [
        'general' => "Invalid email or password."
    ];

    $_SESSION['old'] = ['email' => $email];

    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

/* Use centralized session creator*/

login_user(
    (int)$user['user_id'],
    strtoupper($user['role']), // normalize role
    $user['name'],
    $user['email']
);

/*Redirect by Role*/

redirect_by_role();
exit;
