<?php
declare(strict_types=1);

/*Redirect Helper*/
function redirect(string $path): void
{
    header("Location: " . BASE_URL . $path);
    exit;
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function old(string $key, $default = '')
{
    return $_SESSION['old'][$key] ?? $default;
}

function error(string $key): string
{
    return $_SESSION['form_errors'][$key] ?? '';
}

/* Clear Old Form Data*/
function clear_form_data(): void
{
    unset($_SESSION['old'], $_SESSION['form_errors']);
}

/*NIC Validation*/
function is_valid_nic(string $nic): bool
{
    return (bool) preg_match('/^(\d{9}[vVxX]|\d{12})$/', $nic);
}

/*Email Validation*/
function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/*Password Strength Minimum 6 chars (can increase later)*/
function is_strong_password(string $password): bool
{
    return strlen($password) >= 6;
}
