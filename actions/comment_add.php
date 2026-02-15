<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);
$text    = trim($_POST['comment_text'] ?? '');

if ($issueId <= 0) {
    $_SESSION['flash_error'] = "Invalid issue.";
    header("Location: " . BASE_URL . "/citizen/track_issue.php");
    exit;
}

if ($text === '' || mb_strlen($text) < 2) {
    $_SESSION['flash_error'] = "Comment is required (min 2 chars).";
    header("Location: " . BASE_URL . "/citizen/issue_view.php?issue_id=" . $issueId);
    exit;
}

if (mb_strlen($text) > 500) {
    $_SESSION['flash_error'] = "Comment too long (max 500 chars).";
    header("Location: " . BASE_URL . "/citizen/issue_view.php?issue_id=" . $issueId);
    exit;
}

/* Ensure issue exists */
$st = $pdo->prepare("SELECT 1 FROM issues WHERE issue_id = ? LIMIT 1");
$st->execute([$issueId]);
if (!$st->fetchColumn()) {
    $_SESSION['flash_error'] = "Issue not found.";
    header("Location: " . BASE_URL . "/citizen/track_issue.php");
    exit;
}

/* Insert comment */
$st = $pdo->prepare("
    INSERT INTO comments (issue_id, user_id, comment_text, created_at)
    VALUES (?, ?, ?, NOW())
");
$st->execute([$issueId, $userId, $text]);

$_SESSION['flash_success'] = "Comment added successfully.";
header("Location: " . BASE_URL . "/citizen/issue_view.php?issue_id=" . $issueId);
exit;