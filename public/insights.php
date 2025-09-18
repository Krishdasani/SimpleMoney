<?php
require_once __DIR__ . '/../src/auth_guard.php';
include __DIR__ . '/partials/chat_widget.php'; 

$userId  = requireUser($pdo);
$current = 'insights';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Insights</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>
</head>
<body class="bg-gray-50">
  <?php if (file_exists(__DIR__.'/partials/navbar.php')) include __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-6xl p-4 md:p-6">
    <h1 class="text-2xl font-semibold tracking-tight">Insights</h1>

    <!-- Controls -->
    <div class="mt-4 grid grid-cols-1 gap-4 rounded-lg bg-white p-4 shadow-sm md:grid-cols-4">
      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700">Date range</label>
        <div class="mt-1 flex gap-2">
          <input id="from" type="date" class="w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
          <input id="to"   type="date" class="w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
        </div>
        <p class="mt-1 text-xs text-gray-500">Used for cash-flow and category breakdown. Defaults to last 90 days.</p>
      </div>
      <div class="flex items-end gap-2 md:justify-end">
        <button id="refresh" class="rounded-md bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">Refresh</button>
      </div>
    </div>

    <!-- KPI -->
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Selected Period Spend</p>
        <p id="k-spend" class="mt-1 text-2xl font-semibold text-red-600">£0.00</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Selected Period Income</p>
        <p id="k-income" class="mt-1 text-2xl font-semibold text-green-600">£0.00</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Selected Period Net</p>
        <p id="k-net" class="mt-1 text-2xl font-semibold">£0.00</p>
      </div>
    </div>

    <!-- Charts -->
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="mb-2 text-sm font-medium text-gray-700">Cash-flow</p>
        <!-- Fixed-height wrapper; canvas fills it -->
        <div id="flowWrap" class="relative w-full" style="height: 300px; max-height: 320px;">
          <canvas id="flowChart" class="absolute inset-0 w-full h-full"></canvas>
        </div>
        <div id="flowError" class="mt-2 hidden text-sm text-red-600"></div>
      </div>

      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="mb-2 text-sm font-medium text-gray-700">Category breakdown</p>
        <div id="catWrap" class="relative w-full" style="height: 300px; max-height: 320px;">
          <canvas id="catChart" class="absolute inset-0 w-full h-full"></canvas>
        </div>
        <div id="catLegend" class="mt-3 space-y-1 text-sm"></div>
        <div id="catError" class="mt-2 hidden text-sm text-red-600"></div>
      </div>
    </div>

    <div id="empty" class="mt-6 hidden rounded-lg border border-dashed p-10 text-center text-gray-500">
      No data for the selected period.
    </div>
  </main>

  <script>
  const $ = (id) => document.getElementById(id);

  // Configure chart heights (in px) here if you want to tweak later:
  const FLOW_HEIGHT = 300;
  const CAT_HEIGHT  = 300;

  // Auto-detect /public base
  const PUBLIC_BASE = (() => {
    const p = location.pathname.toLowerCase();
    const i = p.indexOf('/public/');
    return i >= 0 ? location.pathname.slice(0, i + 7) : '/SimpleMoney/public';
  })();
  const api = (name) => `${PUBLIC_BASE}/api/${name}`;

  const fmt = (v,c='GBP') => {
    const n = Number(v||0);
    const s = n.toLocaleString('en-GB', {style:'currency', currency:c});
    return s.replace('GBP','£');
  };

  let flowChart, catChart;

  async function fetchJson(url, opts={}){
    const r = await fetch(url, {credentials:'same-origin', ...opts});
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('application/json')) {
      const t = await r.text();
      console.error('Non-JSON from', url, t.slice(0,200));
      throw new Error('Unexpected response');
    }
    const j = await r.json();
    if (!r.ok) { console.error('API error', j); throw new Error(j?.error || 'api'); }
    return j;
  }

  function buildUrl(){
    const from = $('from').value;
    const to   = $('to').value;
    const u = new URL(api('insights.php'), window.location.origin);
    if (from) u.searchParams.set('from', from);
    if (to)   u.searchParams.set('to', to);
    u.searchParams.set('account_id', '__all__'); // all accounts/cards
    return u.toString();
  }

  function renderKpis(data){
    const cur = data.currency || 'GBP';
    $('k-spend').textContent  = fmt(data.totals?.spend || 0, cur);
    $('k-income').textContent = fmt(data.totals?.income || 0, cur);
    $('k-net').textContent    = fmt(data.totals?.net || 0, cur);
  }

  function renderFlow(data){
    try {
      const labels = (data.series || []).map(s => s.date);
      const income = (data.series || []).map(s => s.income);
      const spend  = (data.series || []).map(s => s.spend);

      // enforce fixed height each time (in case of hot reloads/resizes)
      const wrap = $('flowWrap');
      wrap.style.height = FLOW_HEIGHT + 'px';

      const ctx = $('flowChart').getContext('2d');
      if (flowChart) flowChart.destroy();
      flowChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels,
          datasets: [
            { label: 'Income', data: income, borderWidth: 2, pointRadius: 1, tension: .2 },
            { label: 'Spend',  data: spend,  borderWidth: 2, pointRadius: 1, tension: .2 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,  // fill wrapper height
          plugins: { legend: { position: 'bottom' } },
          scales: { y: { beginAtZero: true } }
        }
      });
      $('flowError').classList.add('hidden');
    } catch (e) {
      console.error('Flow chart error', e);
      $('flowError').textContent = 'Could not render the cash-flow chart.';
      $('flowError').classList.remove('hidden');
    }
  }

  function renderCats(data){
    try {
      const cats = (data.categories || []).filter(c => c.spend > 0);
      const labels = cats.map(c => c.name);
      const values = cats.map(c => Number(c.spend.toFixed(2)));

      $('catWrap').style.height = CAT_HEIGHT + 'px';

      const ctx = $('catChart').getContext('2d');
      if (catChart) catChart.destroy();
      catChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels, datasets: [{ data: values }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
      });

      const cur = data.currency || 'GBP';
      const legend = cats.map(c =>
        `<div class="flex justify-between"><span>${c.name}</span><span class="font-medium">${fmt(-Math.abs(c.spend)*-1, cur)}</span></div>`
      ).join('');
      $('catLegend').innerHTML = legend || '<div class="text-gray-500">No spend in this period.</div>';
      $('catError').classList.add('hidden');
    } catch (e) {
      console.error('Category chart error', e);
      $('catError').textContent = 'Could not render the category chart.';
      $('catError').classList.remove('hidden');
    }
  }

  async function load(){
    const data = await fetchJson(buildUrl());
    const hasData = (data.series && data.series.length) || (data.categories && data.categories.length);
    if (!hasData) { $('empty').classList.remove('hidden'); } else { $('empty').classList.add('hidden'); }
    renderKpis(data);
    renderFlow(data);
    renderCats(data);
  }

  // Boot
  document.addEventListener('DOMContentLoaded', async () => {
    // Default to last 90 days
    const to = new Date();
    const from = new Date(); from.setDate(from.getDate() - 89);
    $('from').value = from.toISOString().slice(0,10);
    $('to').value   = to.toISOString().slice(0,10);

    await load();
    $('refresh').addEventListener('click', (e)=>{ e.preventDefault(); load(); });
  });
  </script>
</body>
</html>
