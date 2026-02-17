<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);
if (session_status() === PHP_SESSION_NONE) session_start();

$adminId = (int)($_SESSION['user_id'] ?? 0);

$issueId = (int)($_POST['issue_id'] ?? 0);
$photoType = strtoupper(trim((string)($_POST['photo_type'] ?? '')));

$allowedTypes = ['PROOF_BEFORE','PROOF_AFTER'];

if ($issueId <= 0 || !in_array($photoType, $allowedTypes, true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid upload request.'];
    header("Location: " . BASE_URL . "/admin/manage_issues.php");
    exit;
}

// Validate file
if (empty($_FILES['photo']) || ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Please choose an image file.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}

$file = $_FILES['photo'];

if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Max file size is 5MB.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}

$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$mime = @mime_content_type($file['tmp_name']);
if (!$mime || !isset($allowedMime[$mime])) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Only JPG, PNG, WebP allowed.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}

$ext = $allowedMime[$mime];

// Ensure upload folder exists (same folder you already secured with .htaccess)
$uploadDir = __DIR__ . '/../public/uploads/issues';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeName = 'proof_' . $issueId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$destAbs = $uploadDir . '/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Upload failed. Try again.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}

$filePath = '/public/uploads/issues/' . $safeName;

try {
    $pdo->beginTransaction();

    // Save in issue_photos
    $st = $pdo->prepare("
      INSERT INTO issue_photos (issue_id, photo_type, file_path, uploaded_by_user_id, created_at)
      VALUES (?, ?, ?, ?, NOW())
    ");
    $st->execute([$issueId, $photoType, $filePath, $adminId]);

    // Add status history note (optional but nice)
    $st = $pdo->prepare("
      INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
      VALUES (?, (SELECT status FROM issues WHERE issue_id=?), ?, ?, NOW())
    ");
    $st->execute([$issueId, $issueId, $adminId, "Admin uploaded proof image ({$photoType})."]);

    // Notify reporter (optional, but lecturers love)
    $st = $pdo->prepare("SELECT reporter_user_id, title FROM issues WHERE issue_id=? LIMIT 1");
    $st->execute([$issueId]);
    $iss = $st->fetch(PDO::FETCH_ASSOC);
    if ($iss) {
        $reporterId = (int)$iss['reporter_user_id'];
        $title = (string)$iss['title'];
        $actionUrl = "/citizen/issue_view.php?issue_id=" . $issueId;

        $st = $pdo->prepare("
          INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url, is_read, created_at)
          VALUES (?, ?, 'PROOF', ?, ?, ?, 0, NOW())
        ");
        $st->execute([
            $reporterId,
            $issueId,
            "Proof uploaded",
            "Proof image uploaded for issue #{$issueId} ({$title}).",
            $actionUrl
        ]);
    }

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Proof uploaded ({$photoType})."];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if (is_file($destAbs)) @unlink($destAbs);

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error saving proof.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}