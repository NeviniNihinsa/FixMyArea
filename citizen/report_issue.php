<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';

require_roles(['citizen']);

$userId = (int)($_SESSION['user_id'] ?? 0);

// fetch categories
$categories = $pdo->query("SELECT category_id, category_name FROM issue_categories ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC);

// fetch logged citizen area
$stmt = $pdo->prepare("SELECT u.area_id, a.area_name FROM users u LEFT JOIN areas a ON a.area_id=u.area_id WHERE u.user_id=? LIMIT 1");
$stmt->execute([$userId]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);

$myAreaId   = (int)($me['area_id'] ?? 0);
$myAreaName = $me['area_name'] ?? '';

// errors (from session)
$errors = $_SESSION['form_errors'] ?? [];
$old    = $_SESSION['old'] ?? [];
unset($_SESSION['form_errors'], $_SESSION['old']);

// flash messages (success/error)
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<style>
  .area-readonly{
    background: #e9ecef !important;
    color: #000 !important;
    border-color: rgba(0,0,0,0.15) !important;
    opacity: 1 !important;
    -webkit-text-fill-color: #000 !important; /* important for Chrome */
    cursor: not-allowed;
  }
</style>

<div class="container py-4">
  <h2 class="fw-bold mb-3">Report an Issue</h2>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if ($flashError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($flashError) ?></div>
  <?php endif; ?>

  <?php if (!empty($errors['general'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($errors['general']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <!-- LEFT: Form -->
    <div class="col-12 col-lg-7">
      <div class="card-dark p-4">
        <form method="POST" action="<?= BASE_URL ?>/actions/issue_create.php" enctype="multipart/form-data" id="reportForm" novalidate>

          <!-- Title -->
          <div class="mb-3">
            <label class="form-label">Issue Title</label>
            <input type="text" name="title" class="form-control"
                   value="<?= htmlspecialchars($old['title'] ?? '') ?>"
                   maxlength="120" required>
            <div class="field-error"><?= htmlspecialchars($errors['title'] ?? '') ?></div>
          </div>

          <!-- Category -->
          <div class="mb-3">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select" required>
              <option value="">Select category</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['category_id'] ?>"
                  <?= ((string)($old['category_id'] ?? '') === (string)$c['category_id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['category_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="field-error"><?= htmlspecialchars($errors['category_id'] ?? '') ?></div>
          </div>

          <!-- Description -->
          <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="5" required><?= htmlspecialchars($old['description'] ?? '') ?></textarea>
            <div class="field-error"><?= htmlspecialchars($errors['description'] ?? '') ?></div>
          </div>

          <!-- Area (auto from user, but keep hidden id) -->
          <div class="mb-3">
            <label class="form-label">Your Area</label>
            <input type="text" class="form-control area-readonly" value="<?= htmlspecialchars($myAreaName ?: 'Not set') ?>" readonly>
            <input type="hidden" name="area_id" value="<?= (int)$myAreaId ?>">
            <div class="field-error"><?= htmlspecialchars($errors['area_id'] ?? '') ?></div>
          </div>

          <!-- Location (lat/lng) -->
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Latitude</label>
              <input type="text" name="lat" class="form-control" value="<?= htmlspecialchars($old['lat'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['lat'] ?? '') ?></div>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Longitude</label>
              <input type="text" name="lng" class="form-control" value="<?= htmlspecialchars($old['lng'] ?? '') ?>" required>
              <div class="field-error"><?= htmlspecialchars($errors['lng'] ?? '') ?></div>
            </div>
          </div>

          <div class="mt-2 d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-brand" id="btnLocation">Use My Location</button>
            <small class="text-muted align-self-center">Allow location to auto-fill lat/lng.</small>
          </div>

          <!-- Photo -->
          <div class="mt-4 mb-3">
            <label class="form-label">Photo Evidence (JPG/PNG/WebP, max 5MB)</label>
            <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp" required>
            <div class="field-error"><?= htmlspecialchars($errors['photo'] ?? '') ?></div>
          </div>

          <button class="btn btn-brand w-100 py-2" type="submit">Submit Issue</button>

        </form>
      </div>
    </div>

    <!-- RIGHT: Map placeholder (later Leaflet) -->
    <div class="col-12 col-lg-5">
      <div class="card-dark p-3 h-100">
        <div class="ratio ratio-4x3" style="border-radius: 12px; overflow:hidden;">
          <div class="d-flex align-items-center justify-content-center" style="background: rgba(255,255,255,0.04);">
            <div class="text-center">
              <div class="text-muted">Map Placeholder</div>
              <div class="small text-muted">OpenStreetMap will be added soon</div>
            </div>
          </div>
        </div>
        <div class="mt-3 small text-muted">
          Tip: for now we use GPS coordinates. Later we’ll replace this box with Leaflet + OpenStreetMap.
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const form = document.getElementById('reportForm');
  const btn = document.getElementById('btnLocation');

  btn.addEventListener('click', () => {
    if (!navigator.geolocation) {
      alert("Geolocation not supported.");
      return;
    }
    btn.disabled = true;
    btn.textContent = "Getting location...";

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        form.lat.value = pos.coords.latitude.toFixed(7);
        form.lng.value = pos.coords.longitude.toFixed(7);
        btn.disabled = false;
        btn.textContent = "Use My Location";
      },
      () => {
        alert("Location permission denied. Enter lat/lng manually.");
        btn.disabled = false;
        btn.textContent = "Use My Location";
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  });

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

    const setErr = (name, msg) => {
      const el = form.querySelector(`[name="${name}"]`);
      if (!el) return;

      // special case: hidden area_id (error div is after hidden input)
      if (name === 'area_id') {
        const errDiv = el.nextElementSibling;
        if (errDiv) errDiv.textContent = msg;
        return;
      }

      if (el.nextElementSibling) el.nextElementSibling.textContent = msg;
    };

    if (!title) { setErr('title', 'Title is required.'); ok = false; }
    else if (title.length < 3) { setErr('title', 'Title must be at least 3 characters.'); ok = false; }

    if (!cat)   { setErr('category_id', 'Category is required.'); ok = false; }
    if (!desc)  { setErr('description', 'Description is required.'); ok = false; }
    else if (desc.length < 10) { setErr('description', 'Description must be at least 10 characters.'); ok = false; }

    if (!area || area === "0") { setErr('area_id', 'Your area is not set. Update profile area first.'); ok = false; }

    const numLat = Number(lat), numLng = Number(lng);
    if (!lat || Number.isNaN(numLat) || numLat < -90 || numLat > 90) { setErr('lat', 'Enter valid latitude (-90 to 90).'); ok = false; }
    if (!lng || Number.isNaN(numLng) || numLng < -180 || numLng > 180) { setErr('lng', 'Enter valid longitude (-180 to 180).'); ok = false; }

    if (!photo) { setErr('photo', 'Photo is required.'); ok = false; }
    else if (photo.size > 5 * 1024 * 1024) { setErr('photo', 'Max file size is 5MB.'); ok = false; }

    if (!ok) e.preventDefault();
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
