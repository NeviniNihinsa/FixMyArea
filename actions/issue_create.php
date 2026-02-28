<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

$title       = trim($_POST['title'] ?? '');
$categoryId  = (int)($_POST['category_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$areaId      = (int)($_POST['area_id'] ?? 0);
$latRaw      = trim($_POST['lat'] ?? '');
$lngRaw      = trim($_POST['lng'] ?? '');

$isCommon = (int)($_POST['is_common'] ?? 0); // 0 personal, 1 common
if ($isCommon !== 0 && $isCommon !== 1) $isCommon = 0;

$commonAreaId = (int)($_POST['common_area_id'] ?? 0);
if ($isCommon === 0) $commonAreaId = 0; // ignore if personal

$errors = [];
$old = [
  'title' => $title,
  'category_id' => (string)$categoryId,
  'description' => $description,
  'lat' => $latRaw,
  'lng' => $lngRaw,
  'is_common' => (string)$isCommon,
  'common_area_id' => $commonAreaId > 0 ? (string)$commonAreaId : '',
];

// validations
if ($title === '' || mb_strlen($title) < 3) $errors['title'] = "Title must be at least 3 characters.";
if ($categoryId <= 0) $errors['category_id'] = "Category is required.";
if ($description === '' || mb_strlen($description) < 10) $errors['description'] = "Description must be at least 10 characters.";
if ($areaId <= 0) $errors['area_id'] = "Your area is not set. Update profile area first.";

if ($isCommon === 1 && $commonAreaId <= 0) {
  $errors['common_area_id'] = "Common area is required for common issues.";
}

if ($latRaw === '' || !is_numeric($latRaw)) $errors['lat'] = "Latitude must be a number.";
if ($lngRaw === '' || !is_numeric($lngRaw)) $errors['lng'] = "Longitude must be a number.";

$lat = (float)$latRaw;
$lng = (float)$lngRaw;
if (is_numeric($latRaw) && ($lat < -90 || $lat > 90)) $errors['lat'] = "Latitude must be between -90 and 90.";
if (is_numeric($lngRaw) && ($lng < -180 || $lng > 180)) $errors['lng'] = "Longitude must be between -180 and 180.";

// photo validation
if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $errors['photo'] = "Photo is required.";
} else {
  $file = $_FILES['photo'];
  if (($file['size'] ?? 0) > 5 * 1024 * 1024) $errors['photo'] = "Max file size is 5MB.";
  $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
  $mime = @mime_content_type($file['tmp_name']);
  if (!$mime || !isset($allowed[$mime])) $errors['photo'] = "Only JPG, PNG, WebP allowed.";
}

if ($errors) {
  $_SESSION['form_errors'] = $errors;
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/citizen/report_issue.php");
  exit;
}

// confirm category exists
$st = $pdo->prepare("SELECT 1 FROM issue_categories WHERE category_id=?");
$st->execute([$categoryId]);
if (!$st->fetchColumn()) {
  $_SESSION['form_errors'] = ['general' => "Invalid category selected."];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/citizen/report_issue.php");
  exit;
}

// confirm common area exists if common
if ($isCommon === 1) {
  $st = $pdo->prepare("SELECT 1 FROM common_areas WHERE common_area_id=?");
  $st->execute([$commonAreaId]);
  if (!$st->fetchColumn()) {
    $_SESSION['form_errors'] = ['common_area_id' => "Invalid common area selected."];
    $_SESSION['old'] = $old;
    header("Location: " . BASE_URL . "/citizen/report_issue.php");
    exit;
  }
}

// upload folder
$uploadDir = __DIR__ . '/../public/uploads/issues';
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

$file = $_FILES['photo'];
$mime = mime_content_type($file['tmp_name']);
$ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];

$safeName = 'issue_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
  $_SESSION['form_errors'] = ['general' => "Failed to upload image. Try again."];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/citizen/report_issue.php");
  exit;
}

// path stored in DB
$filePath = '/public/uploads/issues/' . $safeName;

try {
  $pdo->beginTransaction();

  // Insert issue (store common_area_id only if common)
  $stmt = $pdo->prepare("
    INSERT INTO issues
      (reporter_user_id, area_id, category_id, title, description, is_common, common_area_id, lat, lng, status, created_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
  ");
  $stmt->execute([
    $userId,
    $areaId,
    $categoryId,
    $title,
    $description,
    $isCommon,
    ($isCommon === 1 ? $commonAreaId : null),
    $lat,
    $lng
  ]);

  $issueId = (int)$pdo->lastInsertId();

  // issue_photos (your enum values are REPORT / PROOF_BEFORE / PROOF_AFTER)
  $stmt = $pdo->prepare("
    INSERT INTO issue_photos (issue_id, photo_type, file_path, uploaded_by_user_id, created_at)
    VALUES (?, 'REPORT', ?, ?, NOW())
  ");
  $stmt->execute([$issueId, $filePath, $userId]);

  // status history
  $stmt = $pdo->prepare("
    INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
    VALUES (?, 'PENDING', ?, 'Issue reported by citizen', NOW())
  ");
  $stmt->execute([$issueId, $userId]);

  $pdo->commit();

  header("Location: " . BASE_URL . "/citizen/track_issue.php?created=1&issue_id=" . $issueId);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  if (is_file($destAbs)) @unlink($destAbs);

  $_SESSION['form_errors'] = ['general' => "Server error. Please try again."];
  $_SESSION['old'] = $old;
  header("Location: " . BASE_URL . "/citizen/report_issue.php");
  exit;
}