<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId    = (int)($_SESSION['user_id'] ?? 0);
$issueId   = (int)($_POST['issue_id'] ?? 0);
$photoType = strtoupper(trim((string)($_POST['photo_type'] ?? '')));


$allowedTypes = ['PROOF'];

if ($issueId <= 0 || $photoType === '' || !in_array($photoType, $allowedTypes, true)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid proof type.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

// ensure assigned
$st = $pdo->prepare("SELECT 1 FROM assignments WHERE issue_id=? AND field_worker_id=? LIMIT 1");
$st->execute([$issueId, $userId]);
if (!$st->fetchColumn()) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Access denied.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

// validate file
if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Photo is required.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$f = $_FILES['photo'];

// max 5MB
if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Max 5MB allowed.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

// only JPG/PNG/WebP
$mime = mime_content_type($f['tmp_name']);
$allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
if (!isset($allowedMime[$mime])) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Only JPG/PNG/WebP allowed.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

// upload folder
$uploadDir = __DIR__ . '/../public/uploads/issues';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext = $allowedMime[$mime];
$safeName = 'proof_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Upload failed. Try again.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$filePath = '/public/uploads/issues/' . $safeName;

try {
  $st = $pdo->prepare("
    INSERT INTO issue_photos (issue_id, photo_type, file_path, uploaded_by_user_id, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $st->execute([$issueId, $photoType, $filePath, $userId]);

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Proof uploaded successfully.'];
} catch (Throwable $e) {
  if (is_file($destAbs)) @unlink($destAbs);
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'DB error. Try again.'];
}

header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
exit;