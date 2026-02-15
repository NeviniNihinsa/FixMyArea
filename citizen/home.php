<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

// Find citizen's area_id + name
$stmt = $pdo->prepare("SELECT area_id, name FROM users WHERE user_id = ? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

$myAreaId = $me['area_id'] ?? null;
$citizenName = $me['name'] ?? ($_SESSION['name'] ?? 'Citizen');

// Areas dropdown
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selectedAreaId = isset($_GET['area_id']) ? (int)$_GET['area_id'] : (int)($myAreaId ?? 0);
if ($selectedAreaId <= 0 && !empty($areas)) {
    $selectedAreaId = (int)$areas[0]['area_id'];
}

function count_issues(PDO $pdo, string $whereSql = "1", array $params = []): int {
    $sql = "SELECT COUNT(*) FROM issues WHERE {$whereSql}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

// Stats
$totalIssues = count_issues($pdo);
$totalFixed  = count_issues($pdo, "status IN ('COMPLETED','CLOSED')");

$areaReported = 0;
$areaFixed = 0;
if ($selectedAreaId > 0) {
    $areaReported = count_issues($pdo, "area_id = ?", [$selectedAreaId]);
    $areaFixed    = count_issues($pdo, "area_id = ? AND status IN ('COMPLETED','CLOSED')", [$selectedAreaId]);
}

// Recent local issues (3 latest)
$recent = [];
if ($selectedAreaId > 0) {
    $st = $pdo->prepare("
        SELECT issue_id, title, status, created_at
        FROM issues
        WHERE area_id = ?
        ORDER BY created_at DESC
        LIMIT 3
    ");
    $st->execute([$selectedAreaId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Welcome <?= htmlspecialchars($citizenName) ?></h2>

  <div class="row g-4">
    <!-- LEFT: Map placeholder -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-3">
        <div class="ratio ratio-4x3" style="border-radius: 12px; overflow:hidden;">
          <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
            <div class="text-center">
              <div class="text-muted">Map Placeholder</div>
              <div class="small text-muted">OpenStreetMap will be added soon</div>
            </div>
          </div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <a class="btn btn-brand" href="<?= BASE_URL ?>/citizen/report_issue.php">Report an Issue</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/track_issue.php">Track Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/community.php">Community</a>
        </div>
      </div>
    </div>

    <!-- RIGHT: Stats -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">

        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Issues</div>
              <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Fixed</div>
              <div class="fs-3 fw-bold"><?= $totalFixed ?></div>
            </div>
          </div>
        </div>

        <hr style="border-color: rgba(241,246,246,0.10);" class="my-4">

        <form method="GET" class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
          <label class="text-muted mb-0">Issues reported in</label>
          <select name="area_id" class="form-select" style="max-width: 260px;" onchange="this.form.submit()">
            <?php foreach ($areas as $a): ?>
              <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $selectedAreaId) ? 'selected' : '' ?>>
                <?= htmlspecialchars($a['area_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="btn btn-brand">Apply</button></noscript>
        </form>

        <div class="row g-3 mt-1">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Reported</div>
              <div class="fs-3 fw-bold"><?= $areaReported ?></div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Fixed</div>
              <div class="fs-3 fw-bold"><?= $areaFixed ?></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Recent Local Issues -->
  <div class="mt-4 card-dark p-4">
    <h4 class="fw-semibold mb-3">Recent Local Issues</h4>

    <?php if (empty($recent)): ?>
      <div class="text-muted">No issues found for this area yet.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:110px;">Track ID</th>
              <th>Title</th>
              <th style="width:140px;">Status</th>
              <th style="width:180px;">Created</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= htmlspecialchars($r['title']) ?></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($r['status']) ?></span></td>
                <td class="text-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/citizen/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
