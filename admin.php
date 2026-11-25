<?php
session_start();
if (($_SESSION['role'] ?? '') !== 'admin') { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Panel</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark mb-3"><div class="container">
  <span class="navbar-brand">🛠️ Admin Panel</span>
  <span><a href="index.php" class="btn btn-sm btn-outline-light">Dashboard</a>
  <a href="logout.php" class="btn btn-sm btn-outline-light">Log out</a></span>
</div></nav>

<div class="container pb-4">
  <div id="msg" class="mb-3"></div>

  <h5>Reports</h5>
  <div class="row g-2 text-center mb-3">
    <div class="col-4"><div class="card p-2"><small>Completed sessions</small><h4 id="n">-</h4></div></div>
    <div class="col-4"><div class="card p-2"><small>Total revenue</small><h4 id="rev">-</h4></div></div>
    <div class="col-4"><div class="card p-2"><small>Avg. stay</small><h4 id="avg">-</h4></div></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-md-6"><div class="card p-3"><h6>Peak hours (vehicles entering)</h6><canvas id="hoursChart"></canvas></div></div>
    <div class="col-md-6"><div class="card p-3"><h6>Revenue, last 7 days (₱)</h6><canvas id="revChart"></canvas></div></div>
  </div>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card p-3">
        <h5>Add slot</h5>
        <form id="addForm" class="d-grid gap-2">
          <input class="form-control" name="zone" placeholder="Zone (e.g. C)" maxlength="3" required>
          <input class="form-control" name="code" placeholder="Slot code (e.g. C01)" maxlength="6" required>
          <select class="form-select" name="type">
            <option value="regular">Regular</option><option value="pwd">PWD</option><option value="motorcycle">Motorcycle</option>
          </select>
          <button class="btn btn-primary">Add slot</button>
        </form>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="card p-3">
        <h5>Manage slots</h5>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead><tr><th>Code</th><th>Zone</th><th>Type</th><th>Status</th><th></th></tr></thead>
            <tbody id="rows"></tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const $ = id => document.getElementById(id);
const TYPES = ['regular', 'pwd', 'motorcycle'];
let hoursChart, revChart;

function show(text, ok) { $('msg').innerHTML = `<div class="alert alert-${ok ? 'success' : 'danger'} mb-0">${text}</div>`; }

async function post(action, data) {
  const r = await fetch('api.php?action=' + action, { method: 'POST', body: data });
  const j = await r.json();
  show(j.message || j.error, r.ok);
  loadSlots();
  return r.ok;
}

async function loadSlots() {
  const s = await (await fetch('api.php?action=slots')).json();
  const badge = { available: 'success', occupied: 'danger', maintenance: 'secondary' };
  $('rows').innerHTML = s.map(x => {
    const busy = x.status === 'occupied' ? 'disabled' : '';
    return `<tr>
      <td><input id="c${x.id}" class="form-control form-control-sm" value="${x.code}" maxlength="6" style="width:80px" ${busy}></td>
      <td>${x.zone}</td>
      <td><select id="t${x.id}" class="form-select form-select-sm" ${busy}>
        ${TYPES.map(t => `<option ${t === x.type ? 'selected' : ''}>${t}</option>`).join('')}</select></td>
      <td><span class="badge bg-${badge[x.status]}">${x.status}</span></td>
      <td class="text-nowrap">
        <button class="btn btn-sm btn-outline-primary" ${busy} onclick="save(${x.id})">Save</button>
        <button class="btn btn-sm btn-outline-secondary" ${busy} onclick="maint(${x.id})">${x.status === 'maintenance' ? 'Reopen' : 'Maintenance'}</button>
      </td></tr>`;
  }).join('');
}

function save(id) {
  const d = new FormData();
  d.append('slot_id', id); d.append('code', $('c' + id).value); d.append('type', $('t' + id).value);
  post('edit_slot', d);
}
function maint(id) { const d = new FormData(); d.append('slot_id', id); post('toggle_maintenance', d); }

$('addForm').addEventListener('submit', async e => {
  e.preventDefault();
  if (await post('add_slot', new FormData(e.target))) e.target.reset();
});

async function loadReport() {
  const r = await (await fetch('api.php?action=report')).json();
  $('n').textContent = r.totals.n;
  $('rev').textContent = '₱' + Number(r.totals.revenue).toFixed(2);
  $('avg').textContent = Math.round(r.totals.avg_mins) + ' min';
  const hours = [...Array(24).keys()];
  const byHour = Object.fromEntries(r.hours.map(x => [x.h, x.c]));
  hoursChart?.destroy(); revChart?.destroy();
  hoursChart = new Chart($('hoursChart'), { type: 'bar',
    data: { labels: hours.map(h => h + ':00'), datasets: [{ label: 'Vehicles', data: hours.map(h => byHour[h] || 0), backgroundColor: '#0d6efd' }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  revChart = new Chart($('revChart'), { type: 'bar',
    data: { labels: r.revenue.map(x => x.d), datasets: [{ label: 'Revenue', data: r.revenue.map(x => x.r), backgroundColor: '#198754' }] },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } } });
}

loadSlots();
loadReport();
</script>
</body>
</html>
