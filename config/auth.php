<?php
declare(strict_types=1);

require_once __DIR__ . '/constants.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function normalize_role(string $roleRaw): string {
  $r = strtolower(trim($roleRaw));

  // DB values
  if ($r === 'local authority') return 'authority';
  if ($r === 'field worker') return 'worker';

  // already normalized
  return $r;
}

function is_logged_in(): bool {
  return !empty($_SESSION['user_id']);
}

function guest_only(): void {
  if (is_logged_in()) {
    $role = normalize_role((string)($_SESSION['role'] ?? 'citizen'));

    $redirect = match ($role) {
      'admin'     => BASE_URL . '/admin/home.php',
      'authority' => BASE_URL . '/authority/home.php',
      'worker'    => BASE_URL . '/worker/home.php',
      default     => BASE_URL . '/citizen/home.php',
    };

    header("Location: " . $redirect);
    exit;
  }
}

function require_roles(array $allowed): void {
  if (!is_logged_in()) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
  }

  $role = normalize_role((string)($_SESSION['role'] ?? 'guest'));

  // normalize allowed roles too
  $allowedNorm = array_map(function($r){
    return normalize_role((string)$r);
  }, $allowed);

  if (!in_array($role, $allowedNorm, true)) {
    http_response_code(403);
    echo "403 Forbidden";
    exit;
  }
}