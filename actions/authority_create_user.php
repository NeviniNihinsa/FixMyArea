<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo "Method Not Allowed";
  exit;
}

function back_with_errors(array $errors, array $old): void {
  $_SESSION['form_errors'] = $errors;
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/authority/create_user.php");
  exit;
}

// Authority area
$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id = ? LIMIT 1");
$st->execute([$userId]);
$myAreaId = (int)($st->fetchColumn() ?: 0);
if ($myAreaId <= 0) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'You are not assigned to a branch.'];
  header("Location: " . BASE_URL . "/authority/manage_users.php");
  exit;
}

// Collect + sanitize
$name    = trim((string)($_POST['name'] ?? ''));
$email   = trim((string)($_POST['email'] ?? ''));
$nic     = trim((string)($_POST['nic'] ?? ''));
$phone   = trim((string)($_POST['phone'] ?? ''));
$dob     = trim((string)($_POST['dob'] ?? ''));
$gender  = trim((string)($_POST['gender'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$role    = strtolower(trim((string)($_POST['role'] ?? 'field worker')));

// IMPORTANT: do NOT trust posted area_id; force authority area
$areaId = $myAreaId;

$password = (string)($_POST['password'] ?? '');
$confirm  = (string)($_POST['confirm_password'] ?? '');

$old = [
  'name' => $name, 'email' => $email, 'nic' => $nic, 'phone' => $phone,
  'dob' => $dob, 'gender' => $gender, 'address' => $address,
  'role' => $role, 'area_id' => $areaId,
];

$errors = [];

// Validate
if ($name === '') $errors['name'] = 'Name is required.';
if ($email === '') $errors['email'] = 'Email is required.';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';

if ($nic === '') $errors['nic'] = 'NIC is required.';

if ($password === '') $errors['password'] = 'Password is required.';
elseif (strlen($password) < 6) $errors['password'] = 'Minimum 6 characters.';

if ($confirm === '') $errors['confirm_password'] = 'Confirm password is required.';
elseif ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';

// Optional validations
if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $errors['dob'] = 'Invalid date format.';
if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) $errors['gender'] = 'Invalid gender.';

if (!in_array($role, ['field worker','worker'], true)) {
  // Force it anyway
  $role = 'field worker';
}

if ($errors) back_with_errors($errors, $old);

try {
  // Uniqueness checks (email + NIC)
  $st = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
  $st->execute([$email]);
  if ($st->fetchColumn()) {
    back_with_errors(['email' => 'This email is already used.'], $old);
  }

  $st = $pdo->prepare("SELECT user_id FROM users WHERE nic = ? LIMIT 1");
  $st->execute([$nic]);
  if ($st->fetchColumn()) {
    back_with_errors(['nic' => 'This NIC is already used.'], $old);
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  // Insert user (matches your users table columns)
  $ins = $pdo->prepare("
    INSERT INTO users (name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
  ");
  $ins->execute([
    $name,
    $email,
    $nic,
    ($dob !== '' ? $dob : null),
    ($phone !== '' ? $phone : null),
    ($gender !== '' ? $gender : null),
    ($address !== '' ? $address : null),
    $areaId,
    $role,
    $hash
  ]);

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'User registered successfully.'];
  header("Location: " . BASE_URL . "/authority/manage_users.php");
  exit;

} catch (Throwable $e) {
  // If you want to see real error temporarily:
  // back_with_errors(['general' => 'Error: ' . $e->getMessage()], $old);

  back_with_errors(['general' => 'Failed to register user. Please try again.'], $old);
}