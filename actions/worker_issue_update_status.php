<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/notify.php';

require_roles(['worker']);
if (session_status() === PHP_SESSION_NONE) session_start();

$userId  = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);
$status  = strtoupper(trim((string)($_POST['status'] ?? '')));

// ✅ Allow values coming from your worker dropdown
// (We will map RESOLVED -> COMPLETED)
$allowed = ['IN_PROGRESS', 'COMPLETED', 'RESOLVED', 'CLOSED'];

if ($issueId <= 0 || !in_array($status, $allowed, true)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid request.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

// ✅ Normalize status (keep DB consistent)
if ($status === 'RESOLVED') {
  $status = 'COMPLETED';
}

// ensure assigned to this worker (optionally only active assignments)
$st = $pdo->prepare("
  SELECT 1
  FROM assignments
  WHERE issue_id=? AND field_worker_id=?
  LIMIT 1
");
$st->execute([$issueId, $userId]);
if (!$st->fetchColumn()) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Access denied.'];
  header("Location: " . BASE_URL . "/worker/assigned_issues.php");
  exit;
}

try {
  $pdo->beginTransaction();

  // Update issue status
  $st = $pdo->prepare("UPDATE issues SET status=? WHERE issue_id=? LIMIT 1");
  $st->execute([$status, $issueId]);

  // Add history
  $note = 'Updated by field worker';
  $st = $pdo->prepare("
    INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note, created_at)
    VALUES (?, ?, ?, ?, NOW())
  ");
  $st->execute([$issueId, $status, $userId, $note]);

  // Find reporter (citizen) to notify
  $st = $pdo->prepare("SELECT reporter_user_id FROM issues WHERE issue_id=? LIMIT 1");
  $st->execute([$issueId]);
  $reporterId = (int)$st->fetchColumn();
  // ✅ notify authority who assigned this issue
try {
  $st = $pdo->prepare("
    SELECT assigned_by_authority_id
    FROM assignments
    WHERE issue_id = ?
    ORDER BY assigned_at DESC, assignment_id DESC
    LIMIT 1
  ");
  $st->execute([$issueId]);
  $authorityId = (int)$st->fetchColumn();

  if ($authorityId > 0) {
    create_notification(
      $pdo,
      $authorityId,
      $issueId,
      'STATUS',
      'Worker updated status',
      "Issue #{$issueId} updated to {$status} by field worker.",
      "/authority/view_issue.php?issue_id={$issueId}"
    );
  }
} catch (Throwable $e) {
  // ignore
}

  // Notify citizen about status update
  if ($reporterId > 0) {
    create_notification(
      $pdo,
      $reporterId,
      $issueId,
      'STATUS',
      'Issue status updated',
      "Your issue #{$issueId} status changed to {$status}.",
      "/citizen/issue_view.php?issue_id={$issueId}"
    );

    // If completed, request feedback
    if ($status === 'COMPLETED') {
      create_notification(
        $pdo,
        $reporterId,
        $issueId,
        'FEEDBACK_REQUEST',
        'Please add feedback',
        "Issue #{$issueId} was marked {$status}. Please rate the service.",
        "/citizen/issue_view.php?issue_id={$issueId}"
      );
    }
  }

  $pdo->commit();
  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status updated.'];

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Try again.'];
}

header("Location: " . BASE_URL . "/worker/issue_view.php?issue_id=" . $issueId);
exit;