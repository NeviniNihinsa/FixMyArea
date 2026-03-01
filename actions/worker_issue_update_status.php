<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);
$status  = strtoupper(trim((string)($_POST['status'] ?? '')));

$allowed = ['PENDING','IN_PROGRESS','RESOLVED','CLOSED'];

if ($userId <= 0 || $issueId <= 0 || !in_array($status, $allowed, true)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid request.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

/**
 * Confirm: this issue is assigned to this worker + get reporter for notification
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

function statusToLabel(string $s): string {
  return match ($s) {
    'PENDING' => 'PENDING',
    'IN_PROGRESS' => 'IN_PROGRESS',
    'RESOLVED' => 'RESOLVED',
    'CLOSED' => 'CLOSED',
    default => $s
  };
}

try {
  $pdo->beginTransaction();

  // 1) Update issue status
  $st = $pdo->prepare("UPDATE issues SET status=? WHERE issue_id=? LIMIT 1");
  $st->execute([$status, $issueId]);

  // 2) Insert status history
  $note = "Updated by technician"; // (new LFD wording) - safe text only
  $st = $pdo->prepare("
    INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $st->execute([$issueId, $status, $userId, $note]);

  // 3) (Optional but recommended) Update assignment_status as well
  //    Keeps worker dashboards consistent.
  $assignmentStatus = match ($status) {
    'PENDING' => 'ASSIGNED',
    'IN_PROGRESS' => 'IN_PROGRESS',
    'RESOLVED' => 'COMPLETED',
    'CLOSED' => 'CLOSED',
    default => 'ASSIGNED'
  };

  $st = $pdo->prepare("
    UPDATE assignments
    SET assignment_status = ?
    WHERE issue_id = ? AND field_worker_id = ?
    LIMIT 1
  ");
  $st->execute([$assignmentStatus, $issueId, $userId]);

  // 4) Create notification to citizen (reporter)
  //    Your table name is: notifications (plural)
  if ($reporterUserId > 0) {
    $title = "Issue status updated";
    $msg   = "Your issue #{$issueId} has been updated to " . statusToLabel($status) . ".";
    $actionUrl = "/citizen/issue_view.php?issue_id=" . $issueId;

    $st = $pdo->prepare("
      INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url, is_read, created_at)
      VALUES (?, ?, 'STATUS', ?, ?, ?, 0, NOW())
    ");
    $st->execute([$reporterUserId, $issueId, $title, $msg, $actionUrl]);
  }

  $pdo->commit();

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status updated successfully.'];

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Try again.'];
}

header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
exit;