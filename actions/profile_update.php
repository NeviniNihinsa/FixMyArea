<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen','worker','authority','admin']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// normalize role from session
$roleRaw = (string)($_SESSION['role'] ?? 'guest');
$role = strtolower(trim($roleRaw));
if ($role === 'local authority') $role = 'authority';
if ($role === 'field worker')    $role = 'worker';

// redirect can be forced (optional hidden input), else use session role
$roleRedirect = strtolower(trim((string)($_POST['role_redirect'] ?? $role)));
if (!in_array($roleRedirect, ['citizen','worker','authority','admin'], true)) $roleRedirect = $role;

$back = BASE_URL . "/{$roleRedirect}/profile.php";

// read inputs
$name    = trim((string)($_POST['name'] ?? ''));
$phone   = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$dob     = trim((string)($_POST['dob'] ?? ''));
$gender  = strtolower(trim((string)($_POST['gender'] ?? '')));
$areaId  = (int)($_POST['area_id'] ?? 0);

$errors = [];
$old = [
  'name' => $name,
  'phone' => $phone,
  'address' => $address,
  'dob' => $dob,
  'gender' => $gender,
  'area_id' => (string)$areaId,
];

// validations
if ($name === '' || mb_strlen($name) < 3) $errors['name'] = 'Name must be at least 3 characters.';
if ($phone === '' || mb_strlen($phone) < 7) $errors['phone'] = 'Phone must be at least 7 digits.';
if ($address === '' || mb_strlen($address) < 3) $errors['address'] = 'Address is required.';

if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) {
  $errors['gender'] = 'Invalid gender.';
}

if ($dob !== '') {
  // basic date format check
  $dt = date_create($dob);
  if (!$dt) $errors['dob'] = 'Invalid date.';
}

if ($areaId <= 0) {
  // citizen and worker MUST have area
  if ($roleRedirect === 'citizen' || $roleRedirect === 'worker') {
    $errors['area_id'] = 'Please select your area.';
  }
}

// verify area exists if set
if ($areaId > 0) {
  $st = $pdo->prepare("SELECT 1 FROM areas WHERE area_id=?");
  $st->execute([$areaId]);
  if (!$st->fetchColumn()) $errors['area_id'] = 'Selected area is invalid.';
}

if ($errors) {
  $_SESSION['form_errors'] = $errors;
  $_SESSION['old'] = $old;
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Please fix the errors and try again.'];
  header("Location: " . $back);
  exit;
}

// update
try {
  $st = $pdo->prepare("
    UPDATE users
    SET name=?, phone=?, address=?, dob=?, gender=?, area_id=?
    WHERE user_id=?
    LIMIT 1
  ");
  $st->execute([
    $name,
    $phone,
    $address,
    ($dob === '' ? null : $dob),
    ($gender === '' ? null : $gender),
    ($areaId <= 0 ? null : $areaId),
    $userId
  ]);

  // update session name for navbar
  $_SESSION['name'] = $name;

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Profile updated successfully.'];
  header("Location: " . $back);
  exit;

} catch (Throwable $e) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Please try again.'];
  header("Location: " . $back);
  exit;
}