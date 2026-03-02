<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header("Location: " . BASE_URL . "/auth/login.php");
    exit;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Get citizen info + branch */
$st = $pdo->prepare("
  SELECT u.area_id, u.name, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? 'Not set');
$citizenName = (string)($me['name'] ?? ($_SESSION['name'] ?? 'Citizen'));

/* Stats (branch totals + personal totals) */
$building = ['total_reported' => 0, 'total_fixed' => 0];
$mine     = ['my_reported' => 0, 'my_fixed' => 0];

if ($myAreaId > 0) {
    $st = $pdo->prepare("
      SELECT
        COUNT(*) AS total_reported,
        SUM(CASE WHEN status IN ('COMPLETED','CLOSED') THEN 1 ELSE 0 END) AS total_fixed
      FROM issues
      WHERE area_id = ?
        AND (
          is_common = 1
          OR (is_common = 0 AND reporter_user_id = ?)
        )
    ");
    $st->execute([$myAreaId, $userId]);
    $building = $st->fetch(PDO::FETCH_ASSOC) ?: $building;
}

// My totals 
$st = $pdo->prepare("
  SELECT
    COUNT(*) AS my_reported,
    SUM(CASE WHEN status IN ('COMPLETED','CLOSED') THEN 1 ELSE 0 END) AS my_fixed
  FROM issues
  WHERE reporter_user_id = ?
");
$st->execute([$userId]);
$mine = $st->fetch(PDO::FETCH_ASSOC) ?: $mine;

/*  Recent issues  */
$recent = [];
if ($myAreaId > 0) {
    $st = $pdo->prepare("
        SELECT issue_id, title, status, created_at
        FROM issues
        WHERE area_id = ?
          AND (
            is_common = 1
            OR (is_common = 0 AND reporter_user_id = ?)
          )
        ORDER BY created_at DESC, issue_id DESC
        LIMIT 3
    ");
    $st->execute([$myAreaId, $userId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Leaflet (map) -->
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
  
  #issuesMap {
    width: 100%;
    height: 100%;
    min-height: 360px; 
  }
  /* leaflet popups */
  .leaflet-popup-content-wrapper,
  .leaflet-popup-tip {
    background: rgba(10, 20, 25, 0.95);
    color: #e8f1f1;
    border: 1px solid rgba(241,246,246,0.15);
  }
  .leaflet-popup-content {
    margin: 10px 12px;
  }
  .leaflet-control-attribution {
    font-size: 11px;
  }

  /* Small status-dot inside marker */
  .status-marker {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    border: 2px solid rgba(255,255,255,0.9);
    box-shadow: 0 0 0 6px rgba(0,0,0,0.18);
  }
</style>

<div class="container py-4">

  <h2 class="fw-bold mb-4">Welcome <?= h($citizenName) ?></h2>

  <div class="row g-4">
    <!-- LEFT: Map -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-3">
        <div class="ratio ratio-4x3" style="border-radius: 12px; overflow:hidden;">
          <!-- MAP -->
          <div id="issuesMap"></div>
        </div>

        <div class="mt-3 d-flex flex-wrap gap-2">
          <a class="btn btn-brand" href="<?= BASE_URL ?>/citizen/report_issue.php">Report an Issue</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/track_issue.php">Track Issues</a>
          <a class="btn btn-outline-brand" href="<?= BASE_URL ?>/citizen/community.php">Community</a>
        </div>

        <div class="mt-2 small text-muted" id="mapMetaText">
          Loading issues on the map…
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">

        <!-- Building totals (branch totals) -->
        <div class="row g-3">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Issues</div>
              <div class="fs-3 fw-bold"><?= (int)$building['total_reported'] ?></div>
              <div class="small text-muted">Visible in your branch</div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Reported Fixed</div>
              <div class="fs-3 fw-bold"><?= (int)$building['total_fixed'] ?></div>
              <div class="small text-muted">Visible in your branch</div>
            </div>
          </div>
        </div>

        <hr style="border-color: rgba(241,246,246,0.10);" class="my-4">

        <!--  read only branch -->
        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
          <div class="text-muted">Issues reported in</div>
          <input
            type="text"
            class="form-control"
            style="max-width: 260px; opacity: 0.9;"
            value="<?= h($myAreaName) ?>"
            readonly
          >
        </div>

        <!-- Tenant totals (my issues) -->
        <div class="row g-3 mt-1">
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Reported</div>
              <div class="fs-3 fw-bold"><?= (int)$mine['my_reported'] ?></div>
              <div class="small text-muted">By you</div>
            </div>
          </div>
          <div class="col-12 col-md-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Issues Fixed</div>
              <div class="fs-3 fw-bold"><?= (int)$mine['my_fixed'] ?></div>
              <div class="small text-muted">By you</div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Recent Issues -->
  <div class="mt-4 card-dark p-4">
    <h4 class="fw-semibold mb-3">Recent Issues</h4>

    <?php if ($myAreaId <= 0): ?>
      <div class="text-muted">Your branch is not set. Please update your profile and select a branch.</div>
    <?php elseif (empty($recent)): ?>
      <div class="text-muted">No issues found for your branch yet.</div>
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
                <td><?= h($r['title']) ?></td>
                <td><span class="badge bg-secondary"><?= h($r['status']) ?></span></td>
                <td class="text-muted"><?= h($r['created_at']) ?></td>
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

<script>
(() => {
  const BASE = <?= json_encode(BASE_URL) ?>;
  const mapMetaText = document.getElementById('mapMetaText');

  // Default view if no markers- Sri Lanka
  const SRI_LANKA_CENTER = [7.8731, 80.7718];
  const SRI_LANKA_ZOOM = 7;

  // Create map
  const map = L.map('issuesMap', {
    zoomControl: true,
    scrollWheelZoom: true
  }).setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);

  // OpenStreetMap tiles
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(map);

  // Status -> color (simple, clear)
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

  // Create a small colored-dot marker icon
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

  // Load markers from your API (already filtered: common in branch + my personal)
  fetch(BASE + '/actions/map_issues.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.ok) throw new Error(data?.error || 'Failed to load');

      const markers = Array.isArray(data.markers) ? data.markers : [];

      mapMetaText.textContent = `Showing ${markers.length} issue(s) on the map for your branch (common + your personal).`;

      if (markers.length === 0) {
        // keep Sri Lanka view
        map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
        return;
      }

      const bounds = [];

      markers.forEach(m => {
        const lat = Number(m.lat);
        const lng = Number(m.lng);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const col = statusColor(m.status);
        const icon = makeDotIcon(col);

        const isCommon = Number(m.is_common) === 1;
        const commonLabel = isCommon
          ? `<div class="small text-muted">Type: <b>Common</b>${m.common_area_name ? ` • Area: ${escHtml(m.common_area_name)}` : ''}</div>`
          : `<div class="small text-muted">Type: <b>Personal</b></div>`;

        const popup = `
          <div style="min-width:220px;">
            <div class="fw-semibold">#${Number(m.issue_id)} — ${escHtml(m.title)}</div>
            <div class="small">Status: <span style="color:${col}; font-weight:700;">${escHtml(m.status)}</span></div>
            ${commonLabel}
            <div class="small text-muted">Created: ${escHtml(m.created_at)}</div>
            <div class="mt-2">
              <a class="btn btn-sm btn-outline-brand" href="${BASE}/citizen/issue_view.php?issue_id=${Number(m.issue_id)}">View</a>
            </div>
          </div>
        `;

        L.marker([lat, lng], { icon }).addTo(map).bindPopup(popup);
        bounds.push([lat, lng]);
      });

      // Auto zoom to all markers
      if (bounds.length) {
        map.fitBounds(bounds, { padding: [25, 25] });
      } else {
        map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
      }
    })
    .catch(() => {
      mapMetaText.textContent = 'Could not load issues for the map.';
      map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>