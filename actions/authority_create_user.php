<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$meId = (int)($_SESSION['user_id'] ?? 0);

// Redirect back to form
$back = BASE_URL . '/authority/create_user.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header("Location: " . $back);
  exit;
}

// Fetch authority area
$st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
$st->execute([$meId]);
$me = $st->fetch(PDO::FETCH_ASSOC);

$myAreaId = isset($me['area_id']) ? (int)$me['area_id'] : 0;

$errors = [];
$old = [];

// Inputs
$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$nic = trim((string)($_POST['nic'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$role = trim((string)($_POST['role'] ?? 'field worker'));
$areaId = (int)($_POST['area_id'] ?? 0);

$password = (string)($_POST['password'] ?? '');
$confirm  = (string)($_POST['confirm_password'] ?? '');

$old = [
  'name' => $name,
  'email' => $email,
  'nic' => $nic,
  'phone' => $phone,
  'dob' => $dob,
  'gender' => $gender,
  'address' => $address,
];

// Validation
if ($myAreaId <= 0) {
  $errors['general'] = 'Your authority account has no assigned area.';
}

if ($name === '' || mb_strlen($name) < 2) $errors['name'] = 'Name is required (min 2 chars).';

if ($email === '') $errors['email'] = 'Email is required.';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';

if ($nic === '') $errors['nic'] = 'NIC is required.';

if ($phone !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $phone)) {
  $errors['phone'] = 'Enter a valid phone number.';
}

if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
  $errors['dob'] = 'Invalid date format.';
}

if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) {
  $errors['gender'] = 'Invalid gender.';
}

// Role must be field worker only
if ($role !== 'field worker') {
  $errors['general'] = 'Only Field Worker accounts can be created here.';
}

// Area must match authority area (fixed)
if ($areaId !== $myAreaId) {
  $errors['area_id'] = 'Invalid area.';
}

// Password
if ($password === '' || strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters.';
if ($confirm === '') $errors['confirm_password'] = 'Confirm password is required.';
elseif ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';

if ($errors) {
  $_SESSION['form_errors'] = $errors;
  $_SESSION['old'] = $old;
  header("Location: " . $back);
  exit;
}

// Insert user
try {
  // Ensure email/nic unique
  $chk = $pdo->prepare("SELECT user_id FROM users WHERE email=? OR nic=? LIMIT 1");
  $chk->execute([$email, $nic]);
  if ($chk->fetch()) {
    $_SESSION['form_errors'] = ['general' => 'Email or NIC already exists.'];
    $_SESSION['old'] = $old;
    header("Location: " . $back);
    exit;
  }

  $hash = password_hash($password, PASSWORD_DEFAULT);

  $ins = $pdo->prepare("
    INSERT INTO users
      (name, email, nic, dob, phone, gender, address, area_id, role, password_hash, status)
    VALUES
      (?, ?, ?, NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), NULLIF(?,''), ?, 'field worker', ?, 'active')
  ");
  $ins->execute([$name, $email, $nic, $dob, $phone, $gender, $address, $myAreaId, $hash]);

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Field Worker created successfully.'];
  header("Location: " . BASE_URL . "/authority/manage_users.php");
  exit;

} catch (Throwable $e) {
  $_SESSION['form_errors'] = ['general' => 'Server error. Please try again.'];
  $_SESSION['old'] = $old;
  header("Location: " . $back);
  exit;
}