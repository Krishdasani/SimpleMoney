<?php
require_once __DIR__ . '/../src/auth_guard.php';

$userId = requireUser($pdo);
$current = 'budgets';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Budgets</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>
</head>
<body class="bg-gray-50">
  <?php include __DIR__ . '/partials/navbar.php'; ?>
  <?php include __DIR__ . '/partials/chat_widget.php'; ?>

  <main class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">

    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold text-gray-900">Budgets</h1>
      <button id="addBtn" class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-white text-sm font-medium hover:bg-blue-700">Add budget</button>
    </div>

    <!-- KPIs -->
    <section class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Budgets</p>
        <p id="kpi-count" class="mt-1 text-2xl font-semibold text-gray-900">0</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Total Limit</p>
        <p id="kpi-limit" class="mt-1 text-2xl font-semibold text-gray-900">£0.00</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Spent this period</p>
        <p id="kpi-spent" class="mt-1 text-2xl font-semibold text-red-600">£0.00</p>
      </div>
    </section>

    <!-- Grid -->
    <section id="grid" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3"></section>

    <!-- Empty -->
    <div id="empty" class="mt-8 hidden rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-500">
      No budgets yet. Click <b>Add budget</b> to create your first one.
    </div>
  </main>

  <!-- Add/Edit Modal -->
  <div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/30 p-4">
    <div class="w-full max-w-md rounded-lg bg-white p-5 shadow-xl">
      <div class="flex items-center justify-between">
        <h2 id="modal-title" class="text-lg font-semibold text-gray-900">Add budget</h2>
        <button id="closeModal" class="text-gray-500 hover:text-gray-700">&times;</button>
      </div>
      <form id="budgetForm" class="mt-4 space-y-3">
        <div>
          <label class="block text-sm font-medium text-gray-700">Category</label>
          <input list="catlist" id="f-category" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600" placeholder="e.g. Groceries"/>
          <datalist id="catlist"></datalist>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Amount (GBP)</label>
          <input type="number" step="0.01" min="0" id="f-amount" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600" placeholder="300.00"/>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700">Period</label>
          <select id="f-period" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600">
            <option value="monthly">Monthly</option>
            <option value="weekly">Weekly</option>
            <option value="custom">Custom from date</option>
          </select>
        </div>
        <div id="customDateWrap" class="hidden">
          <label class="block text-sm font-medium text-gray-700">Start date</label>
          <input type="date" id="f-start" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
        </div>
        <div class="flex items-center gap-2">
          <input type="checkbox" id="f-rollover" class="rounded border-gray-300"/>
          <label for="f-rollover" class="text-sm text-gray-700">Enable rollover</label>
        </div>

        <div class="pt-2 flex items-center justify-end gap-2">
          <button type="button" id="cancelBtn" class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-700 hover:bg-gray-200">Cancel</button>
          <button type="submit" class="rounded-md bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // ---------- Helpers ----------
    const fmtGBP = v => new Intl.NumberFormat('en-GB',{style:'currency',currency:'GBP'}).format(v);
    function clsForPct(p){ return p<0.7?'bg-green-600':(p<1?'bg-amber-500':'bg-red-600'); }

    // ---------- Modal ----------
    const modal = document.getElementById('modal');
    function openModal(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
    function closeModal(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
    document.getElementById('addBtn').addEventListener('click', ()=>{ resetForm(); openModal(); });
    document.getElementById('closeModal').addEventListener('click', closeModal);
    document.getElementById('cancelBtn').addEventListener('click', closeModal);
    document.getElementById('f-period').addEventListener('change', (e)=>{
      document.getElementById('customDateWrap').classList.toggle('hidden', e.target.value!=='custom');
    });

    function resetForm(){
      document.getElementById('budgetForm').reset();
      document.getElementById('customDateWrap').classList.add('hidden');
    }

    // ---------- Categories source (derive from recent tx) ----------
    async function loadCategories(){
      // Pull last 90 days, all accounts (uses your transactions API)
      const today = new Date();
      const from = new Date(today); from.setDate(today.getDate()-90);
      const url = new URL('/SimpleMoney/public/api/transactions.php', window.location.origin);
      url.searchParams.set('account_id', '__all__');
      url.searchParams.set('from', from.toISOString().slice(0,10));
      url.searchParams.set('to', today.toISOString().slice(0,10));
      url.searchParams.set('page', 1);
      url.searchParams.set('size', 500);

      try {
        const r = await fetch(url, {credentials:'same-origin'});
        const data = await r.json();
        const set = new Set();
        (data.items||[]).forEach(t => set.add((t.category||'Other').toString()));
        const cats = Array.from(set).sort((a,b)=>a.toLowerCase().localeCompare(b.toLowerCase()));
        const dl = document.getElementById('catlist'); dl.innerHTML='';
        cats.forEach(c => {
          const opt = document.createElement('option'); opt.value=c; dl.appendChild(opt);
        });
      } catch(e) {
        console.warn('Category preload failed', e);
      }
    }

    // ---------- API calls ----------
    async function fetchBudgets(){
      const r = await fetch('/SimpleMoney/public/api/budgets.php', {credentials:'same-origin'});
      return await r.json();
    }
    async function createBudget(payload){
      const r = await fetch('/SimpleMoney/public/api/budgets.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify(payload)
      });
      if (!r.ok) throw new Error('Create failed');
      return await r.json();
    }
    async function deleteBudget(id){
      const r = await fetch('/SimpleMoney/public/api/budgets.php?id='+id, {method:'DELETE', credentials:'same-origin'});
      if (!r.ok) throw new Error('Delete failed');
      return await r.json();
    }

    // ---------- Render ----------
    function render(data){
      const k = data.kpi || {count:0,total_limit:0,spent:0,remaining:0};
      document.getElementById('kpi-count').textContent = k.count || 0;
      document.getElementById('kpi-limit').textContent = fmtGBP(k.total_limit||0);
      document.getElementById('kpi-spent').textContent = fmtGBP(k.spent||0);

      const grid = document.getElementById('grid');
      grid.innerHTML = '';
      const items = data.items || [];

      if (!items.length){
        document.getElementById('empty').classList.remove('hidden');
        return;
      }
      document.getElementById('empty').classList.add('hidden');

      items.forEach(b=>{
        const pct = b.pct || 0;
        const li = document.createElement('div');
        li.className = 'rounded-lg bg-white p-4 shadow-sm';
        li.innerHTML = `
          <div class="flex items-start justify-between">
            <div>
              <div class="text-sm font-semibold text-gray-900">${b.category}</div>
              <div class="text-xs text-gray-500">${b.period === 'custom' ? `From ${b.window.from}` : b.period.charAt(0).toUpperCase()+b.period.slice(1)} • ${b.window.from} – ${b.window.to}</div>
            </div>
            <button data-id="${b.id}" class="del text-xs text-red-600 hover:text-red-700">Delete</button>
          </div>

          <div class="mt-3">
            <div class="flex items-center justify-between text-xs">
              <span class="text-gray-600">Spent</span>
              <span class="text-gray-900 font-medium">${fmtGBP(b.spent)} / ${fmtGBP(b.amount)}</span>
            </div>
            <div class="mt-1 h-2 w-full rounded-full bg-gray-200 overflow-hidden">
              <div class="h-2 ${clsForPct(pct)}" style="width:${Math.min(100, Math.round(pct*100))}%"></div>
            </div>
            <div class="mt-1 text-xs text-gray-600">
              Remaining: <span class="font-medium">${fmtGBP(b.remaining)}</span>
            </div>
          </div>
        `;
        grid.appendChild(li);
      });

      // wire delete buttons
      grid.querySelectorAll('.del').forEach(btn=>{
        btn.addEventListener('click', async (e)=>{
          const id = e.currentTarget.getAttribute('data-id');
          if (!confirm('Delete this budget?')) return;
          try { await deleteBudget(id); await refresh(); }
          catch(err){ alert('Delete failed'); }
        });
      });
    }

    // ---------- Form submit ----------
    document.getElementById('budgetForm').addEventListener('submit', async (e)=>{
      e.preventDefault();
      const payload = {
        category:    document.getElementById('f-category').value.trim(),
        amount:      parseFloat(document.getElementById('f-amount').value || '0'),
        period_type: document.getElementById('f-period').value,
        start_date:  (document.getElementById('f-period').value==='custom' ? document.getElementById('f-start').value : null),
        rollover:    document.getElementById('f-rollover').checked ? 1 : 0,
      };
      if (!payload.category || !(payload.amount>0)) { alert('Please enter a category and a positive amount.'); return; }
      if (payload.period_type==='custom' && !payload.start_date) { alert('Pick a start date for custom period.'); return; }

      try {
        await createBudget(payload);
        closeModal();
        await refresh();
      } catch (err) {
        alert('Save failed (maybe duplicate budget for same category/period?)');
      }
    });

    async function refresh(){
      try {
        const data = await fetchBudgets();
        render(data);
      } catch (e) {
        console.error(e);
        alert('Failed to load budgets.');
      }
    }

    // ---------- Init ----------
    (async ()=>{
      await loadCategories();
      await refresh();
    })();
  </script>
</body>
</html>
