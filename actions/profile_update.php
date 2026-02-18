<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_login();

$userId = (int)($_SESSION['user_id'] ?? 0);
$role   = $_SESSION['role'] ?? 'guest';

if ($userId <= 0) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

/** Redirect target depends on role */
$redirect = BASE_URL . "/auth/login.php";
if ($role === 'citizen')   $redirect = BASE_URL . "/citizen/profile.php";
if ($role === 'worker')    $redirect = BASE_URL . "/worker/profile.php";
if ($role === 'authority') $redirect = BASE_URL . "/authority/profile.php";
if ($role === 'admin')     $redirect = BASE_URL . "/admin/profile.php";

/** Collect inputs */
$name   = trim($_POST['name'] ?? '');
$nic    = trim($_POST['nic'] ?? '');
$phone  = trim($_POST['phone'] ?? '');
$dob    = trim($_POST['dob'] ?? '');
$gender = trim($_POST['gender'] ?? '');
$areaIdRaw = $_POST['area_id'] ?? '';

$areaId = ($areaIdRaw === '' ? 0 : (int)$areaIdRaw);

$errors = [];
$old = [
    'name' => $name,
    'nic' => $nic,
    'phone' => $phone,
    'dob' => $dob,
    'gender' => $gender,
    'area_id' => (string)$areaId,
];

//Validations

// Name
if ($name === '' || mb_strlen($name) < 2) {
    $errors['name'] = "Name is required (min 2 characters).";
} elseif (mb_strlen($name) > 80) {
    $errors['name'] = "Name must be 80 characters or less.";
}

// NIC safe validation
if ($nic === '' || mb_strlen($nic) < 5) {
    $errors['nic'] = "NIC is required.";
} elseif (mb_strlen($nic) > 20) {
    $errors['nic'] = "NIC must be 20 characters or less.";
}

// Phone 
if ($phone !== '') {
    // Allow +, digits, spaces, -
    if (!preg_match('/^[0-9+\-\s]{7,15}$/', $phone)) {
        $errors['phone'] = "Enter a valid phone number (7–15 digits).";
    }
}

// DOB 
if ($dob !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $dob);
    $valid = $dt && $dt->format('Y-m-d') === $dob;
    if (!$valid) {
        $errors['dob'] = "Date of Birth must be a valid date.";
    } else {
        $today = new DateTime('today');
        if ($dt > $today) {
            $errors['dob'] = "Date of Birth cannot be in the future.";
        }
    }
}

// Gender 
$allowedGender = ['male', 'female', 'other', ''];
if (!in_array($gender, $allowedGender, true)) {
    $errors['gender'] = "Invalid gender value.";
}

// Area 
if ($areaId <= 0) {
    // citizen must select area 
    if ($role === 'citizen') {
        $errors['area_id'] = "Please select your area.";
    }
} else {
    // Ensure area exists
    $st = $pdo->prepare("SELECT 1 FROM areas WHERE area_id=?");
    $st->execute([$areaId]);
    if (!$st->fetchColumn()) {
        $errors['area_id'] = "Selected area is invalid.";
    }
}

// If validation errors 
if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['old'] = $old;
    header("Location: " . $redirect);
    exit;
}

//DB UPDATE
try {
    // NIC should be unique 
    $st = $pdo->prepare("SELECT user_id FROM users WHERE nic = ? AND user_id <> ? LIMIT 1");
    $st->execute([$nic, $userId]);
    if ($st->fetch(PDO::FETCH_ASSOC)) {
        $_SESSION['form_errors'] = ['nic' => "This NIC is already used by another account."];
        $_SESSION['old'] = $old;
        header("Location: " . $redirect);
        exit;
    }

    $stmt = $pdo->prepare("
        UPDATE users
        SET name = ?, nic = ?, phone = ?, dob = ?, gender = ?, area_id = ?
        WHERE user_id = ?
        LIMIT 1
    ");

    // Store NULL for empty dob/gender/area if needed
    $dobDb    = ($dob === '') ? null : $dob;
    $genderDb = ($gender === '') ? null : $gender;
    $areaDb   = ($areaId <= 0) ? null : $areaId;

    $stmt->execute([$name, $nic, $phone, $dobDb, $genderDb, $areaDb, $userId]);

    // Update session name 
    $_SESSION['name'] = $name;

    $_SESSION['flash_success'] = "Profile updated successfully.";
    header("Location: " . $redirect);
    exit;

} catch (Throwable $e) {
    $_SESSION['form_errors'] = ['general' => "Server error. Please try again."];
    $_SESSION['old'] = $old;
    header("Location: " . $redirect);
    exit;
}