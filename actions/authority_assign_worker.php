<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$authorityId = (int)($_SESSION['user_id'] ?? 0);

$issueId  = (int)($_POST['issue_id'] ?? 0);
$workerId = (int)($_POST['field_worker_id'] ?? 0);

$back = BASE_URL . '/authority/view_issue.php?id=' . $issueId;

if ($issueId <= 0 || $workerId <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid request.'];
    header('Location: ' . $back);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1) Ensure issue exists
    $st = $pdo->prepare("
        SELECT i.issue_id, i.reporter_user_id, i.status
        FROM issues i
        WHERE i.issue_id = ?
        LIMIT 1
    ");
    $st->execute([$issueId]);
    $issue = $st->fetch(PDO::FETCH_ASSOC);

    if (!$issue) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Issue not found.'];
        header('Location: ' . BASE_URL . '/authority/area_issues.php');
        exit;
    }

    // 2) Ensure selected user is an ACTIVE field worker (B2: any area allowed)
    $st = $pdo->prepare("
        SELECT u.user_id, u.name, u.status, u.role
        FROM users u
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $st->execute([$workerId]);
    $worker = $st->fetch(PDO::FETCH_ASSOC);

    if (
        !$worker ||
        strtolower((string)$worker['role']) !== 'field worker' ||
        strtolower((string)$worker['status']) !== 'active'
    ) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Selected worker is not available.'];
        header('Location: ' . $back);
        exit;
    }

    // 3) Block if issue already has an active assignment (ASSIGNED/ACCEPTED)
    $st = $pdo->prepare("
        SELECT a.assignment_id, a.assignment_status
        FROM assignments a
        WHERE a.issue_id = ?
          AND a.assignment_status IN ('ASSIGNED','ACCEPTED')
        ORDER BY a.assigned_at DESC
        LIMIT 1
    ");
    $st->execute([$issueId]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'This issue is already assigned to a worker.'];
        header('Location: ' . $back);
        exit;
    }

    // 4) Create assignment
    $st = $pdo->prepare("
        INSERT INTO assignments (issue_id, field_worker_id, assigned_by_authority_id, assignment_status)
        VALUES (?, ?, ?, 'ASSIGNED')
    ");
    $st->execute([$issueId, $workerId, $authorityId]);

    // 5) Update issue status -> ASSIGNED (only if not already)
    if (($issue['status'] ?? '') !== 'ASSIGNED') {
        $st = $pdo->prepare("UPDATE issues SET status='ASSIGNED' WHERE issue_id=? LIMIT 1");
        $st->execute([$issueId]);
    }

    // 6) Add timeline record
    $note = 'Assigned to field worker: ' . (string)$worker['name'];
    $st = $pdo->prepare("
        INSERT INTO issue_status_history (issue_id, status, changed_by_user_id, note)
        VALUES (?, 'ASSIGNED', ?, ?)
    ");
    $st->execute([$issueId, $authorityId, $note]);

    // 7) Notifications (worker + reporter)
    // Worker notification
    $st = $pdo->prepare("
        INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url)
        VALUES (?, ?, 'ASSIGNMENT', 'New assignment', 'You have been assigned a new issue.', ?)
    ");
    $st->execute([
        $workerId,
        $issueId,
        BASE_URL . '/worker/view_issue.php?id=' . $issueId
    ]);

    // Reporter notification
    $reporterId = (int)$issue['reporter_user_id'];
    $st = $pdo->prepare("
        INSERT INTO notifications (user_id, issue_id, notification_type, title, message, action_url)
        VALUES (?, ?, 'STATUS', 'Issue assigned', 'Your issue has been assigned to a field worker.', ?)
    ");
    $st->execute([
        $reporterId,
        $issueId,
        BASE_URL . '/citizen/track_issue.php?id=' . $issueId
    ]);

    $pdo->commit();

    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Worker assigned successfully.'];
    header('Location: ' . $back);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Server error. Please try again.'];
    header('Location: ' . $back);
    exit;
}