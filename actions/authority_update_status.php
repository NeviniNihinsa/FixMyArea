<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority','authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$userId = (int)($_SESSION['user_id'] ?? 0);
$issueId = (int)($_POST['issue_id'] ?? 0);
$status  = strtoupper(trim((string)($_POST['status'] ?? '')));

$allowed = ['PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED'];
$back = BASE_URL . '/authority/view_issue.php?issue_id=' . $issueId;

if ($userId <= 0 || $issueId <= 0 || !in_array($status, $allowed, true)) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid status update request.'];
  header("Location: $back");
  exit;
}

try {
  // authority area 
  $st = $pdo->prepare("SELECT area_id FROM users WHERE user_id=? LIMIT 1");
  $st->execute([$userId]);
  $myAreaId = (int)($st->fetchColumn() ?: 0);

  // load issue
  $st = $pdo->prepare("SELECT issue_id, area_id, status FROM issues WHERE issue_id=? LIMIT 1");
  $st->execute([$issueId]);
  $issue = $st->fetch(PDO::FETCH_ASSOC);

  if (!$issue) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Issue not found.'];
    header("Location: " . BASE_URL . "/authority/area_issues.php");
    exit;
  }

  if ($myAreaId > 0 && (int)$issue['area_id'] !== $myAreaId) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Not allowed (issue not in your area).'];
    header("Location: " . BASE_URL . "/authority/area_issues.php");
    exit;
  }

  $pdo->beginTransaction();

  $st = $pdo->prepare("UPDATE issues SET status=? WHERE issue_id=? LIMIT 1");
  $st->execute([$status, $issueId]);

  $note = 'Status updated by local authority';
  $st = $pdo->prepare("
    INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note)
    VALUES (?, ?, ?, ?)
  ");
  $st->execute([$issueId, $status, $userId, $note]);

  $pdo->commit();

  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Status updated.'];
  header("Location: $back");
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error while updating status.'];
  header("Location: $back");
  exit;
}