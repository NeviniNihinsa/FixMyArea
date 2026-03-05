<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['local authority', 'authority']);

if (session_status() === PHP_SESSION_NONE) session_start();

$page_title = 'Authority Dashboard - FixMyArea';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

function h($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* Load authority + area */
$st = $pdo->prepare("
  SELECT u.name, u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$userName = (string)($me['name'] ?? 'Local Authority');
$areaId   = (int)($me['area_id'] ?? 0);
$areaName = (string)($me['area_name'] ?? '—');

/* Stats for this area */
$stats = [
  'total'       => 0,
  'completed'   => 0,
  'in_progress' => 0,
  'pending'     => 0,
];

if ($areaId > 0) {
  $st = $pdo->prepare("
    SELECT
      COUNT(*) AS total,
      SUM(status IN ('COMPLETED','CLOSED')) AS completed,
      SUM(status IN ('IN_PROGRESS','ASSIGNED')) AS in_progress,
      SUM(status = 'PENDING') AS pending
    FROM issues
    WHERE area_id = ?
  ");
  $st->execute([$areaId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $stats['total']       = (int)($row['total'] ?? 0);
  $stats['completed']   = (int)($row['completed'] ?? 0);
  $stats['in_progress'] = (int)($row['in_progress'] ?? 0);
  $stats['pending']     = (int)($row['pending'] ?? 0);
}

/* Recently updates */
$recent = [];
if ($areaId > 0) {
  $st = $pdo->prepare("
    SELECT
      i.issue_id,
      i.title,
      i.status,
      i.created_at,
      a.area_name,
      c.category_name,
      urep.email AS reporter_email,
      uw.name AS worker_name,
      uw.email AS worker_email
    FROM issues i
    JOIN areas a ON a.area_id = i.area_id
    LEFT JOIN issue_categories c ON c.category_id = i.category_id
    JOIN users urep ON urep.user_id = i.reporter_user_id

    /* latest assignment (if exists) */
    LEFT JOIN assignments asg ON asg.assignment_id = (
      SELECT a2.assignment_id
      FROM assignments a2
      WHERE a2.issue_id = i.issue_id
      ORDER BY a2.assigned_at DESC
      LIMIT 1
    )
    LEFT JOIN users uw ON uw.user_id = asg.field_worker_id

    WHERE i.area_id = ?
    ORDER BY i.created_at DESC, i.issue_id DESC
    LIMIT 8
  ");
  $st->execute([$areaId]);
  $recent = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Leaflet (OpenStreetMap) -->
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
  #issuesMapAuthority { width: 100%; height: 100%; min-height: 320px; }
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

  <div class="mb-4">
    <h2 class="fw-bold mb-1">Welcome <?= h($userName) ?></h2>
  </div>

  <div class="row g-4">
    <!-- Map -->
    <div class="col-12 col-lg-7">
      <div class="card-dark p-4" style="min-height:320px;">
        <div style="border-radius: 14px; overflow:hidden;">
          <div id="issuesMapAuthority"></div>
        </div>
        <div class="mt-2 small text-muted" id="mapMetaAuthority">
          Loading issues on the map…
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="col-12 col-lg-5">
      <div class="card-dark p-4 h-100">
        <div class="fw-semibold mb-3">Reported Issues in <?= h($areaName) ?></div>

        <div class="row g-3">
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total number of issues</div>
              <div class="display-6 fw-bold"><?= (int)$stats['total'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Completed</div>
              <div class="display-6 fw-bold"><?= (int)$stats['completed'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">In Progress</div>
              <div class="display-6 fw-bold"><?= (int)$stats['in_progress'] ?></div>
            </div>
          </div>

          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Pending</div>
              <div class="display-6 fw-bold"><?= (int)$stats['pending'] ?></div>
            </div>
          </div>
        </div>

        <?php if ($areaId === 0): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Your account has no area assigned. Please set <code>users.area_id</code> for this authority.
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recently Updates -->
  <div class="mt-4">
    <h5 class="fw-semibold mb-3">Recent Updates</h5>

    <div class="card-dark p-3 p-md-4">
      <div class="table-responsive">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th style="width:90px;">Issue ID</th>
              <th>Title</th>
              <th style="width:140px;">Category</th>
              <th style="width:140px;">Area branch</th>
              <th style="width:180px;">Reported By</th>
              <th style="width:200px;">Assigned To</th>
              <th style="width:120px;">Status</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($recent)): ?>
            <tr>
              <td colspan="8" class="text-muted">No issues found for this area.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title'] ?? '') ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['area_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td>
                  <?php if (!empty($r['worker_email'])): ?>
                    <?= h($r['worker_email']) ?>
                  <?php else: ?>
                    <span class="text-muted">—</span>
                  <?php endif; ?>
                </td>
                <td><span class="badge bg-secondary"><?= h($r['status'] ?? '') ?></span></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/authority/view_issue.php?issue_id=<?= (int)$r['issue_id'] ?>">
                    View
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
(() => {
  const BASE = <?= json_encode(BASE_URL) ?>;
  const meta = document.getElementById('mapMetaAuthority');

  // Default if no markers (Sri Lanka)
  const SRI_LANKA_CENTER = [7.8731, 80.7718];
  const SRI_LANKA_ZOOM = 7;

  const map = L.map('issuesMapAuthority', {
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

  // endpoint returns ALL issues in authority's area (role = authority)
  fetch(BASE + '/actions/map_issues.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.ok) throw new Error(data?.error || 'Failed');

      const markers = Array.isArray(data.markers) ? data.markers : [];
      meta.textContent = `Showing ${markers.length} issue(s) in your area.`;

      if (markers.length === 0) {
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

        const typeLabel = (Number(m.is_common) === 1)
          ? `<div class="small text-muted">Type: <b>Common</b>${m.common_area_name ? ` • Area: ${escHtml(m.common_area_name)}` : ''}</div>`
          : `<div class="small text-muted">Type: <b>Personal</b></div>`;

        const popup = `
          <div style="min-width:240px;">
            <div class="fw-semibold">#${Number(m.issue_id)} — ${escHtml(m.title)}</div>
            <div class="small">Status: <span style="color:${col}; font-weight:700;">${escHtml(m.status)}</span></div>
            ${typeLabel}
            <div class="mt-2">
              <a class="btn btn-sm btn-outline-brand" href="${BASE}/authority/view_issue.php?issue_id=${Number(m.issue_id)}">View</a>
            </div>
          </div>
        `;

        L.marker([lat, lng], { icon }).addTo(map).bindPopup(popup);
        bounds.push([lat, lng]);
      });

      if (bounds.length) {
        map.fitBounds(bounds, { padding: [25, 25] });
      } else {
        map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
      }
    })
    .catch(() => {
      meta.textContent = 'Could not load issues for the map.';
      map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer_internal.php'; ?>
