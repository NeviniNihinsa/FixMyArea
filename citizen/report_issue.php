<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';

require_roles(['citizen']);

$page_title = 'Report an Issue - FixMyArea';
require_once __DIR__ . '/../includes/header.php';
?>
<!-- Leaflet (OpenStreetMap) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php
require_once __DIR__ . '/../includes/navbar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
  header("Location: " . BASE_URL . "/auth/login.php");
  exit;
}

// fetch categories
$categories = $pdo->query("
  SELECT category_id, category_name
  FROM issue_categories
  ORDER BY category_name
")->fetchAll(PDO::FETCH_ASSOC);

// fetch common areas
$commonAreas = $pdo->query("
  SELECT common_area_id, area_name
  FROM common_areas
  ORDER BY area_name
")->fetchAll(PDO::FETCH_ASSOC);

// fetch logged citizen area
$stmt = $pdo->prepare("
  SELECT u.area_id, a.area_name, u.address
  FROM users u
  LEFT JOIN areas a ON a.area_id=u.area_id
  WHERE u.user_id=? LIMIT 1
");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = (string)($me['area_name'] ?? '');
$myAddress  = (string)($me['address'] ?? '');

// errors/old (from session)
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);

// defaults
$oldIsCommon     = (string)($old['is_common'] ?? '0'); // '0' personal, '1' common
$oldCommonAreaId = (string)($old['common_area_id'] ?? '');
?>
<div class="container py-4 app-container">
  <h2 class="fw-bold mb-4">Report an Issue</h2>

  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- LEFT: Form -->
    <div class="col-12 col-lg-7">
      <div class="card-dark p-4">
        <form method="POST"
              action="<?= BASE_URL ?>/actions/issue_create.php"
              enctype="multipart/form-data"
              id="reportForm"
              novalidate>

          <!-- Title -->
          <div class="mb-3">
            <label class="form-label">Issue Title</label>
            <input type="text" name="title" id="issueTitle" class="form-control"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>"
                   maxlength="120" required>
            <div class="field-error"><?= htmlspecialchars($errors['title'] ?? '') ?></div>
          </div>

          <!-- Common / Personal -->
          <div class="mb-3">
            <label class="form-label d-block mb-2">Common issue / Personal issue</label>

            <div class="d-flex flex-wrap gap-3">
              <div class="form-check">
                <input class="form-check-input" type="radio" name="is_common" id="isPersonal" value="0"
                  <?= ($oldIsCommon !== '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="isPersonal">Personal</label>
              </div>

              <div class="form-check">
                <input class="form-check-input" type="radio" name="is_common" id="isCommon" value="1"
                  <?= ($oldIsCommon === '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="isCommon">Common</label>
              </div>
            </div>

            <div class="field-error"><?= htmlspecialchars($errors['is_common'] ?? '') ?></div>
          </div>

          <!-- Common Area (only if Common selected) -->
          <div class="mb-3" id="commonAreaWrap" style="display:none;">
            <label class="form-label">Common Area</label>
            <select name="common_area_id" class="form-select" id="commonAreaSelect">
              <option value="">Select common area</option>
              <?php foreach ($commonAreas as $ca): ?>
                <option value="<?= (int)$ca['common_area_id'] ?>"
                  <?= ((string)$ca['common_area_id'] === $oldCommonAreaId) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($ca['area_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-error"><?= htmlspecialchars($errors['common_area_id'] ?? '') ?></div>

            <!-- ✅ AI Duplicate Warning — injected here by JS -->
            <div id="duplicate-warning"></div>
          </div>

          <!-- Category -->
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category_id" id="categorySelect" class="form-select" required>
              <option value="">Select category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>"
                  <?= ((string)($old['category_id'] ?? '') === (string)$c['category_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-error"><?= htmlspecialchars($errors['category_id'] ?? '') ?></div>
            <!-- ✅ AI Category Badge -->
            <small id="ai-category-badge" class="mt-1 d-block" style="color: #a78bfa; min-height: 1.2em;"></small>
          </div>

          <!-- Description -->
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" id="issueDescription" class="form-control" rows="5" required><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
            <div class="field-error"><?= htmlspecialchars($errors['description'] ?? '') ?></div>
          </div>

          <!-- Address (auto from user, display only) -->
          <div class="mb-3">
            <label class="form-label">Your Address</label>
            <input type="text" class="form-control"
                   value="<?= htmlspecialchars($myAddress ?: 'Not set') ?>" disabled>
            <div class="field-error">
              <?php if (!$myAddress): ?>
                Please update your address in Profile.
              <?php endif; ?>
            </div>
          </div>

          <!-- Area (keep hidden id for backend filtering) -->
          <input type="hidden" name="area_id" value="<?= (int)$myAreaId ?>">
          <div class="field-error mb-2"><?= htmlspecialchars($errors['area_id'] ?? '') ?></div>

          <!-- Location (lat/lng) - hidden, auto filled -->
          <div class="row g-3" style="display:none;">
            <div class="col-12 col-md-6">
              <label class="form-label">Latitude</label>
              <input type="text" name="lat" class="form-control"
                     value="<?= htmlspecialchars($old['lat'] ?? '') ?>" readonly>
              <div class="field-error"><?= htmlspecialchars($errors['lat'] ?? '') ?></div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Longitude</label>
              <input type="text" name="lng" class="form-control"
                     value="<?= htmlspecialchars($old['lng'] ?? '') ?>" readonly>
              <div class="field-error"><?= htmlspecialchars($errors['lng'] ?? '') ?></div>
            </div>
          </div>

          <div class="mt-2 d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-brand" id="btnLocation">Use My Location</button>
            <small class="text-muted align-self-center">We will detect your location</small>
          </div>

          <!-- show location errors -->
          <div class="field-error mt-2"><?= htmlspecialchars($errors['lat'] ?? '') ?></div>
          <div class="field-error"><?= htmlspecialchars($errors['lng'] ?? '') ?></div>

          <!-- Photo -->
          <div class="mt-4 mb-3">
            <label class="form-label">Photo Evidence (JPG/PNG/WebP, max 5MB)</label>
            <input type="file" name="photo" class="form-control"
                   accept="image/jpeg,image/png,image/webp" required>
            <div class="field-error"><?= htmlspecialchars($errors['photo'] ?? '') ?></div>
          </div>

          <button class="btn btn-brand w-100 py-2" type="submit" id="submitBtn">Submit Issue</button>
        </form>
      </div>
    </div>

    <!-- RIGHT: Map -->
    <div class="col-12 col-lg-5">
      <div class="card-dark p-3 h-100">
        <div id="reportMap" style="height: 420px; border-radius: 12px; overflow:hidden; border:1px solid rgba(255,255,255,0.08);"></div>
        <div class="mt-3 small text-muted">
          Tip: Click <b>Use My Location</b> to detect your location
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const form         = document.getElementById('reportForm');
  const btn          = document.getElementById('btnLocation');
  const commonWrap   = document.getElementById('commonAreaWrap');
  const commonSelect = document.getElementById('commonAreaSelect');
  const titleEl      = document.getElementById('issueTitle');
  const descEl       = document.getElementById('issueDescription');
  const categoryEl   = document.getElementById('categorySelect');
  const aiBadge      = document.getElementById('ai-category-badge');
  const dupWarning   = document.getElementById('duplicate-warning');

  const BASE = '<?= BASE_URL ?>';

  // OpenStreetMap Map
  const mapEl = document.getElementById('reportMap');
  let map = null;
  let marker = null;

  const initMap = () => {
    if (!mapEl || map) return;
    map = L.map('reportMap').setView([6.9271, 79.8612], 12); // default Colombo
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);
  };

  const setMarker = (lat, lng, text) => {
    initMap();
    if (!map) return;
    const pos = [lat, lng];
    map.setView(pos, 16);
    if (marker) map.removeLayer(marker);
    marker = L.marker(pos).addTo(map);
    if (text) marker.bindPopup(text).openPopup();
  };

  initMap();

  // If old lat/lng exists (after validation error), re-show on map
  const oldLat = Number(form.lat?.value || 0);
  const oldLng = Number(form.lng?.value || 0);
  if (!Number.isNaN(oldLat) && !Number.isNaN(oldLng) && oldLat !== 0 && oldLng !== 0) {
    setMarker(oldLat, oldLng, "Previously detected location");
  }

 
  // Common / Personal toggle
  const toggleCommon = () => {
    const isCommon = form.querySelector('input[name="is_common"]:checked')?.value === '1';
    commonWrap.style.display = isCommon ? '' : 'none';
    if (!isCommon) {
      commonSelect.value = '';
      dupWarning.innerHTML = '';
    }
  };
  form.querySelectorAll('input[name="is_common"]').forEach(r => r.addEventListener('change', () => {
    toggleCommon();
    triggerDuplicateCheck(); // re-run when switching to/from common
  }));
  toggleCommon();

  //  AI Category Suggestion

  let catTimer = null;

  const suggestCategory = () => {
    const title = titleEl.value.trim();
    const desc  = descEl.value.trim();

    if (title.length < 4 && desc.length < 8) return;

    aiBadge.textContent = ' Detecting category…';
    aiBadge.style.color = '#a78bfa';

    const fd = new FormData();
    fd.append('title', title);
    fd.append('description', desc);

    fetch(BASE + '/actions/ai_suggest_category.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.category_id) {
          const prev = categoryEl.value;
          categoryEl.value = data.category_id;

          if (prev !== String(data.category_id)) {
            categoryEl.style.transition = 'box-shadow 0.3s';
            categoryEl.style.boxShadow  = '0 0 0 3px rgba(167,139,250,0.4)';
            setTimeout(() => categoryEl.style.boxShadow = '', 1500);
          }

          aiBadge.textContent = ` AI suggested: ${data.category_name} — change if incorrect`;
        } else {
          aiBadge.textContent = '';
        }
      })
      .catch(() => { aiBadge.textContent = ''; });
  };

  const onCatInput = () => {
    clearTimeout(catTimer);
    catTimer = setTimeout(suggestCategory, 900);
  };

  titleEl.addEventListener('input', onCatInput);
  descEl.addEventListener('input', onCatInput);

  // Duplicate Issue Detection

  let dupTimer = null;

  const checkDuplicate = () => {
    const isCommon = form.querySelector('input[name="is_common"]:checked')?.value;
    if (isCommon !== '1') return;

    const title       = titleEl.value.trim();
    const desc        = descEl.value.trim();
    const commonAreaId = commonSelect.value;

    if (title.length < 4 || !commonAreaId) {
      dupWarning.innerHTML = '';
      return;
    }

    dupWarning.innerHTML = '<div class="text-muted small mt-2"> Checking for similar issues…</div>';

    const fd = new FormData();
    fd.append('title', title);
    fd.append('description', desc);
    fd.append('common_area_id', commonAreaId);

    fetch(BASE + '/actions/ai_check_duplicate.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(data => {
        if (data.duplicate && data.matches?.length) {
          const rows = data.matches.map(m => {
            const statusColors = {
              PENDING: 'bg-secondary',
              ASSIGNED: 'bg-info text-dark',
              IN_PROGRESS: 'bg-warning text-dark',
            };
            const badgeClass = statusColors[m.status] ?? 'bg-secondary';
            return `
              <li class="d-flex align-items-start gap-2 mb-1">
                <span class="badge ${badgeClass} mt-1">${m.status.replace('_',' ')}</span>
                <span>
                  <strong>#${m.issue_id}</strong> — ${escHtml(m.title)}
                  <span class="text-muted small ms-1">(${m.score}% match)</span>
                </span>
              </li>`;
          }).join('');

          dupWarning.innerHTML = `
            <div class="alert alert-warning mt-2 mb-0 py-2 px-3" style="border-left: 4px solid #f59e0b;">
              <div class="fw-semibold mb-1">⚠️ Similar issue(s) already reported in this area:</div>
              <ul class="mb-2 ps-0" style="list-style:none;">${rows}</ul>
              <small class="text-muted">You can still submit if your issue is different from the above.</small>
            </div>`;
        } else {
          dupWarning.innerHTML = `<div class="text-success small mt-2">✅ No similar issues found — looks unique!</div>`;
          setTimeout(() => { dupWarning.innerHTML = ''; }, 3000);
        }
      })
      .catch(() => { dupWarning.innerHTML = ''; });
  };

  const triggerDuplicateCheck = () => {
    clearTimeout(dupTimer);
    dupTimer = setTimeout(checkDuplicate, 800);
  };

  titleEl.addEventListener('input', triggerDuplicateCheck);
  descEl.addEventListener('input', triggerDuplicateCheck);
  commonSelect.addEventListener('change', triggerDuplicateCheck);

  const escHtml = (str) => {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  };


  // GPS Location button

  btn.addEventListener('click', () => {
    if (!navigator.geolocation) {
      alert('Geolocation not supported.');
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Getting location…';

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = Number(pos.coords.latitude.toFixed(7));
        const lng = Number(pos.coords.longitude.toFixed(7));

        form.lat.value = lat;
        form.lng.value = lng;

        setMarker(lat, lng, "Detected Location ✅");

        btn.disabled = false;
        btn.textContent = 'Use My Location';
      },
      () => {
        alert('Location permission denied. Please allow location access to submit the issue.');
        btn.disabled = false;
        btn.textContent = 'Use My Location';
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  });

  
  // Client-side form validation
  
  form.addEventListener('submit', (e) => {
    let ok = true;
    form.querySelectorAll('.field-error').forEach(el => el.textContent = '');

    const title = form.title.value.trim();
    const desc  = form.description.value.trim();
    const cat   = form.category_id.value;
    const area  = form.area_id.value;
    const lat   = form.lat.value.trim();
    const lng   = form.lng.value.trim();
    const photo = form.photo.files[0];

    const isCommon    = form.querySelector('input[name="is_common"]:checked')?.value || '0';
    const commonAreaId = commonSelect.value;

    const setErr = (name, msg) => {
      const el = form.querySelector(`[name="${name}"]`);
      if (el && el.nextElementSibling?.classList.contains('field-error')) {
        el.nextElementSibling.textContent = msg;
      }
    };

    if (!title)                       { setErr('title', 'Title is required.'); ok = false; }
    if (!cat)                         { setErr('category_id', 'Category is required.'); ok = false; }
    if (!desc)                        { setErr('description', 'Description is required.'); ok = false; }
    if (!area || area === '0')        { ok = false; }
    if (isCommon === '1' && !commonAreaId) { setErr('common_area_id', 'Common area is required for common issues.'); ok = false; }

    const numLat = Number(lat), numLng = Number(lng);
    if (!lat || Number.isNaN(numLat) || numLat < -90  || numLat > 90)  { setErr('lat', 'Location is required. Please click "Use My Location".'); ok = false; }
    if (!lng || Number.isNaN(numLng) || numLng < -180 || numLng > 180) { setErr('lng', 'Location is required. Please click "Use My Location".'); ok = false; }

    if (!photo)                            { setErr('photo', 'Photo is required.'); ok = false; }
    else if (photo.size > 5 * 1024 * 1024) { setErr('photo', 'Max file size is 5MB.'); ok = false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>