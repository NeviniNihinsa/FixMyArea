<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['worker']);

$page_title = 'Worker Dashboard - FixMyArea';

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);

$st = $pdo->prepare("
  SELECT u.user_id, u.name, u.email, u.area_id, a.area_name
  FROM users u
  LEFT JOIN areas a ON a.area_id = u.area_id
  WHERE u.user_id = ?
  LIMIT 1
");
$st->execute([$userId]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$areaName = (string)($me['area_name'] ?? 'Not set');
$areaId   = (int)($me['area_id'] ?? 0);

$st = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE field_worker_id=?");
$st->execute([$userId]);
$totalAssigned = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM assignments
  WHERE field_worker_id=?
    AND assignment_status IN ('COMPLETED','CLOSED','DONE')
");
$st->execute([$userId]);
$completed = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT COUNT(*)
  FROM assignments
  WHERE field_worker_id=?
    AND assignment_status IN ('PENDING','ASSIGNED','IN_PROGRESS')
");
$st->execute([$userId]);
$pending = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
$st->execute([$userId]);
$newNotifs = (int)$st->fetchColumn();

$st = $pdo->prepare("
  SELECT
    i.issue_id, i.title, i.status, i.created_at,
    c.category_name,
    a.area_name,
    u.email AS reporter_email
  FROM assignments x
  JOIN issues i ON i.issue_id = x.issue_id
  LEFT JOIN issue_categories c ON c.category_id = i.category_id
  LEFT JOIN areas a ON a.area_id = i.area_id
  LEFT JOIN users u ON u.user_id = i.reporter_user_id
  WHERE x.field_worker_id = ?
  ORDER BY i.created_at DESC, i.issue_id DESC
  LIMIT 6
");
$st->execute([$userId]);
$recent = $st->fetchAll(PDO::FETCH_ASSOC);

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function niceStatus(string $s): string { return strtoupper(trim($s)); }
?>

<!-- OpenStreet Map -->
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
  #issuesMapWorker { width: 100%; height: 100%; min-height: 420px; }
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

  <h1 class="fw-bold mb-4">Welcome <?= h($_SESSION['name'] ?? 'Worker') ?></h1>

  <div class="row g-4">
    <!-- LEFT: Map -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-3 h-100">
        <div class="ratio ratio-1x1" style="border-radius: 14px; overflow:hidden;">
          <!--  Map container -->
          <div id="issuesMapWorker"></div>
        </div>

        <div class="mt-2 small text-muted" id="mapMetaWorker">
          Loading assigned issues on the map…
        </div>
      </div>
    </div>

    <!-- RIGHT: Stats -->
    <div class="col-12 col-lg-6">
      <div class="card-dark p-4 h-100">
        <div class="mb-3">
          <div class="text-muted">Assigned Area / Council</div>
          <div class="fw-semibold fs-5"><?= h($areaName) ?></div>
        </div>

        <div class="row g-3">
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Total Assigned</div>
              <div class="fs-3 fw-bold"><?= (int)$totalAssigned ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Completed</div>
              <div class="fs-3 fw-bold"><?= (int)$completed ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">Pending Issues</div>
              <div class="fs-3 fw-bold"><?= (int)$pending ?></div>
            </div>
          </div>
          <div class="col-6">
            <div class="card-dark p-3">
              <div class="text-muted small">New Notifications</div>
              <div class="fs-3 fw-bold"><?= (int)$newNotifs ?></div>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <div class="mt-4">
    <h4 class="fw-semibold mb-3">Recently Assigned / Updated Issues</h4>

    <div class="card-dark p-3 p-md-4">
      <div class="table-responsive d-none d-md-block">
        <table class="table table-dark-custom align-middle mb-0">
          <thead>
            <tr>
              <th>Issue ID</th>
              <th>Title</th>
              <th>Category</th>
              <th>Area</th>
              <th>Reported By</th>
              <th>Status</th>
              <th style="width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="7" class="text-muted">No assigned issues yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recent as $r): ?>
              <tr>
                <td>#<?= (int)$r['issue_id'] ?></td>
                <td><?= h($r['title']) ?></td>
                <td><?= h($r['category_name'] ?? '—') ?></td>
                <td><?= h($r['area_name'] ?? '—') ?></td>
                <td><?= h($r['reporter_email'] ?? '—') ?></td>
                <td><span class="badge bg-secondary"><?= h(niceStatus((string)$r['status'])) ?></span></td>
                <td>
                  <a class="btn btn-sm btn-outline-brand"
                     href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Mobile cards -->
      <div class="d-md-none d-flex flex-column gap-3">
        <?php if (!$recent): ?>
          <div class="text-muted">No assigned issues yet.</div>
        <?php else: ?>
          <?php foreach ($recent as $r): ?>
            <div class="card-dark p-3">
              <div class="d-flex justify-content-between">
                <div class="fw-semibold">#<?= (int)$r['issue_id'] ?> — <?= h($r['title']) ?></div>
                <span class="badge bg-secondary"><?= h(niceStatus((string)$r['status'])) ?></span>
              </div>
              <div class="text-muted small mt-1">
                <?= h($r['category_name'] ?? '—') ?> • <?= h($r['area_name'] ?? '—') ?>
              </div>
              <div class="text-muted small">Reported by: <?= h($r['reporter_email'] ?? '—') ?></div>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-brand"
                   href="<?= BASE_URL ?>/worker/issue_view.php?issue_id=<?= (int)$r['issue_id'] ?>">View</a>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

</div>

<script>
(() => {
  const BASE = <?= json_encode(BASE_URL) ?>;
  const meta = document.getElementById('mapMetaWorker');

  // Default if no markers (Sri Lanka)
  const SRI_LANKA_CENTER = [7.8731, 80.7718];
  const SRI_LANKA_ZOOM = 7;

  const map = L.map('issuesMapWorker', {
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

  // returns ONLY assigned issues for worker role
  fetch(BASE + '/actions/map_issues.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data || !data.ok) throw new Error(data?.error || 'Failed');

      const markers = Array.isArray(data.markers) ? data.markers : [];
      meta.textContent = `Showing ${markers.length} assigned issue(s) on the map.`;

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
          <div style="min-width:220px;">
            <div class="fw-semibold">#${Number(m.issue_id)} — ${escHtml(m.title)}</div>
            <div class="small">Status: <span style="color:${col}; font-weight:700;">${escHtml(m.status)}</span></div>
            ${typeLabel}
            <div class="mt-2">
              <a class="btn btn-sm btn-outline-brand" href="${BASE}/worker/issue_view.php?issue_id=${Number(m.issue_id)}">View</a>
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
      meta.textContent = 'Could not load assigned issues for the map.';
      map.setView(SRI_LANKA_CENTER, SRI_LANKA_ZOOM);
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>