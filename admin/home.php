<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['admin']);

$page_title = 'Admin Home - Fixly';
require_once __DIR__ . '/../includes/header.php';

$navApp = __DIR__ . '/../includes/navbar_app.php';
if (file_exists($navApp)) {
    require_once $navApp;
} else {
    require_once __DIR__ . '/../includes/navbar.php';
}

// ── Area filter ──
$areaId = (int)($_GET['area_id'] ?? 0);
$areas  = $pdo->query("SELECT area_id, area_name FROM areas ORDER BY area_name")->fetchAll(PDO::FETCH_ASSOC);

$areaName = 'All Branches';
foreach ($areas as $a) {
    if ((int)$a['area_id'] === $areaId) { $areaName = $a['area_name']; break; }
}

// ── Helpers ──
function countIssuesByStatus(PDO $pdo, array $statuses, int $areaId = 0): int {
    if (empty($statuses)) return 0;
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $params = $statuses;
    $areaSql = '';
    if ($areaId > 0) { $areaSql = " AND area_id = ?"; $params[] = $areaId; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM issues WHERE status IN ($in)$areaSql");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── KPI Stats (area-filtered) ──
$totalUsersSql = $areaId > 0
    ? "SELECT COUNT(*) FROM users WHERE area_id = ?"
    : "SELECT COUNT(*) FROM users";
$st = $pdo->prepare($totalUsersSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$totalUsers = (int)$st->fetchColumn();

$totalIssuesSql = $areaId > 0
    ? "SELECT COUNT(*) FROM issues WHERE area_id = ?"
    : "SELECT COUNT(*) FROM issues";
$st = $pdo->prepare($totalIssuesSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$totalIssues = (int)$st->fetchColumn();

$pending   = countIssuesByStatus($pdo, ['PENDING'],              $areaId);
$inProg    = countIssuesByStatus($pdo, ['IN_PROGRESS'],          $areaId);
$completed = countIssuesByStatus($pdo, ['COMPLETED', 'CLOSED'],   $areaId);

// ── Latest issues (area-filtered) ──
$latestSql = "
    SELECT i.issue_id, i.title, i.status, i.created_at,
           a.area_name, c.category_name
    FROM issues i
    LEFT JOIN areas a ON a.area_id = i.area_id
    LEFT JOIN issue_categories c ON c.category_id = i.category_id
    " . ($areaId > 0 ? "WHERE i.area_id = ?" : "") . "
    ORDER BY i.created_at DESC
    LIMIT 5
";
$st = $pdo->prepare($latestSql);
$areaId > 0 ? $st->execute([$areaId]) : $st->execute();
$latest = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Map markers (ALL issues, NO FILTER) ──
// IMPORTANT: Change i.lat / i.lng to your actual issues table columns if different.
// Examples:
//   i.latitude AS lat, i.longitude AS lng
//   i.gps_lat AS lat,  i.gps_lng AS lng
$st = $pdo->prepare("
  SELECT
    i.issue_id,
    i.title,
    i.status,
    i.is_common,
    ca.area_name AS common_area_name,
    a.area_name,
    i.lat AS lat,     -- TODO: change column name if needed
    i.lng AS lng      -- TODO: change column name if needed
  FROM issues i
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN common_areas ca ON ca.common_area_id = i.common_area_id
  ORDER BY i.created_at DESC, i.issue_id DESC
  LIMIT 1500
");
$st->execute();
$mapRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$mapMarkers = [];
foreach ($mapRows as $r) {
    $lat = isset($r['lat']) ? (float)$r['lat'] : 0.0;
    $lng = isset($r['lng']) ? (float)$r['lng'] : 0.0;
    if (!$lat || !$lng) continue;

    $mapMarkers[] = [
        'issue_id' => (int)$r['issue_id'],
        'title' => (string)$r['title'],
        'status' => (string)$r['status'],
        'area_name' => (string)($r['area_name'] ?? ''),
        'is_common' => (int)($r['is_common'] ?? 0),
        'common_area_name' => $r['common_area_name'] ?? null,
        'lat' => $lat,
        'lng' => $lng,
    ];
}
?>

<!-- OpenStreet Map / Leaflet -->
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

<style>
  #issuesMapAdminHome { width: 100%; height: 100%; min-height: 420px; }
  .leaflet-popup-content-wrapper,
  .leaflet-popup-tip {
    background: rgba(10, 20, 25, 0.95);
    color: #e8f1f1;
    border: 1px solid rgba(241,246,246,0.15);
  }
  .leaflet-popup-content { margin: 10px 12px; }
  .status-marker {
    width: 14px; height: 14px;
    border-radius: 999px;
    border: 2px solid rgba(255,255,255,0.9);
    box-shadow: 0 0 0 6px rgba(0,0,0,0.18);
  }
</style>

<div class="container py-4 app-container">

  <!-- Header -->
  <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-4">
    <div>
      <h2 class="fw-bold mb-1">Admin Dashboard</h2>
      <div class="text-muted small">System overview — <span class="fw-semibold"><?= h($areaName) ?></span></div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">Manage Users</a>
      <a class="btn btn-brand"         href="<?= BASE_URL ?>/admin/manage_issues.php">Manage Issues</a>
    </div>
  </div>

  <!-- Branch Filter -->
  <div class="card-dark p-3 mb-4">
    <form method="GET" class="d-flex flex-wrap gap-3 align-items-end">
      <div>
        <label class="form-label mb-1 small">Filter by Branch</label>
        <select name="area_id" class="form-select form-select-sm" style="min-width:200px;">
          <option value="0">All Branches</option>
          <?php foreach ($areas as $a): ?>
            <option value="<?= (int)$a['area_id'] ?>" <?= ((int)$a['area_id'] === $areaId) ? 'selected' : '' ?>>
              <?= h($a['area_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-brand btn-sm" type="submit">Apply</button>
      <?php if ($areaId > 0): ?>
        <a class="btn btn-outline-brand btn-sm" href="<?= BASE_URL ?>/admin/home.php">Reset</a>
      <?php endif; ?>
    </form>
  </div>

  <!-- KPI Cards — now 5 cards including In Progress -->
  <div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Users</div>
        <div class="fs-3 fw-bold"><?= $totalUsers ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Total Issues</div>
        <div class="fs-3 fw-bold"><?= $totalIssues ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Pending</div>
        <div class="fs-3 fw-bold" style="color:var(--accent-600);"><?= $pending ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">In Progress</div>
        <div class="fs-3 fw-bold text-primary"><?= $inProg ?></div>
      </div>
    </div>
    <div class="col-6 col-md-4 col-lg">
      <div class="card-dark p-3 text-center">
        <div class="text-muted small">Completed</div>
        <div class="fs-3 fw-bold text-success"><?= $completed ?></div>
      </div>
    </div>
  </div>

  <!-- Map: All Issues (NO FILTER) -->
  <div class="card-dark p-4 mb-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
      <h5 class="fw-semibold mb-0">All Issues Map</h5>
      <div class="small text-muted" id="mapMetaAdminHome">Loading issues…</div>
    </div>

    <div class="ratio ratio-21x9" style="border-radius: 14px; overflow:hidden;">
      <div id="issuesMapAdminHome"></div>
    </div>
  </div>

  <!-- Bottom row: Quick Actions + Latest Issues -->
  <div class="row g-3">
    <div class="col-12 col-lg-4">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Quick Actions</h5>
        <div class="d-grid gap-2">
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_users.php">View Users</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/add_user.php">Add New User</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/manage_issues.php">View Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/analytics.php">Analytics</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/admin/profile.php">My Profile</a>
        </div>
      </div>
    </div>

    <div class="col-12 col-lg-8">
      <div class="card-dark p-4 h-100">
        <h5 class="fw-semibold mb-3">Latest Issues
          <?php if ($areaId > 0): ?>
            <span class="badge ms-2" style="background:var(--accent-300);color:#1a1005;font-size:0.75rem;"><?= h($areaName) ?></span>
          <?php endif; ?>
        </h5>

        <?php if (empty($latest)): ?>
          <div class="text-muted">No issues yet.</div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-dark-custom align-middle mb-0">
              <thead>
                <tr>
                  <th style="width:80px;">ID</th>
                  <th>Title</th>
                  <th style="width:130px;">Status</th>
                  <th style="width:150px;">Created</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($latest as $r): ?>
                  <tr>
                    <td>
                      <a href="<?= BASE_URL ?>/admin/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>"
                         style="color:var(--accent-600); text-decoration:none;">
                        #<?= (int)$r['issue_id'] ?>
                      </a>
                    </td>
                    <td>
                      <div class="fw-semibold"><?= h($r['title']) ?></div>
                      <div class="small text-muted">
                        <?= h($r['category_name'] ?? '—') ?> • <?= h($r['area_name'] ?? '—') ?>
                      </div>
                    </td>
                    <td><span class="badge bg-secondary"><?= h($r['status']) ?></span></td>
                    <td class="text-muted small"><?= h($r['created_at']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>
(() => {
  const BASE = <?= json_encode(BASE_URL) ?>;
  const meta = document.getElementById('mapMetaAdminHome');
  const markers = <?= json_encode($mapMarkers) ?>;

  const SRI_LANKA_CENTER = [7.8731, 80.7718];
  const SRI_LANKA_ZOOM = 7;

  const map = L.map('issuesMapAdminHome', {
    zoomControl: true,
    scrollWheelZoom: true
  }).setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  const statusColor = (status) => {
    switch ((status || '').toUpperCase()) {
      case 'PENDING': return '#94a3b8';
      case 'ASSIGNED': return '#38bdf8';
      case 'IN_PROGRESS': return '#fbbf24';
      case 'COMPLETED': return '#22c55e';
      case 'CLOSED': return '#16a34a';
      case 'REOPENED': return '#f97316';
      case 'REJECTED': return '#ef4444';
      default: return '#94a3b8';
    }
  };

  const makeDotIcon = (color) => L.divIcon({
    className: '',
    html: `<div class="status-marker" style="background:${color}"></div>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
    popupAnchor: [0, -8]
  });

  const escHtml = (s) => {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
  };

  if (!Array.isArray(markers) || markers.length === 0) {
    meta.textContent = 'No issue coordinates found.';
    map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    return;
  }

  meta.textContent = `Showing ${markers.length} issue(s) on the map.`;

  const bounds = [];
  markers.forEach(m => {
    const lat = Number(m.lat);
    const lng = Number(m.lng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    const col = statusColor(m.status);
    const icon = makeDotIcon(col);

    const typeLabel = (Number(m.is_common) === 1)
      ? `<div class="small text-muted">Type: <b>Common</b>${m.common_area_name ? ` • Area: ${escHtml(m.common_area_name)}` : ''}</div>`
      : `<div class="small text-muted">Type: <b>Personal</b></div>`;

    const popup = `
      <div style="min-width:220px;">
        <div class="fw-semibold">#${Number(m.issue_id)} — ${escHtml(m.title)}</div>
        <div class="small">Status: <span style="color:${col}; font-weight:700;">${escHtml(m.status)}</span></div>
        <div class="small text-muted">Branch: <b>${escHtml(m.area_name || '—')}</b></div>
        ${typeLabel}
        <div class="mt-2">
          <a class="btn btn-sm btn-outline-brand" href="${BASE}/admin/view_issue.php?issue_id=${Number(m.issue_id)}">View</a>
        </div>
      </div>
    `;

    L.marker([lat, lng], { icon }).addTo(map).bindPopup(popup);
    bounds.push([lat, lng]);
  });

  if (bounds.length) map.fitBounds(bounds, { padding: [25, 25] });
  else map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>