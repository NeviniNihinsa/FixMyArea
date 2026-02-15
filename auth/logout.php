<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

// Start session safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Remove all session variables
$_SESSION = [];

// Destroy session cookie (VERY professional step)
if (ini_get("session.use_cookies")) {

    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Finally destroy session
session_destroy();

/*Redirect to Login*/
header("Location: " . BASE_URL . "/auth/login.php");
exit;
