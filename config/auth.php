<?php
declare(strict_types=1);

/** Guest-only guard (block logged-in users from login/register)*/

require_once __DIR__ . '/constants.php';

/**Always ensure session is started before using $_SESSION*/
function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**True if user is logged in*/
function is_logged_in(): bool
{
    ensure_session();
    return !empty($_SESSION['user_id']);
}

/**Get current user id from session*/
function current_user_id(): ?int
{
    ensure_session();
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**Get current user role from session*/
function current_role(): string
{
    ensure_session();
    return $_SESSION['role'] ?? 'GUEST';
}

/**Redirect user to the correct dashboard by role*/
function redirect_by_role(): void
{
    $role = current_role();

    switch ($role) {
        case 'ADMIN':
            header("Location: " . BASE_URL . "/admin/home.php");
            exit;

        case 'AUTHORITY':
            header("Location: " . BASE_URL . "/authority/home.php");
            exit;

        case 'WORKER':
            header("Location: " . BASE_URL . "/worker/home.php");
            exit;

        case 'CITIZEN':
            header("Location: " . BASE_URL . "/citizen/home.php");
            exit;

        default:
            // If role missing/corrupt, force logout (safer)
            header("Location: " . BASE_URL . "/auth/logout.php");
            exit;
    }
}

/**Guest-only guard*/
function guest_only(): void
{
    if (is_logged_in()) {
        redirect_by_role();
    }
}

/** If not logged in, redirect to login.*/
function require_login(): void
{
    if (!is_logged_in()) {
        header("Location: " . BASE_URL . "/auth/login.php");
        exit;
    }
}

/**Require one of the given roles*/
function require_roles(array $roles): void
{
    require_login();

    $role = current_role();
    $allowed = array_map('strtoupper', $roles);

    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        echo "403 Forbidden";
        exit;
    }
}

/** Convenience role guards*/
function require_admin(): void
{
    require_roles(['ADMIN']);
}

function require_authority(): void
{
    require_roles(['AUTHORITY']);
}

function require_worker(): void
{
    require_roles(['WORKER']);
}

function require_citizen(): void
{
    require_roles(['CITIZEN']);
}

/** Login session setter */
function login_user(int $userId, string $role, string $name = '', string $email = ''): void
{
    ensure_session();

    // Basic session hardening: regenerate id on login
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = strtoupper($role);

    // Optional profile fields (helpful later for navbar)
    if ($name !== '') $_SESSION['name'] = $name;
    if ($email !== '') $_SESSION['email'] = $email;
}

/** Logout helper*/
function logout_user(): void
{
    ensure_session();

    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"], $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
