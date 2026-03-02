<?php
declare(strict_types=1);

/**Create a notification row */
function create_notification(
    PDO $pdo,
    int $userId,
    ?int $issueId,
    string $type,
    string $title,
    string $message,
    ?string $actionUrl = null
): void {
    $st = $pdo->prepare("
        INSERT INTO notifications
          (user_id, issue_id, notification_type, title, message, action_url, is_read, created_at)
        VALUES
          (?, ?, ?, ?, ?, ?, 0, NOW())
    ");
    $st->execute([$userId, $issueId, $type, $title, $message, $actionUrl]);
}