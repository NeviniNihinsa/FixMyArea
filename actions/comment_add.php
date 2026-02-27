<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen','worker','admin','authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);
$text = trim((string)($_POST['comment_text'] ?? ''));

// where to go back
$returnTo = trim((string)($_POST['return_to'] ?? ''));
if ($returnTo === '') {
  // fallback by role
  $role = strtolower(trim((string)($_SESSION['role'] ?? 'citizen')));
  if ($role === 'field worker') $role = 'worker';
  if ($role === 'local authority') $role = 'authority';

  $returnTo = match ($role) {
    'worker' => 'worker/community.php',
    'admin' => 'admin/community.php',
    'authority' => 'authority/community.php',
    default => 'citizen/community.php',
  };
}

// basic validation
if ($issueId <= 0) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid issue.'];
  header("Location: " . BASE_URL . "/" . $returnTo);
  exit;
}

if ($text === '' || mb_strlen($text) < 2) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Comment is too short.'];
  header("Location: " . BASE_URL . "/" . $returnTo);
  exit;
}

// Ensure issue exists
$st = $pdo->prepare("SELECT 1 FROM issues WHERE issue_id=?");
$st->execute([$issueId]);
if (!$st->fetchColumn()) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Issue not found.'];
  header("Location: " . BASE_URL . "/" . $returnTo);
  exit;
}

// Insert comment into your table: comments
try {
  $st = $pdo->prepare("
    INSERT INTO comments (issue_id, user_id, comment_text, created_at)
    VALUES (?, ?, ?, NOW())
  ");
  $st->execute([$issueId, $userId, $text]);

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Comment added.'];
  header("Location: " . BASE_URL . "/" . $returnTo);
  exit;

} catch (Throwable $e) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Try again.'];
  header("Location: " . BASE_URL . "/" . $returnTo);
  exit;
}