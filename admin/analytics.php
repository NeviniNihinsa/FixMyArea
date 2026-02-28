<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Analytics & Reports - Fixly';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

/**
 * FILTERS (match low-fi)
 * - area_id (optional)
 * - from_date (optional)
 * - to_date (optional)
 */
$areaId   = (int)($_GET['area_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));

$errors = [];

/** Validate dates (YYYY-MM-DD) */
$validDate = function(string $d): bool {
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};

if (!$validDate($fromDate)) $errors[] = "Invalid From Date.";
if (!$validDate($toDate))   $errors[] = "Invalid To Date.";
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) $errors[] = "From Date must be before To Date.";

/** Areas dropdown */
$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

/** Build WHERE clause */
$where = [];
$params = [];

if ($areaId > 0) { $where[] = "i.area_id = ?"; $params[] = $areaId; }

if ($fromDate !== '') { $where[] = "i.created_at >= ?"; $params[] = $fromDate . " 00:00:00"; }
if ($toDate !== '')   { $where[] = "i.created_at <= ?"; $params[] = $toDate . " 23:59:59"; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/**
 * KPI 1: Total Issues
 */
$st = $pdo->prepare("SELECT COUNT(*) FROM issues i {$whereSql}");
$st->execute($params);
$totalIssues = (int)$st->fetchColumn();

/**
 * KPI 2: Resolved Issues
 * (use your statuses: COMPLETED/CLOSED)
 */
$st = $pdo->prepare("SELECT COUNT(*) FROM issues i {$whereSql}" . ($whereSql ? " AND " : " WHERE ") . " i.status IN ('COMPLETED','CLOSED')");
$st->execute($params);
$resolvedIssues = (int)$st->fetchColumn();

/**
 * KPI 3: Avg Resolution Time (days)
 * We calculate:
 * - for issues closed/completed
 * - difference between i.created_at and latest status_history row where status is COMPLETED/CLOSED
 *
 * If you don't have those rows, avg = 0.0
 */
$avgResolution = 0.0;

$sqlAvg = "
SELECT AVG(DATEDIFF(h.resolved_at, i.created_at)) AS avg_days
FROM issues i
JOIN (
  SELECT issue_id, MAX(created_at) AS resolved_at
  FROM issue_status_history
  WHERE status IN ('COMPLETED','CLOSED')
  GROUP BY issue_id
) h ON h.issue_id = i.issue_id
{$whereSql}
AND i.status IN ('COMPLETED','CLOSED')
";
$st = $pdo->prepare($sqlAvg);
$st->execute($params);
$avgResolution = (float)($st->fetchColumn() ?? 0.0);
if ($avgResolution < 0) $avgResolution = 0.0;
$avgResolution = round($avgResolution, 1);

/**
 * Chart 1: Issue Distribution by Category
 */
$sqlCat = "
SELECT ic.category_name AS label, COUNT(*) AS c
FROM issues i
JOIN issue_categories ic ON ic.category_id = i.category_id
{$whereSql}
GROUP BY ic.category_name
ORDER BY c DESC
";
$st = $pdo->prepare($sqlCat);
$st->execute($params);
$byCategory = $st->fetchAll(PDO::FETCH_ASSOC);

$catLabels = array_map(fn($r) => (string)$r['label'], $byCategory);
$catData   = array_map(fn($r) => (int)$r['c'], $byCategory);

/**
 * Chart 2: Resolution Time Analysis (simple + defendable)
 * Bucket resolved issues into ranges:
 * 0-2, 3-7, 8-14, 15-30, 31+
 */
$buckets = [
  '0-2 days' => 0,
  '3-7 days' => 0,
  '8-14 days' => 0,
  '15-30 days' => 0,
  '31+ days' => 0,
];

$sqlResTimes = "
SELECT DATEDIFF(h.resolved_at, i.created_at) AS days_taken
FROM issues i
JOIN (
  SELECT issue_id, MAX(created_at) AS resolved_at
  FROM issue_status_history
  WHERE status IN ('COMPLETED','CLOSED')
  GROUP BY issue_id
) h ON h.issue_id = i.issue_id
{$whereSql}
AND i.status IN ('COMPLETED','CLOSED')
";
$st = $pdo->prepare($sqlResTimes);
$st->execute($params);
$resTimes = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($resTimes as $r) {
  $d = (int)($r['days_taken'] ?? 0);
  if ($d <= 2) $buckets['0-2 days']++;
  elseif ($d <= 7) $buckets['3-7 days']++;
  elseif ($d <= 14) $buckets['8-14 days']++;
  elseif ($d <= 30) $buckets['15-30 days']++;
  else $buckets['31+ days']++;
}

$rtLabels = array_keys($buckets);
$rtData   = array_values($buckets);

/**
 * CSV Download (low-fi says PDF/CSV; do CSV now)
 * /admin/analytics.php?download=csv&...
 */
$download = (string)($_GET['download'] ?? '');
if ($download === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="analytics_report.csv"');

  $out = fopen('php://output', 'w');

  // Summary section
  fputcsv($out, ['Fixly Analytics Report']);
  fputcsv($out, ['Area', $areaId > 0 ? (string)$areaId : 'All']);
  fputcsv($out, ['From', $fromDate !== '' ? $fromDate : 'Any']);
  fputcsv($out, ['To', $toDate !== '' ? $toDate : 'Any']);
  fputcsv($out, []);
  fputcsv($out, ['Total Issues', $totalIssues]);
  fputcsv($out, ['Resolved Issues', $resolvedIssues]);
  fputcsv($out, ['Avg Resolution Time (days)', $avgResolution]);
  fputcsv($out, []);

  // Category distribution
  fputcsv($out, ['Issue Distribution by Category']);
  fputcsv($out, ['Category', 'Count']);
  foreach ($byCategory as $row) {
    fputcsv($out, [$row['label'], $row['c']]);
  }
  fputcsv($out, []);

  // Resolution time buckets
  fputcsv($out, ['Resolution Time Analysis']);
  fputcsv($out, ['Range', 'Count']);
  foreach ($buckets as $k => $v) {
    fputcsv($out, [$k, $v]);
  }

  fclose($out);
  exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Analytics & Reports</h2>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?= h(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <!-- FILTER BOX (matches low-fi) -->
  <div class="card-dark p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div class="fw-semibold">Filter by</div>

      <form method="GET" class="w-100">
        <div class="row g-3 align-items-end">

          <div class="col-12 col-md-4 col-lg-3">
            <label class="form-label text-muted">Branch</label>
            <select name="area_id" class="form-select">
              <option value="0">All areas</option>
              <?php foreach ($areas as $a): ?>
                <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
                  <?= h($a['area_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-12 col-md-4 col-lg-3">
            <label class="form-label text-muted">From Date</label>
            <input type="date" name="from_date" class="form-control" value="<?= h($fromDate) ?>">
          </div>

          <div class="col-12 col-md-4 col-lg-3">
            <label class="form-label text-muted">To Date</label>
            <input type="date" name="to_date" class="form-control" value="<?= h($toDate) ?>">
          </div>

          <div class="col-12 col-lg-3 d-flex justify-content-lg-end">
            <button class="btn btn-brand w-100 w-lg-auto" type="submit">Generate Report</button>
          </div>

        </div>
      </form>
    </div>
  </div>

  <!-- KPI ROW (matches low-fi order) -->
  <div class="row g-4 mb-4">
    <div class="col-12 col-md-4">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Issues</div>
        <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Resolved</div>
        <div class="fs-3 fw-bold"><?= $resolvedIssues ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Avg Resolution Time</div>
        <div class="fs-3 fw-bold"><?= h((string)$avgResolution) ?></div>
      </div>
    </div>
  </div>

  <!-- TWO CHART BOXES (matches low-fi) -->
  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Issue Distribution by Category</h5>
        <canvas id="catChart" height="180"></canvas>
        <?php if (empty($byCategory)): ?>
          <div class="text-muted small mt-2">No data for this filter.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-12 col-lg-6">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Resolution Time Analysis</h5>
        <canvas id="rtChart" height="180"></canvas>
        <?php if (empty($resTimes)): ?>
          <div class="text-muted small mt-2">No resolved issues for this filter.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- HEATMAP PLACEHOLDER (matches low-fi big box) -->
  <div class="card-dark p-4 mb-4">
    <h5 class="fw-semibold mb-3">Issue Density Heatmap</h5>

    <div class="ratio ratio-21x9" style="border-radius: 14px; overflow:hidden;">
      <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
        <div class="text-center">
          <div class="text-muted">Heatmap Placeholder</div>
          <div class="small text-muted">We will add OpenStreetMap/Leaflet heat layer later.</div>
        </div>
      </div>
    </div>

    <div class="mt-3 d-flex justify-content-end">
      <?php
        $qs = $_GET;
        $qs['download'] = 'csv';
        $downloadUrl = BASE_URL . '/admin/analytics.php?' . http_build_query($qs);
      ?>
      <a class="btn btn-outline-brand" href="<?= h($downloadUrl) ?>">
        Download Report (CSV)
      </a>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(() => {
  const catLabels = <?= json_encode($catLabels) ?>;
  const catData   = <?= json_encode($catData) ?>;

  const rtLabels = <?= json_encode($rtLabels) ?>;
  const rtData   = <?= json_encode($rtData) ?>;

  // Category chart
  const cEl = document.getElementById('catChart');
  if (cEl && catLabels.length) {
    new Chart(cEl, {
      type: 'bar',
      data: { labels: catLabels, datasets: [{ data: catData }] },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  // Resolution time chart
  const rEl = document.getElementById('rtChart');
  if (rEl && rtLabels.length) {
    new Chart(rEl, {
      type: 'bar',
      data: { labels: rtLabels, datasets: [{ data: rtData }] },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>