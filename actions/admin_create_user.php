<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/helpers.php';

require_roles(['admin']);
if (session_status() === PHP_SESSION_NONE) session_start();

$name = trim((string)($_POST['name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$nic = trim((string)($_POST['nic'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$dob = trim((string)($_POST['dob'] ?? ''));
$gender = trim((string)($_POST['gender'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$areaIdRaw = trim((string)($_POST['area_id'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$status = trim((string)($_POST['status'] ?? 'active'));

$areaId = ($areaIdRaw === '') ? null : (int)$areaIdRaw;

$errors = [];
$old = [
  'name'=>$name,'email'=>$email,'nic'=>$nic,'phone'=>$phone,'dob'=>$dob,'gender'=>$gender,
  'address'=>$address,'area_id'=>$areaIdRaw,'role'=>$role,'status'=>$status
];

if ($name === '' || mb_strlen($name) < 3) $errors['name'] = 'Name must be at least 3 characters.';
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email.';
if ($nic === '' || !is_valid_nic($nic)) $errors['nic'] = 'NIC must be 9 digits + V/X OR 12 digits.';
if ($role === '') $errors['role'] = 'Role is required.';
if ($password === '' || mb_strlen($password) < 6) $errors['password'] = 'Password must be at least 6 characters.';

if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) $errors['gender'] = 'Invalid gender.';
if ($status === '' || !in_array($status, ['active','inactive'], true)) $errors['status'] = 'Invalid status.';
if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $errors['dob'] = 'Invalid date format.';

$allowedRoles = ['citizen','field worker','local authority','admin'];
if ($role !== '' && !in_array($role, $allowedRoles, true)) {
  $errors['role'] = 'Invalid role.';
}

if ($areaId !== null && $areaId <= 0) $errors['area_id'] = 'Invalid area.';
if ($areaId !== null) {
  $st = $pdo->prepare("SELECT 1 FROM areas WHERE area_id=?");
  $st->execute([$areaId]);
  if (!$st->fetchColumn()) $errors['area_id'] = 'Selected area does not exist.';
}

if ($errors) {
  $_SESSION['form_errors'] = $errors;
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/admin/create_user.php");
  exit;
}

// unique checks
$st = $pdo->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
$st->execute([$email]);
if ($st->fetchColumn()) {
  $_SESSION['form_errors'] = ['email' => 'Email already exists.'];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/admin/create_user.php");
  exit;
}

$st = $pdo->prepare("SELECT 1 FROM users WHERE nic=? LIMIT 1");
$st->execute([$nic]);
if ($st->fetchColumn()) {
  $_SESSION['form_errors'] = ['nic' => 'NIC already exists.'];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/admin/create_user.php");
  exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

try {
  $sql = "
    INSERT INTO users
      (name,email,nic,dob,phone,gender,address,area_id,role,password_hash,status,created_at)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,NOW())
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    $name,
    $email,
    $nic,
    ($dob === '' ? null : $dob),
    ($phone === '' ? null : $phone),
    ($gender === '' ? null : $gender),
    ($address === '' ? null : $address),
    $areaId,
    $role,
    $hash,
    $status
  ]);

  $_SESSION['flash'] = ['type'=>'success','msg'=>'User created successfully.'];
  header("Location: " . BASE_URL . "/admin/manage_users.php");
  exit;

} catch (Throwable $e) {
  $_SESSION['form_errors'] = ['general' => 'Server error. Please try again.'];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/admin/create_user.php");
  exit;
}