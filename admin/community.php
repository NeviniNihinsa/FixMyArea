<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Community - Admin';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

/* Fetch latest issues with votes + comments */
$sql = "
SELECT 
    i.issue_id,
    i.title,
    i.status,
    COUNT(DISTINCT v.vote_id) AS upvotes,
    COUNT(DISTINCT c.comment_id) AS comments_count
FROM issues i
LEFT JOIN votes v ON v.issue_id = i.issue_id
LEFT JOIN comments c ON c.issue_id = i.issue_id
GROUP BY i.issue_id
ORDER BY i.created_at DESC
LIMIT 50
";

$stmt = $pdo->query($sql);
$issues = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="container py-4 app-container">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold">Community</h2>
        <div class="d-flex align-items-center gap-3">
    <div class="text-muted small">Sort: Recently Added</div>
    <a href="<?= BASE_URL ?>/admin/leaderboard.php" class="btn btn-outline-brand btn-sm">
         View Leaderboard
    </a>
</div>
    </div>

    <?php if(empty($issues)): ?>
        <div class="card-dark p-4">
            <div class="text-muted">No issues available.</div>
        </div>
    <?php else: ?>

        <div class="d-flex flex-column gap-4">

            <?php foreach($issues as $issue): ?>
                <div class="card-dark p-4 rounded-4">

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">

                        <div>
                            <div class="fw-semibold mb-2">
                                #<?= h($issue['issue_id']) ?> | 
                                <?= h($issue['title']) ?> | 
                                <?= h($issue['status']) ?>
                            </div>

                            <div class="text-muted small d-flex gap-4">
                                <span>⬆ <?= (int)$issue['upvotes'] ?> upvotes</span>
                                <span>💬 <?= (int)$issue['comments_count'] ?> comments</span>
                            </div>
                        </div>

                        <a href="<?= BASE_URL ?>/admin/view_issue.php?issue_id=<?= (int)$issue['issue_id'] ?>"
                           class="btn btn-outline-brand">
                            View Issue
                        </a>

                    </div>

                </div>
            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>