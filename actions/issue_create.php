<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);

$title       = trim($_POST['title'] ?? '');
$categoryId  = (int)($_POST['category_id'] ?? 0);
$description = trim($_POST['description'] ?? '');
$areaId      = (int)($_POST['area_id'] ?? 0);
$latRaw      = trim($_POST['lat'] ?? '');
$lngRaw      = trim($_POST['lng'] ?? '');

$errors = [];
$old = [
    'title' => $title,
    'category_id' => (string)$categoryId,
    'description' => $description,
    'lat' => $latRaw,
    'lng' => $lngRaw
];

// validations
if ($title === '' || mb_strlen($title) < 3) $errors['title'] = "Title must be at least 3 characters.";
if ($categoryId <= 0) $errors['category_id'] = "Category is required.";
if ($description === '' || mb_strlen($description) < 10) $errors['description'] = "Description must be at least 10 characters.";
if ($areaId <= 0) $errors['area_id'] = "Your area is not set. Update profile area first.";

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

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors['photo'] = "Max file size is 5MB.";
    } else {
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        $mime = @mime_content_type($file['tmp_name']);
        if (!$mime || !isset($allowed[$mime])) {
            $errors['photo'] = "Only JPG, PNG, WebP allowed.";
        }
    }
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

// upload folder
$uploadDir = __DIR__ . '/../public/uploads/issues';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

$file = $_FILES['photo'];
$mime = mime_content_type($file['tmp_name']);
$ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];

$safeName = 'issue_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    $_SESSION['flash_error'] = " Failed to upload image. Please try again.";
    $_SESSION['old'] = $old;
    header("Location: " . BASE_URL . "/citizen/report_issue.php");
    exit;
}

// path stored in DB (relative to your project root)
$filePath = '/public/uploads/issues/' . $safeName;

try {
    $pdo->beginTransaction();

    // issues
    $stmt = $pdo->prepare("
        INSERT INTO issues (reporter_user_id, area_id, category_id, title, description, lat, lng, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
    ");
    $stmt->execute([$userId, $areaId, $categoryId, $title, $description, $lat, $lng]);

    $issueId = (int)$pdo->lastInsertId();

    // issue_photos
    $stmt = $pdo->prepare("
        INSERT INTO issue_photos (issue_id, photo_type, file_path, uploaded_by_user_id, created_at)
        VALUES (?, 'REPORT', ?, ?, NOW())
    ");
    $stmt->execute([$issueId, $filePath, $userId]);

    // issue_status_history
    $stmt = $pdo->prepare("
        INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
        VALUES (?, 'PENDING', ?, 'Issue reported by citizen', NOW())
    ");
    $stmt->execute([$issueId, $userId]);

    $pdo->commit();

    $_SESSION['flash_success'] = " Issue submitted successfully! Track ID: #{$issueId}";
    header("Location: " . BASE_URL . "/citizen/report_issue.php");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    // remove uploaded file if DB failed
    if (is_file($destAbs)) @unlink($destAbs);

    $_SESSION['flash_error'] = " Server error. Please try again.";
    $_SESSION['old'] = $old;
    header("Location: " . BASE_URL . "/citizen/report_issue.php");
    exit;
}
