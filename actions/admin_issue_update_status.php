<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);
if (session_status() === PHP_SESSION_NONE) session_start();

$adminId = (int)($_SESSION['user_id'] ?? 0);

$issueId = (int)($_POST['issue_id'] ?? 0);
$status  = strtoupper(trim((string)($_POST['status'] ?? '')));
$note    = trim((string)($_POST['note'] ?? ''));

$allowedStatuses = ['PENDING','IN_PROGRESS','RESOLVED','COMPLETED','CLOSED','REJECTED'];

if ($issueId <= 0 || !in_array($status, $allowedStatuses, true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid request.'];
    header("Location: " . BASE_URL . "/admin/manage_issues.php");
    exit;
}

try {
    // Load issue + reporter
    $st = $pdo->prepare("SELECT issue_id, reporter_user_id, status, title FROM issues WHERE issue_id=? LIMIT 1");
    $st->execute([$issueId]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);

    if (!$issue) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Issue not found.'];
        header("Location: " . BASE_URL . "/admin/manage_issues.php");
        exit;
    }

    $oldStatus = (string)$issue['status'];
    $reporterId = (int)$issue['reporter_user_id'];
    $title = (string)$issue['title'];

    $pdo->beginTransaction();

    // Update issues table
    $st = $pdo->prepare("UPDATE issues SET status=? WHERE issue_id=? LIMIT 1");
    $st->execute([$status, $issueId]);

    // Insert history
    $historyNote = $note !== '' ? $note : ("Admin updated status: {$oldStatus} → {$status}");
    $st = $pdo->prepare("
      INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
      VALUES (?, ?, ?, ?, NOW())
    ");
    $st->execute([$issueId, $status, $adminId, $historyNote]);

    // Create notification to tenant (reporter)
    $actionUrl = "/citizen/issue_view.php?issue_id=" . $issueId;
    $notifTitle = "Issue status updated";
    $notifMsg = "Your issue #{$issueId} ({$title}) status changed: {$oldStatus} → {$status}.";

    $st = $pdo->prepare("
      INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url, is_read, created_at)
      VALUES (?, ?, 'STATUS', ?, ?, ?, 0, NOW())
    ");
    $st->execute([$reporterId, $issueId, $notifTitle, $notifMsg, $actionUrl]);

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Status updated to {$status}."];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error while updating status.'];
    header("Location: " . BASE_URL . "/admin/view_issue.php?issue_id=" . $issueId);
    exit;
}