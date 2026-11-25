<?php session_start(); $role = $_SESSION['role'] ?? null; $staff = in_array($role, ['admin','attendant']); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Parking Slot Allocation and Availability Monitoring System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .slot-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px}
  .slot{border:0;border-radius:8px;padding:12px 4px;color:#fff;font-weight:600;transition:transform .15s}
  .slot small{display:block;font-size:.7rem;font-weight:400}
  .slot.available{background:#198754}
  .slot.maintenance{background:#6c757d}
  .slot.occupied{background:#dc3545;cursor:pointer}
  .slot.occupied:not(:disabled):hover{transform:scale(1.06)}
</style>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-3"><div class="container">
  <span class="navbar-brand">🅿️ Parking Slot Allocation &amp; Monitoring</span>
  <?php if ($staff): ?>
    <span class="text-white"><?= htmlspecialchars($_SESSION['user']) ?> (<?= $role ?>)
      <?php if ($role === 'admin'): ?><a href="admin.php" class="btn btn-sm btn-warning ms-2">Admin</a><?php endif; ?>
      <a href="logout.php" class="btn btn-sm btn-outline-light ms-2">Log out</a></span>
  <?php else: ?>
    <a href="login.php" class="btn btn-sm btn-outline-light">Staff login</a>
  <?php endif; ?>
</div></nav>

<div class="container pb-4">
  <div class="row g-2 text-center mb-3">
    <div class="col-6 col-md-3"><div class="card p-2"><small>Total</small><h3 id="total">-</h3></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-success"><small>Available</small><h3 id="avail">-</h3></div></div>
    <div class="col-6 col-md-3"><div class="card p-2 text-danger"><small>Occupied</small><h3 id="occ">-</h3></div></div>
    <div class="col-6 col-md-3"><div class="card p-2"><small>Occupancy</small><h3 id="rate">-</h3></div></div>
  </div>

  <div class="row g-3">
    <?php if ($staff): ?>
    <div class="col-lg-4">
      <div class="card p-3">
        <h5>Park a vehicle</h5>
        <form id="parkForm" class="d-grid gap-2">
          <input class="form-control" name="plate" placeholder="Plate number (e.g. ABC 1234)" required maxlength="8">
          <select class="form-select" name="vehicle_type">
            <option value="car">Car</option>
            <option value="motorcycle">Motorcycle</option>
            <option value="pwd">PWD</option>
          </select>
          <button class="btn btn-primary">Allocate slot</button>
        </form>
        <div id="msg" class="mt-3"></div>
        <small class="text-muted mt-2">Tip: click a red slot to release the vehicle and see the fee.</small>
      </div>
    </div>
    <?php endif; ?>
    <div class="<?= $staff ? 'col-lg-8' : 'col-12' ?>">
      <div class="card p-3"><h5>Live slot map <small class="text-muted fs-6">(updates every 3 seconds)</small></h5><div id="map"></div></div>
    </div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const STAFF = <?= $staff ? 'true' : 'false' ?>;

function show(text, ok) {
  $('msg').innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} mb-0">${text}</div>`;
}

async function load() {
  const s = await (await fetch('api.php?action=slots')).json();
  const free = s.filter(x => x.status === 'available').length;
  $('total').textContent = s.length;
  $('avail').textContent = free;
  const occ = s.filter(x => x.status === 'occupied').length, usable = free + occ;
  $('occ').textContent = occ;
  $('rate').textContent = (usable ? Math.round(occ / usable * 100) : 0) + '%';

  const zones = {};
  s.forEach(x => (zones[x.zone] ??= []).push(x));
  $('map').innerHTML = Object.entries(zones).map(([z, list]) => `
    <h6 class="mt-3">Zone ${z} <span class="text-muted">· ${list.filter(x => x.status === 'available').length} free</span></h6>
    <div class="slot-grid">${list.map(x => `
      <button class="slot ${x.status}" ${x.status === 'occupied' && STAFF ? `onclick="release(${x.id},'${x.plate}')"` : 'disabled'}>
        ${x.code}<small>${x.status === 'maintenance' ? 'maintenance' : (x.plate ?? x.type)}</small>
      </button>`).join('')}
    </div>`).join('');
}

async function post(action, data) {
  const r = await fetch('api.php?action=' + action, { method: 'POST', body: data });
  const j = await r.json();
  show(j.message || j.error, r.ok);
  load();
}

$('parkForm')?.addEventListener('submit', e => {
  e.preventDefault();
  post('park', new FormData(e.target));
  e.target.plate.value = '';
});

function release(id, plate) {
  if (!confirm('Release ' + plate + ' and compute the fee?')) return;
  const d = new FormData(); d.append('slot_id', id);
  post('release', d);
}

load();
setInterval(load, 3000);
</script>
</body>
</html>
