<?php
require_once __DIR__ . '/../src/auth_guard.php';
$userId = requireUser($pdo);
$current = 'dashboard';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>
</head>
<body class="bg-gray-50">
  <?php include __DIR__ . '/partials/navbar.php'; ?>
  <?php include __DIR__ . '/partials/chat_widget.php'; ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <!-- Re-auth banner -->
    <div id="reauth" class="hidden mb-4 rounded-md border border-yellow-300 bg-yellow-50 p-4">
      <div class="flex items-start gap-3">
        <div class="mt-0.5 h-2.5 w-2.5 rounded-full bg-yellow-400"></div>
        <div>
          <p class="text-sm text-yellow-900">
            One or more banks need you to re-authenticate (SCA). Some data may be unavailable.
          </p>
          <a href="/SimpleMoney/public/auth/tl_start.php"
             class="mt-2 inline-flex rounded-md bg-yellow-600 px-3 py-1.5 text-white text-sm hover:bg-yellow-700">
            Reconnect now
          </a>
        </div>
      </div>
    </div>

    <!-- KPI tiles -->
    <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Total Balance</p>
        <p id="kpi-balance" class="mt-1 text-2xl font-semibold text-gray-900">—</p>
        <p class="mt-1 text-xs text-gray-500" id="kpi-balance-note"></p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Month-to-date Spent</p>
        <p id="kpi-spent" class="mt-1 text-2xl font-semibold text-red-600">—</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Month-to-date Income</p>
        <p id="kpi-income" class="mt-1 text-2xl font-semibold text-green-600">—</p>
      </div>
    </section>

    <!-- Two columns -->
    <section class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
      <!-- Recent transactions -->
      <div class="lg:col-span-7 rounded-lg bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
          <h2 class="text-sm font-semibold text-gray-900">Recent Transactions</h2>
          <a href="/SimpleMoney/public/transactions.php"
             class="text-sm text-blue-600 hover:text-blue-700">View all</a>
        </div>
        <div id="recent" class="divide-y divide-gray-100"></div>
      </div>

      <!-- Balances by account -->
      <div class="lg:col-span-5 rounded-lg bg-white shadow-sm">
        <div class="border-b border-gray-200 px-4 py-3">
          <h2 class="text-sm font-semibold text-gray-900">Balances by Account</h2>
        </div>
        <ul id="balances" class="divide-y divide-gray-100"></ul>
      </div>
    </section>
  </main>

  <script>
    const fmtGBP = v => new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(v);

    async function loadSummary(){
      const r = await fetch('/SimpleMoney/public/api/summary.php', {credentials:'same-origin'});
      const data = await r.json();
      if (!r.ok) throw new Error(data.error || 'API error');
      render(data);
    }

    function render(data){
      // Banner
      document.getElementById('reauth').classList.toggle('hidden', !data.reauth_required);

      // KPIs (assume GBP primary; if multi-currency, we show note)
      document.getElementById('kpi-balance').textContent = fmtGBP(data.kpis.total_balance_gbp || 0);
      document.getElementById('kpi-spent').textContent   = fmtGBP((data.kpis.mtd_spent_gbp || 0) * -1); // spent is negative in API calc
      document.getElementById('kpi-income').textContent  = fmtGBP(data.kpis.mtd_income_gbp || 0);
      document.getElementById('kpi-balance-note').textContent =
        (data.kpis.other_currencies?.length ? `Includes ${data.kpis.other_currencies.join(', ')}` : '');

      // Recent
      const recent = document.getElementById('recent');
      recent.innerHTML = '';
      (data.recent || []).forEach(t=>{
        const row = document.createElement('div');
        row.className = 'px-4 py-3 flex items-center justify-between';
        row.innerHTML = `
          <div>
            <div class="text-sm font-medium text-gray-900">${t.desc}</div>
            <div class="text-xs text-gray-500">${t.date} • ${t.account_label}</div>
          </div>
          <div class="text-sm ${t.amount<0?'text-red-600':'text-green-600'} font-medium">
            ${fmtGBP(Math.abs(t.amount))}${t.amount<0?'<span class="sr-only"> out</span>':''}
          </div>
        `;
        recent.appendChild(row);
      });
      if ((data.recent||[]).length === 0){
        recent.innerHTML = '<div class="px-4 py-6 text-sm text-gray-500">No recent transactions.</div>';
      }

      // Balances
      const balances = document.getElementById('balances');
      balances.innerHTML = '';
      (data.balances || []).forEach(b=>{
        const li = document.createElement('li');
        li.className = 'px-4 py-3 flex items-center justify-between';
        li.innerHTML = `
          <div>
            <div class="text-sm font-medium text-gray-900">${b.label}</div>
            <div class="text-xs text-gray-500">${b.provider?.toUpperCase() || 'truelayer'} • ${b.account_id.slice(0,6)}…</div>
          </div>
          <div class="text-sm font-medium ${b.amount<0?'text-red-600':'text-gray-900'}">
            ${fmtGBP(b.amount)}
          </div>
        `;
        balances.appendChild(li);
      });
      if ((data.balances||[]).length === 0){
        balances.innerHTML = '<li class="px-4 py-6 text-sm text-gray-500">No accounts connected.</li>';
      }
    }

    (async ()=> {
      try { await loadSummary(); }
      catch(e){ console.error(e); alert('Failed to load dashboard.'); }
    })();
  </script>
</body>
</html>
