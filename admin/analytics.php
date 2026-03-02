<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Analytics & Reports - Fixly';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$areaId   = (int)($_GET['area_id'] ?? 0);
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate   = trim((string)($_GET['to_date'] ?? ''));
$locType  = trim((string)($_GET['loc_type'] ?? '')); // '' | 'common' | 'unit'

$errors = [];

$validDate = function(string $d): bool {
  if ($d === '') return true;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
};

if (!$validDate($fromDate)) $errors[] = "Invalid From Date.";
if (!$validDate($toDate))   $errors[] = "Invalid To Date.";
if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) $errors[] = "From Date must be before To Date.";

$areas       = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);
$commonAreas = $pdo->query("SELECT common_area_id, area_name FROM common_areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

// Resolve selected area name
$selectedAreaName = 'All';
foreach ($areas as $a) {
  if ((int)$a['area_id'] === $areaId) { $selectedAreaName = $a['area_name']; break; }
}

$where  = [];
$params = [];

if ($areaId > 0)      { $where[] = "i.area_id = ?";     $params[] = $areaId; }
if ($fromDate !== '') { $where[] = "i.created_at >= ?"; $params[] = $fromDate . " 00:00:00"; }
if ($toDate !== '')   { $where[] = "i.created_at <= ?"; $params[] = $toDate . " 23:59:59"; }
if ($locType === 'common') { $where[] = "i.is_common = 1"; }
if ($locType === 'unit')   { $where[] = "i.is_common = 0"; }

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/** KPI 1: Total Issues */
$st = $pdo->prepare("SELECT COUNT(*) FROM issues i {$whereSql}");
$st->execute($params);
$totalIssues = (int)$st->fetchColumn();

/** KPI 2: Resolved Issues */
$st = $pdo->prepare("SELECT COUNT(*) FROM issues i {$whereSql}" . ($whereSql ? " AND " : " WHERE ") . "i.status IN ('COMPLETED','CLOSED')");
$st->execute($params);
$resolvedIssues = (int)$st->fetchColumn();

/** KPI 3: Pipeline breakdown */
$sqlPipeline = "
SELECT i.status, COUNT(*) AS c
FROM issues i
{$whereSql}
" . ($whereSql ? "AND" : "WHERE") . " i.status NOT IN ('COMPLETED','CLOSED','REJECTED')
GROUP BY i.status
";
$st = $pdo->prepare($sqlPipeline);
$st->execute($params);
$pipelineRows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$pendingCount    = (int)($pipelineRows['PENDING']     ?? 0);
$assignedCount   = (int)($pipelineRows['ASSIGNED']    ?? 0);
$inProgressCount = (int)($pipelineRows['IN_PROGRESS'] ?? 0);
$reopenedCount   = (int)($pipelineRows['REOPENED']    ?? 0);

/** KPI 4: Avg Resolution Time (days) */
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

/** Chart 1: Issue Distribution by Category */
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
$catLabels  = array_map(fn($r) => (string)$r['label'], $byCategory);
$catData    = array_map(fn($r) => (int)$r['c'], $byCategory);

/** Chart 2: Resolution Time Analysis */
$buckets = [
  '0-2 days'   => 0,
  '3-7 days'   => 0,
  '8-14 days'  => 0,
  '15-30 days' => 0,
  '31+ days'   => 0,
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
  if ($d <= 2)       $buckets['0-2 days']++;
  elseif ($d <= 7)   $buckets['3-7 days']++;
  elseif ($d <= 14)  $buckets['8-14 days']++;
  elseif ($d <= 30)  $buckets['15-30 days']++;
  else               $buckets['31+ days']++;
}
$rtLabels = array_keys($buckets);
$rtData   = array_values($buckets);

/** Chart 3: Common Area vs Tenant Unit split */
// Only filter by area/date — NOT by locType so the donut always shows both halves
$splitWhere  = [];
$splitParams = [];
if ($areaId > 0)       { $splitWhere[] = "area_id = ?";       $splitParams[] = $areaId; }
if ($fromDate !== '')  { $splitWhere[] = "created_at >= ?";   $splitParams[] = $fromDate . " 00:00:00"; }
if ($toDate !== '')    { $splitWhere[] = "created_at <= ?";   $splitParams[] = $toDate . " 23:59:59"; }
$splitWhereSql = $splitWhere ? ("WHERE " . implode(" AND ", $splitWhere)) : "";

$st = $pdo->prepare("SELECT SUM(is_common = 1) AS common_count, SUM(is_common = 0) AS unit_count FROM issues {$splitWhereSql}");
$st->execute($splitParams);
$splitRow    = $st->fetch(PDO::FETCH_ASSOC);
$commonCount = (int)($splitRow['common_count'] ?? 0);
$unitCount   = (int)($splitRow['unit_count']   ?? 0);

/** Chart 4: Issues by Common Area */
$sqlCommonAreaBreakdown = "
SELECT ca.area_name AS label, COUNT(*) AS c
FROM issues i
JOIN common_areas ca ON ca.common_area_id = i.common_area_id
{$whereSql}
" . ($whereSql ? "AND" : "WHERE") . " i.is_common = 1
GROUP BY ca.area_name
ORDER BY c DESC
";
$st = $pdo->prepare($sqlCommonAreaBreakdown);
$st->execute($params);
$byCommonArea = $st->fetchAll(PDO::FETCH_ASSOC);
$caLabels     = array_map(fn($r) => (string)$r['label'], $byCommonArea);
$caData       = array_map(fn($r) => (int)$r['c'], $byCommonArea);

/** PDF Download (print-to-pdf flow) */
$download = (string)($_GET['download'] ?? '');

if ($download === 'pdf') {
  // Build a nice filter label
  $filterLabel = [];
  $filterLabel[] = "Branch: " . ($selectedAreaName ?? 'All');
  $filterLabel[] = "Location: " . ($locType !== '' ? ucfirst($locType) : 'All');
  $filterLabel[] = "From: " . ($fromDate !== '' ? $fromDate : 'Any');
  $filterLabel[] = "To: " . ($toDate !== '' ? $toDate : 'Any');

  function hpdf($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

  ?>
  <!doctype html>
  <html>
  <head>
    <meta charset="utf-8">
    <title>FixMyArea Analytics Report</title>
    <style>
      body { font-family: Arial, sans-serif; color:#111; margin: 24px; }
      h1 { margin: 0 0 6px; font-size: 20px; }
      .meta { margin: 0 0 16px; color:#444; font-size: 12px; }
      .kpi { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 14px 0 18px; }
      .box { border: 1px solid #ddd; padding: 10px; border-radius: 8px; }
      .label { font-size: 11px; color:#666; }
      .value { font-size: 16px; font-weight: 700; margin-top: 4px; }
      table { width: 100%; border-collapse: collapse; margin-top: 8px; }
      th, td { border: 1px solid #ddd; padding: 8px; font-size: 12px; }
      th { background: #f2f2f2; text-align: left; }
      .section { margin-top: 18px; }
      @media print { .no-print { display: none !important; } body { margin: 0; } }
    </style>
  </head>
  <body>
    <div class="no-print" style="margin-bottom:12px;">
      <button onclick="window.print()">Print / Save as PDF</button>
    </div>

    <h1>FixMyArea Analytics Report</h1>
    <div class="meta">
      Generated: <?= date('Y-m-d H:i') ?><br>
      Filters: <?= hpdf(implode(" | ", $filterLabel)) ?>
    </div>

    <div class="kpi">
      <div class="box"><div class="label">Total Issues</div><div class="value"><?= (int)$totalIssues ?></div></div>
      <div class="box"><div class="label">Resolved</div><div class="value"><?= (int)$resolvedIssues ?></div></div>
      <div class="box"><div class="label">Avg Resolution Time</div><div class="value"><?= hpdf((string)$avgResolution) ?> days</div></div>
      <div class="box"><div class="label">Reopened</div><div class="value"><?= (int)$reopenedCount ?></div></div>
    </div>

    <div class="section">
      <h3>Active Pipeline</h3>
      <table>
        <thead><tr><th>Status</th><th>Count</th></tr></thead>
        <tbody>
          <tr><td>Pending</td><td><?= (int)$pendingCount ?></td></tr>
          <tr><td>Assigned</td><td><?= (int)$assignedCount ?></td></tr>
          <tr><td>In Progress</td><td><?= (int)$inProgressCount ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="section">
      <h3>Issue Distribution by Category</h3>
      <table>
        <thead><tr><th>Category</th><th>Count</th></tr></thead>
        <tbody>
        <?php if (empty($byCategory)): ?>
          <tr><td colspan="2">No data for this filter.</td></tr>
        <?php else: ?>
          <?php foreach ($byCategory as $row): ?>
            <tr>
              <td><?= hpdf($row['label'] ?? '') ?></td>
              <td><?= (int)($row['c'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="section">
      <h3>Resolution Time Analysis</h3>
      <table>
        <thead><tr><th>Range</th><th>Count</th></tr></thead>
        <tbody>
          <?php foreach ($buckets as $range => $count): ?>
            <tr><td><?= hpdf($range) ?></td><td><?= (int)$count ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <script>
      window.addEventListener('load', () => { window.print(); });
      window.addEventListener('afterprint', () => { window.close(); });
      setTimeout(() => { try { window.close(); } catch (e) {} }, 4000);
    </script>
  </body>
  </html>
  <?php
  exit;
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<!-- Leaflet + Heat plugin -->
<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin=""
>
<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin=""
></script>
<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>

<style>
  /* ✅ FIXED: id must match container below */
  #issuesMapAdmin { width: 100%; height: 100%; min-height: 420px; }
</style>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Analytics & Reports</h2>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <!-- FILTER BOX -->
  <div class="card-dark p-4 mb-4">
    <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
      <div class="fw-semibold">Filter by</div>
      <form method="GET" class="w-100">
        <div class="row g-3 align-items-end">

          <div class="col-12 col-md-4 col-lg-2">
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

          <div class="col-12 col-md-4 col-lg-2">
            <label class="form-label text-muted">Location Type</label>
            <select name="loc_type" class="form-select">
              <option value="">All</option>
              <option value="common" <?= ($locType === 'common') ? 'selected' : '' ?>>Common Area</option>
              <option value="unit"   <?= ($locType === 'unit')   ? 'selected' : '' ?>>Tenant Unit</option>
            </select>
          </div>

          <div class="col-12 col-md-4 col-lg-2">
            <label class="form-label text-muted">From Date</label>
            <input type="date" name="from_date" class="form-control" value="<?= h($fromDate) ?>">
          </div>

          <div class="col-12 col-md-4 col-lg-2">
            <label class="form-label text-muted">To Date</label>
            <input type="date" name="to_date" class="form-control" value="<?= h($toDate) ?>">
          </div>

          <div class="col-12 col-lg-4 d-flex justify-content-lg-end">
            <button class="btn btn-brand w-100 w-lg-auto" type="submit">Generate Report</button>
          </div>

        </div>
      </form>
    </div>
  </div>

  <!-- KPI ROW 1 -->
  <div class="row g-4 mb-3">
    <div class="col-6 col-md-3">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Issues</div>
        <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Resolved</div>
        <div class="fs-3 fw-bold text-success"><?= $resolvedIssues ?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Avg Resolution Time</div>
        <div class="fs-3 fw-bold"><?= h((string)$avgResolution) ?> <span class="fs-6 text-muted">days</span></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Reopened</div>
        <div class="fs-3 fw-bold text-warning"><?= $reopenedCount ?></div>
      </div>
    </div>
  </div>

  <!-- KPI ROW 2 -->
  <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card-dark p-3">
        <div class="text-muted small mb-2 fw-semibold">Active Pipeline</div>
        <div class="row g-3 text-center">
          <div class="col-4 col-md-2">
            <div class="text-muted small">Pending</div>
            <div class="fs-5 fw-bold text-secondary"><?= $pendingCount ?></div>
          </div>
          <div class="col-4 col-md-2">
            <div class="text-muted small">Assigned</div>
            <div class="fs-5 fw-bold" style="color:var(--bs-info)"><?= $assignedCount ?></div>
          </div>
          <div class="col-4 col-md-2">
            <div class="text-muted small">In Progress</div>
            <div class="fs-5 fw-bold text-primary"><?= $inProgressCount ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- CHARTS ROW 1 -->
  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Issue Distribution by Category</h5>
        <canvas id="catChart" height="200"></canvas>
        <?php if (empty($byCategory)): ?>
          <div class="text-muted small mt-2">No data for this filter.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Resolution Time Analysis</h5>
        <canvas id="rtChart" height="200"></canvas>
        <?php if (empty($resTimes)): ?>
          <div class="text-muted small mt-2">No resolved issues for this filter.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- CHARTS ROW 2 -->
  <div class="row g-4 mb-4">
    <div class="col-12 col-lg-5">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Common Area vs Tenant Unit</h5>
        <?php if ($commonCount + $unitCount === 0): ?>
          <div class="text-muted small">No data for this filter.</div>
        <?php else: ?>
          <canvas id="splitChart" height="220"></canvas>
          <div class="d-flex justify-content-center gap-4 mt-3 small text-muted">
            <span><span class="me-1" style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#ff914c;vertical-align:middle;"></span>Common Area (<?= $commonCount ?>)</span>
            <span><span class="me-1" style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#ffcc56;vertical-align:middle;"></span>Tenant Unit (<?= $unitCount ?>)</span>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-lg-7">
      <div class="card-dark p-4">
        <h5 class="fw-semibold mb-3">Issues by Common Area</h5>
        <?php if (empty($byCommonArea)): ?>
          <div class="text-muted small">No common area issues for this filter.</div>
        <?php else: ?>
          <canvas id="caChart" height="220"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- HEATMAP -->
  <div class="card-dark p-4 mb-4">
    <h5 class="fw-semibold mb-3">Issue Density Heatmap</h5>

    <div class="ratio ratio-21x9" style="border-radius: 14px; overflow:hidden;">
      <div id="issuesMapAdmin"></div>
    </div>

    <div class="mt-2 small text-muted" id="mapMetaAdmin">Loading heatmap…</div>

    <div class="mt-3 d-flex justify-content-end">
      <?php
        $qs = $_GET;
        $qs['download'] = 'pdf';
        $downloadUrl = 'analytics.php?' . http_build_query($qs); // relative
      ?>
      <a class="btn btn-outline-brand" href="<?= h($downloadUrl) ?>" target="_blank" rel="noopener">Download Report</a>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
(() => {
  const catLabels = <?= json_encode($catLabels) ?>;
  const catData   = <?= json_encode($catData) ?>;
  const rtLabels  = <?= json_encode($rtLabels) ?>;
  const rtData    = <?= json_encode($rtData) ?>;
  const caLabels  = <?= json_encode($caLabels) ?>;
  const caData    = <?= json_encode($caData) ?>;
  const splitData = [<?= $commonCount ?>, <?= $unitCount ?>];

  const gridColor  = 'rgba(255,145,76,0.12)';
  const tickColor  = '#a07840';
  const barColors  = ['#ff914c','#ffad52','#ffcc56','#f97316','#fb923c','#fbbf24','#d97706','#92400e'];

  function barOpts(horizontal = false) {
    return {
      responsive: true,
      indexAxis: horizontal ? 'y' : 'x',
      plugins: { legend: { display: false } },
      scales: {
        x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor } },
        y: { grid: { color: gridColor }, ticks: { color: tickColor } }
      }
    };
  }

  const cEl = document.getElementById('catChart');
  if (cEl && catLabels.length) {
    new Chart(cEl, {
      type: 'bar',
      data: { labels: catLabels, datasets: [{ data: catData, backgroundColor: catLabels.map((_,i) => barColors[i % barColors.length]) }] },
      options: barOpts()
    });
  }

  const rEl = document.getElementById('rtChart');
  if (rEl) {
    new Chart(rEl, {
      type: 'bar',
      data: { labels: rtLabels, datasets: [{ data: rtData, backgroundColor: '#ff914c' }] },
      options: barOpts()
    });
  }

  const sEl = document.getElementById('splitChart');
  if (sEl && (splitData[0] + splitData[1]) > 0) {
    new Chart(sEl, {
      type: 'doughnut',
      data: {
        labels: ['Common Area', 'Tenant Unit'],
        datasets: [{ data: splitData, backgroundColor: ['#ff914c', '#ffcc56'], borderWidth: 2, borderColor: 'rgba(0,0,0,0.3)' }]
      },
      options: {
        responsive: true,
        cutout: '65%',
        plugins: { legend: { display: false } }
      }
    });
  }

  const caEl = document.getElementById('caChart');
  if (caEl && caLabels.length) {
    new Chart(caEl, {
      type: 'bar',
      data: { labels: caLabels, datasets: [{ data: caData, backgroundColor: caLabels.map((_,i) => barColors[i % barColors.length]) }] },
      options: barOpts(true)
    });
  }
})();
</script>

<script>
(() => {
  const BASE = <?= json_encode(BASE_URL) ?>;

  const meta = document.getElementById('mapMetaAdmin');
  const mapEl = document.getElementById('issuesMapAdmin');
  if (!mapEl) return;

  const SRI_LANKA_CENTER = [7.8731, 80.7718];
  const SRI_LANKA_ZOOM = 7;

  const map = L.map('issuesMapAdmin', {
    zoomControl: true,
    scrollWheelZoom: true
  }).setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // use same filters currently on the page
  const params = new URLSearchParams({
    area_id: <?= (int)$areaId ?>,
    from_date: <?= json_encode($fromDate) ?>,
    to_date: <?= json_encode($toDate) ?>,
    loc_type: <?= json_encode($locType) ?>,
  });

  const intensityForStatus = (status) => {
    switch ((status || '').toUpperCase()) {
      case 'REOPENED': return 0.95;
      case 'IN_PROGRESS': return 0.85;
      case 'ASSIGNED': return 0.75;
      case 'PENDING': return 0.70;
      case 'COMPLETED':
      case 'CLOSED': return 0.45;
      case 'REJECTED': return 0.40;
      default: return 0.60;
    }
  };

  fetch(BASE + '/actions/map_issues_admin.php?' + params.toString(), { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.ok) throw new Error(data?.error || 'Failed');

      const markers = Array.isArray(data.markers) ? data.markers : [];
      meta.textContent = `Heatmap shows ${markers.length} issue point(s) for current filters.`;

      if (markers.length === 0) {
        map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
        return;
      }

      const heatPoints = [];
      const bounds = [];

      markers.forEach(m => {
        const lat = Number(m.lat);
        const lng = Number(m.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        heatPoints.push([lat, lng, intensityForStatus(m.status)]);
        bounds.push([lat, lng]);
      });

      const heat = L.heatLayer(heatPoints, {
        radius: 25,
        blur: 18,
        maxZoom: 17,
        minOpacity: 0.35
      }).addTo(map);

      if (bounds.length) map.fitBounds(bounds, { padding: [25, 25] });
      else map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    })
    .catch(() => {
      meta.textContent = 'Could not load issues for the heatmap.';
      map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>