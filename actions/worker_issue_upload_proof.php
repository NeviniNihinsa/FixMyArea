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

// Must match your DB ENUM values
$allowedTypes = ['PROOF_BEFORE', 'PROOF_AFTER'];

if ($userId <= 0 || $issueId <= 0 || !in_array($photoType, $allowedTypes, true)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid proof type.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

/**
 * Ensure this issue is assigned to this worker + get reporter for notification
 */
$st = $pdo->prepare("
  SELECT i.issue_id, i.title, i.reporter_user_id
  FROM assignments a
  JOIN issues i ON i.issue_id = a.issue_id
  WHERE a.issue_id = ? AND a.field_worker_id = ?
  LIMIT 1
");
$st->execute([$issueId, $userId]);
$issue = $st->fetch(PDO::FETCH_ASSOC);

if (!$issue) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Access denied.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

$reporterUserId = (int)($issue['reporter_user_id'] ?? 0);
$issueTitle     = (string)($issue['title'] ?? '');

/** Validate file */
if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Photo is required.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$f = $_FILES['photo'];
if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Upload failed. Please choose a valid file.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$size = (int)($f['size'] ?? 0);
if ($size <= 0 || $size > 5 * 1024 * 1024) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Max 5MB allowed.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$tmp = (string)($f['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid upload. Try again.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$mime = @mime_content_type($tmp) ?: '';
$allowedMime = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

if (!isset($allowedMime[$mime])) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Only JPG/PNG/WebP allowed.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

/** Save file */
$uploadDir = __DIR__ . '/../public/uploads/issues';
if (!is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

$ext = $allowedMime[$mime];
$safeName = 'proof_' . $issueId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs  = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($tmp, $destAbs)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Upload failed. Try again.'];
  header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
  exit;
}

$filePath = '/public/uploads/issues/' . $safeName;

try {
  $pdo->beginTransaction();

  // Insert photo record
  $st = $pdo->prepare("
    INSERT INTO issue_photos (issue_id, photo_type, file_path, uploaded_by_user_id, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $st->execute([$issueId, $photoType, $filePath, $userId]);

  // Notify citizen (reporter)
  if ($reporterUserId > 0) {
    $title = "Proof uploaded";
    $msg   = ($photoType === 'PROOF_BEFORE')
      ? "Technician uploaded PROOF_BEFORE for issue #{$issueId}."
      : "Technician uploaded PROOF_AFTER for issue #{$issueId}.";

    $actionUrl = "/citizen/issue_view.php?issue_id=" . $issueId;

    $st = $pdo->prepare("
      INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url, is_read, created_at)
      VALUES (?, ?, 'PROOF', ?, ?, ?, 0, NOW())
    ");
    $st->execute([$reporterUserId, $issueId, $title, $msg, $actionUrl]);
  }

  $pdo->commit();
  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Proof uploaded successfully.'];

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  if (is_file($destAbs)) @unlink($destAbs);
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'DB error. Try again.'];
}

header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
exit;