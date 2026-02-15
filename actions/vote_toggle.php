<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);

if ($issueId <= 0) {
    $_SESSION['flash_error'] = "Invalid issue.";
    header("Location: " . BASE_URL . "/citizen/track_issue.php");
    exit;
}

/* Check existing vote */
$st = $pdo->prepare("SELECT vote_id FROM votes WHERE issue_id = ? AND user_id = ? AND value = 1 LIMIT 1");
$st->execute([$issueId, $userId]);
$voteId = $st->fetchColumn();

if ($voteId) {
    /* Remove vote (toggle off) */
    $st = $pdo->prepare("DELETE FROM votes WHERE vote_id = ?");
    $st->execute([(int)$voteId]);
    $_SESSION['flash_success'] = "Upvote removed.";
} else {
    /* Add vote */
    $st = $pdo->prepare("
        INSERT INTO votes (issue_id, user_id, value, created_at)
        VALUES (?, ?, 1, NOW())
    ");
    $st->execute([$issueId, $userId]);
    $_SESSION['flash_success'] = "Upvoted successfully.";
}

header("Location: " . BASE_URL . "/citizen/issue_view.php?issue_id=" . $issueId);
exit;