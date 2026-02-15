<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/helpers.php';

session_start();

$name = trim($_POST['name'] ?? '');
$nic = trim($_POST['nic'] ?? '');
$email = trim($_POST['email'] ?? '');
$dob = trim($_POST['dob'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$area_id = (int)($_POST['area_id'] ?? 0);
$password = $_POST['password'] ?? '';
$confirm = $_POST['confirm_password'] ?? '';

$errors = [];

// Required
if ($name === '') $errors['name'] = "Name is required.";
if ($nic === '') $errors['nic'] = "NIC is required.";
elseif (!is_valid_nic($nic)) $errors['nic'] = "Enter a valid NIC (old/new format).";

if ($email === '') $errors['email'] = "Email is required.";
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = "Enter a valid email.";

if ($area_id <= 0) $errors['area_id'] = "Select your area.";

if ($password === '') $errors['password'] = "Password is required.";
elseif (strlen($password) < 6) $errors['password'] = "Minimum 6 characters.";

if ($confirm === '') $errors['confirm_password'] = "Confirm your password.";
elseif ($password !== $confirm) $errors['confirm_password'] = "Passwords do not match.";

// Optional checks
if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) $errors['dob'] = "Invalid date.";
if ($gender !== '' && !in_array($gender, ['male','female','other'], true)) $errors['gender'] = "Invalid gender.";

// If errors -> back
if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['old'] = compact('name','nic','email','dob','phone','address','gender','area_id');
    header("Location: " . BASE_URL . "/auth/register.php");
    exit;
}

// Check duplicate email/NIC
$check = $pdo->prepare("SELECT 1 FROM users WHERE email=? OR nic=? LIMIT 1");
$check->execute([$email, $nic]);
if ($check->fetch()) {
    $_SESSION['form_errors'] = ['general' => "Email or NIC already exists."];
    $_SESSION['old'] = compact('name','nic','email','dob','phone','address','gender','area_id');
    header("Location: " . BASE_URL . "/auth/register.php");
    exit;
}

// Insert citizen
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("
  INSERT INTO users (name,email,nic,dob,phone,gender,address,area_id,role,password_hash,status)
  VALUES (?,?,?,?,?,?,?,?, 'citizen', ?, 'active')
");
$stmt->execute([
  $name,
  $email,
  $nic,
  ($dob === '' ? null : $dob),
  ($phone === '' ? null : $phone),
  ($gender === '' ? null : $gender),
  ($address === '' ? null : $address),
  $area_id,
  $hash
]);

$_SESSION['user_id'] = (int)$pdo->lastInsertId();
$_SESSION['name'] = $name;
$_SESSION['email'] = $email;
$_SESSION['role'] = 'citizen';

header("Location: " . BASE_URL . "/citizen/home.php");
exit;
