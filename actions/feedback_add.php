<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);

$overall = (int)($_POST['overall_rating'] ?? 0);
$worker  = (int)($_POST['worker_rating'] ?? 0);
$auth    = (int)($_POST['authority_rating'] ?? 0);
$text    = trim($_POST['feedback_text'] ?? '');

$redirect = BASE_URL . "/citizen/issue_view.php?issue_id=" . $issueId;

if ($issueId <= 0) {
    $_SESSION['flash_error'] = "Invalid issue.";
    header("Location: " . BASE_URL . "/citizen/track_issue.php");
    exit;
}

$validRating = fn(int $x) => $x >= 1 && $x <= 5;

if (!$validRating($overall) || !$validRating($worker) || !$validRating($auth)) {
    $_SESSION['flash_error'] = "Please select ratings between 1 and 5.";
    header("Location: " . $redirect);
    exit;
}

if (mb_strlen($text) > 500) {
    $_SESSION['flash_error'] = "Feedback too long (max 500 chars).";
    header("Location: " . $redirect);
    exit;
}

//Ensure issue exists
$st = $pdo->prepare("SELECT 1 FROM issues WHERE issue_id = ? LIMIT 1");
$st->execute([$issueId]);
if (!$st->fetchColumn()) {
    $_SESSION['flash_error'] = "Issue not found.";
    header("Location: " . BASE_URL . "/citizen/track_issue.php");
    exit;
}

//Prevent duplicate feedback by same citizen
$st = $pdo->prepare("SELECT 1 FROM feedback_ratings WHERE issue_id = ? AND citizen_user_id = ? LIMIT 1");
$st->execute([$issueId, $userId]);
if ($st->fetchColumn()) {
    $_SESSION['flash_error'] = "You already submitted feedback for this issue.";
    header("Location: " . $redirect);
    exit;
}

$authorityUserId = null;
$workerUserId    = null;

$st = $pdo->prepare("
    SELECT field_worker_id, assigned_by_authority_id
    FROM assignments
    WHERE issue_id = ?
    ORDER BY assigned_at DESC
    LIMIT 1
");
$st->execute([$issueId]);
$as = $st->fetch(PDO::FETCH_ASSOC);
if ($as) {
    $workerUserId = !empty($as['field_worker_id']) ? (int)$as['field_worker_id'] : null;
    $authorityUserId = !empty($as['assigned_by_authority_id']) ? (int)$as['assigned_by_authority_id'] : null;
}

// Insert feedback
$st = $pdo->prepare("
    INSERT INTO feedback_ratings
        (issue_id, citizen_user_id, authority_user_id, field_worker_id,
         authority_rating, worker_rating, overall_rating, feedback_text, created_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW())
");
$st->execute([
    $issueId,
    $userId,
    $authorityUserId,
    $workerUserId,
    $auth,
    $worker,
    $overall,
    $text
]);

$_SESSION['flash_success'] = "Feedback submitted successfully.";
header("Location: " . $redirect);
exit;