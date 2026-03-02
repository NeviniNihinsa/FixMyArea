<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Analytics & Reports - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$areaId   = (int)($_GET['area_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));

$errors = [];

$validDate = function(string $d): bool {
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};

if (!$validDate($fromDate)) $errors[] = "Invalid From Date.";
if (!$validDate($toDate))   $errors[] = "Invalid To Date.";
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) $errors[] = "From Date must be before To Date.";

$areas = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

$where = [];
$params = [];

if ($areaId > 0) { $where[] = "i.area_id = ?"; $params[] = $areaId; }
if ($fromDate !== '') { $where[] = "i.created_at >= ?"; $params[] = $fromDate . " 00:00:00"; }
if ($toDate !== '')   { $where[] = "i.created_at <= ?"; $params[] = $toDate . " 23:59:59"; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// Resolve area name for report header
$areaName = 'All Areas';
if ($areaId > 0) {
  foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = (string)$a['area_name']; break; }
  }
}

$periodText = 'Any time period';
if ($fromDate !== '' && $toDate !== '') $periodText = $fromDate . " to " . $toDate;
elseif ($fromDate !== '') $periodText = "From " . $fromDate;
elseif ($toDate !== '') $periodText = "Up to " . $toDate;

// KPI 1: Total Issues
$st = $pdo->prepare("SELECT COUNT(*) FROM issues i {$whereSql}");
$st->execute($params);
$totalIssues = (int)$st->fetchColumn();

// KPI 2: Resolved Issues
$st = $pdo->prepare(
  "SELECT COUNT(*) FROM issues i {$whereSql}" .
  ($whereSql ? " AND " : " WHERE ") .
  " i.status IN ('COMPLETED','CLOSED')"
);
$st->execute($params);
$resolvedIssues = (int)$st->fetchColumn();

// KPI 3: Avg Resolution Time (days)
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
" . ($whereSql ? " AND " : " WHERE ") . " i.status IN ('COMPLETED','CLOSED')
";
$st = $pdo->prepare($sqlAvg);
$st->execute($params);
$avgResolution = (float)($st->fetchColumn() ?? 0.0);
if ($avgResolution < 0) $avgResolution = 0.0;
$avgResolution = round($avgResolution, 1);

// Chart 1: Issue Distribution by Category
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

// Chart 2: Resolution Time Analysis buckets
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
" . ($whereSql ? " AND " : " WHERE ") . " i.status IN ('COMPLETED','CLOSED')
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

// A small narrative helper for “human readable” report
$resolvedRate = ($totalIssues > 0) ? round(($resolvedIssues / $totalIssues) * 100, 1) : 0.0;
$topCategoryText = 'N/A';
if (!empty($byCategory)) {
  $topCategoryText = (string)$byCategory[0]['label'] . " (" . (int)$byCategory[0]['c'] . ")";
}

// ------- DOWNLOADS -------
$download = (string)($_GET['download'] ?? '');

// 1) CSV download (raw data)
if ($download === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="analytics_report.csv"');

  $out = fopen('php://output', 'w');

  fputcsv($out, ['FixMyArea Analytics Report (DATA EXPORT)']);
  fputcsv($out, ['Area', $areaName]);
  fputcsv($out, ['Period', $periodText]);
  fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
  fputcsv($out, []);

  fputcsv($out, ['KPI Summary']);
  fputcsv($out, ['Total Issues', $totalIssues]);
  fputcsv($out, ['Resolved Issues', $resolvedIssues]);
  fputcsv($out, ['Resolved Rate (%)', $resolvedRate]);
  fputcsv($out, ['Avg Resolution Time (days)', $avgResolution]);
  fputcsv($out, []);

  fputcsv($out, ['Issue Distribution by Category']);
  fputcsv($out, ['Category', 'Count']);
  foreach ($byCategory as $row) {
    fputcsv($out, [$row['label'], $row['c']]);
  }
  fputcsv($out, []);

  fputcsv($out, ['Resolution Time Analysis']);
  fputcsv($out, ['Range', 'Count']);
  foreach ($buckets as $k => $v) {
    fputcsv($out, [$k, $v]);
  }

  fclose($out);
  exit;
}

// 2) Printable “PDF-style” HTML download (sections + page breaks)
if ($download === 'print') {
  // Standalone print HTML
  header('Content-Type: text/html; charset=utf-8');
  ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>FixMyArea - Analytics Report</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    /* Print-friendly report styling */
    :root{
      --ink:#111;
      --muted:#555;
      --line:#ddd;
    }
    body{
      font-family: Arial, Helvetica, sans-serif;
      color: var(--ink);
      margin: 24px;
    }
    .report-header{
      border-bottom: 2px solid var(--ink);
      padding-bottom: 12px;
      margin-bottom: 18px;
    }
    .title{
      font-size: 22px;
      font-weight: 700;
      margin: 0;
    }
    .meta{
      margin-top: 6px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }
    h2{
      font-size: 16px;
      margin: 18px 0 8px;
    }
    p{
      margin: 6px 0 10px;
      color: var(--ink);
      font-size: 13.5px;
      line-height: 1.55;
    }
    .kpi-grid{
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 10px;
      margin-top: 10px;
    }
    .kpi{
      border: 1px solid var(--line);
      padding: 10px;
      border-radius: 8px;
    }
    .kpi .label{ color: var(--muted); font-size: 12px; }
    .kpi .value{ font-size: 18px; font-weight: 700; margin-top: 6px; }
    table{
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
      font-size: 13px;
    }
    th, td{
      border: 1px solid var(--line);
      padding: 8px;
      text-align: left;
    }
    th{ background: #f5f5f5; }
    .small{ font-size: 12px; color: var(--muted); }
    .page-break{
      page-break-after: always;
      break-after: page;
    }

    @media print{
      body{ margin: 14mm; }
      .no-print{ display:none !important; }
      .kpi-grid{ grid-template-columns: repeat(2, 1fr); }
    }
  </style>
</head>
<body>

  <div class="no-print" style="margin-bottom:14px;">
    <button onclick="window.print()">Print / Save as PDF</button>
  </div>

  <div class="report-header">
    <p class="title">FixMyArea Analytics & Reports</p>
    <div class="meta">
      <div><strong>Area:</strong> <?= h($areaName) ?></div>
      <div><strong>Period:</strong> <?= h($periodText) ?></div>
      <div><strong>Generated at:</strong> <?= h(date('Y-m-d H:i:s')) ?></div>
    </div>
  </div>

  <h2>1. Executive Summary</h2>
  <p>
    During the selected period, the system recorded <strong><?= (int)$totalIssues ?></strong> issues.
    Out of these, <strong><?= (int)$resolvedIssues ?></strong> were resolved
    (<strong><?= h((string)$resolvedRate) ?>%</strong> resolution rate).
    The average resolution time was <strong><?= h((string)$avgResolution) ?></strong> days.
    The most reported category was <strong><?= h($topCategoryText) ?></strong>.
  </p>

  <h2>2. Key Performance Indicators (KPIs)</h2>
  <div class="kpi-grid">
    <div class="kpi">
      <div class="label">Total Issues</div>
      <div class="value"><?= (int)$totalIssues ?></div>
    </div>
    <div class="kpi">
      <div class="label">Resolved Issues</div>
      <div class="value"><?= (int)$resolvedIssues ?></div>
    </div>
    <div class="kpi">
      <div class="label">Resolved Rate</div>
      <div class="value"><?= h((string)$resolvedRate) ?>%</div>
    </div>
    <div class="kpi">
      <div class="label">Avg Resolution Time</div>
      <div class="value"><?= h((string)$avgResolution) ?> days</div>
    </div>
  </div>

  <div class="page-break"></div>

  <h2>3. Issue Distribution by Category</h2>
  <?php if (empty($byCategory)): ?>
    <p class="small">No category data available for the selected filter.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Category</th><th>Count</th></tr>
      </thead>
      <tbody>
        <?php foreach ($byCategory as $row): ?>
          <tr>
            <td><?= h($row['label']) ?></td>
            <td><?= (int)$row['c'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>4. Resolution Time Analysis</h2>
  <?php if (empty($resTimes)): ?>
    <p class="small">No resolved issues found for the selected filter.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Time Range</th><th>Resolved Count</th></tr>
      </thead>
      <tbody>
        <?php foreach ($buckets as $range => $count): ?>
          <tr>
            <td><?= h($range) ?></td>
            <td><?= (int)$count ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="page-break"></div>

  <h2>5. Notes / Limitations</h2>
  <p>
    - Resolution time is calculated from issue creation to the latest status change marked COMPLETED/CLOSED.<br>
    - Filters (Area and Date range) apply to issue creation time.<br>
    - This report is intended for academic demonstration and system monitoring.
  </p>

</body>
</html>
  <?php
  exit;
}

// ---------- NORMAL PAGE UI (Dashboard view) ----------
?>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Analytics & Reports</h2>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?= h(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <!-- FILTER BOX -->
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

          <div class="col-12 col-lg-3 d-flex justify-content-lg-end gap-2">
            <button class="btn btn-brand w-100 w-lg-auto" type="submit">Generate</button>
            <?php
              $qsCsv = $_GET;
              $qsCsv['download'] = 'csv';
              $downloadCsvUrl = BASE_URL . '/admin/analytics.php?' . http_build_query($qsCsv);

              $qsPrint = $_GET;
              $qsPrint['download'] = 'print';
              $downloadPrintUrl = BASE_URL . '/admin/analytics.php?' . http_build_query($qsPrint);
            ?>
            <!-- <a class="btn btn-outline-brand w-100 w-lg-auto" href="<?= h($downloadCsvUrl) ?>">CSV</a> -->
            <a class="btn btn-outline-light w-100 w-lg-auto" href="<?= h($downloadPrintUrl) ?>" target="_blank">Donload Report</a>
          </div>

        </div>
      </form>
    </div>
  </div>

  <!-- KPI ROW  -->
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

  <!-- TWO CHART BOXES -->
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

  <!-- HEATMAP PLACEHOLDER -->
  <div class="card-dark p-4 mb-4">
    <h5 class="fw-semibold mb-2">Issue Density Heatmap</h5>
    <div class="text-muted small mb-3">
      Placeholder — add OpenStreetMap/Leaflet heat layer later.
    </div>

    <div class="ratio ratio-21x9" style="border-radius: 14px; overflow:hidden;">
      <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
        <div class="text-center">
          <div class="text-muted">Heatmap Placeholder</div>
          <div class="small text-muted">We will add OpenStreetMap/Leaflet heat layer later.</div>
        </div>
      </div>
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

  const cEl = document.getElementById('catChart');
  if (cEl && catLabels.length) {
    new Chart(cEl, {
      type: 'bar',
      data: { labels: catLabels, datasets: [{ data: catData }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
  }

  const rEl = document.getElementById('rtChart');
  if (rEl && rtLabels.length) {
    new Chart(rEl, {
      type: 'bar',
      data: { labels: rtLabels, datasets: [{ data: rtData }] },
      options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
    });
  }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>