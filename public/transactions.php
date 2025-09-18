<?php
require_once __DIR__ . '/../src/auth_guard.php';
include __DIR__ . '/partials/chat_widget.php'; 


$userId  = requireUser($pdo);
$current = 'transactions';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Transactions</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}</style>
</head>
<body class="bg-gray-50">
  <?php if (file_exists(__DIR__.'/partials/navbar.php')) include __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-6xl p-4 md:p-6">
    <h1 class="text-2xl font-semibold tracking-tight">Transactions</h1>

    <!-- Filters -->
    <form id="filters" class="mt-4 grid grid-cols-1 gap-4 rounded-lg bg-white p-4 shadow-sm md:grid-cols-4">
      <div>
        <label class="block text-sm font-medium text-gray-700">Accounts / Cards</label>
        <select id="f-account" multiple class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600 h-[140px]"></select>
        <p id="accountsHelp" class="mt-1 text-xs text-gray-500">Choose specific items, or keep “All accounts / cards”.</p>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">From</label>
        <input id="f-from" type="date" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">To</label>
        <input id="f-to" type="date" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
      </div>
      <div>
        <label class="block text-sm font-medium text-gray-700">Category</label>
        <select id="f-category" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600">
          <option value="">All</option>
        </select>
      </div>
      <div class="md:col-span-3">
        <label class="block text-sm font-medium text-gray-700">Search</label>
        <input id="f-q" type="text" placeholder="Description or merchant…" class="mt-1 w-full rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
      </div>
      <div class="flex items-end gap-2 md:justify-end">
        <button id="refreshBtn" class="rounded-md bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">Refresh</button>
        <button id="resetBtn" type="button" class="rounded-md border px-4 py-2 text-gray-700 hover:bg-gray-50">Reset</button>
      </div>
    </form>

    <!-- KPIs -->
    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Total Transactions</p>
        <p id="sum-count" class="mt-1 text-2xl font-semibold">0</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Total Spent</p>
        <p id="sum-spent" class="mt-1 text-2xl font-semibold text-red-600">£0.00</p>
      </div>
      <div class="rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-gray-500">Total Income</p>
        <p id="sum-income" class="mt-1 text-2xl font-semibold text-green-600">£0.00</p>
      </div>
    </div>

    <!-- Table -->
    <div class="mt-4 overflow-hidden rounded-lg bg-white shadow-sm">
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Description</th>
              <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Category</th>
              <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Amount</th>
            </tr>
          </thead>
          <tbody id="tbody" class="divide-y divide-gray-200 bg-white"></tbody>
        </table>
      </div>
      <div class="flex items-center justify-between p-3">
        <div class="text-sm text-gray-500" id="pageMeta"></div>
        <div class="flex gap-2">
          <button id="prevBtn" class="rounded-md border px-3 py-1 text-sm hover:bg-gray-50">Prev</button>
          <button id="nextBtn" class="rounded-md border px-3 py-1 text-sm hover:bg-gray-50">Next</button>
        </div>
      </div>
    </div>

    <div id="empty" class="mt-6 hidden rounded-lg border border-dashed p-10 text-center text-gray-500">
      No transactions yet. Try widening your date range or refreshing.
    </div>
  </main>

  <script>
  const $ = (id) => document.getElementById(id);

  // auto-detect /public base (works under any folder)
  const PUBLIC_BASE = (() => {
    const p = location.pathname.toLowerCase();
    const i = p.indexOf('/public/');
    return i >= 0 ? location.pathname.slice(0, i + 7) : '/SimpleMoney/public';
  })();
  const api = (name) => `${PUBLIC_BASE}/api/${name}`;

  const state = { page: 1, size: 50, lastKey: '' };

  const fmt = (v,c='GBP') => {
    const n = Number(v||0);
    const s = n.toLocaleString('en-GB',{style:'currency',currency:c});
    return s.replace('GBP','£');
  };

  function normDate(s){
    if(!s) return '';
    const m=s.match(/^(\d{2})-(\d{2})-(\d{4})$/);
    return m ? `${m[3]}-${m[2]}-${m[1]}` : s;
  }
  function clampDates(from,to){
    const today=new Date().toISOString().slice(0,10);
    from=normDate(from); to=normDate(to);
    if(from && from>today) from=today;
    if(to && to>today) to=today;
    if(from && to && from>to){ const t=from; from=to; to=t; }
    return {from,to};
  }

  async function fetchJson(url, opts={}){
    const r = await fetch(url,{credentials:'same-origin',...opts});
    const ct = r.headers.get('content-type') || '';
    if(!ct.includes('application/json')){
      const t = await r.text();
      console.error('Non-JSON', t.slice(0,400));
      throw new Error('Unexpected response (not JSON)');
    }
    const j = await r.json();
    if(!r.ok){ console.error('API error', j); throw new Error(j?.error||'api'); }
    return j;
  }

  async function loadCategories(){
    const sel = $('f-category');
    sel.innerHTML = '<option value="">All</option>';
    try{
      const data = await fetchJson(api('categories.php'));
      for(const c of (data.items||[])){
        const o = document.createElement('option');
        o.value = c.id; o.textContent = c.name;
        sel.appendChild(o);
      }
    }catch(e){
      console.warn('categories load failed', e);
      // fallback to “All” only
    }
  }

  async function loadAccounts(){
    const sel = $('f-account');
    sel.innerHTML = '';
    const all = document.createElement('option');
    all.value='__all__'; all.textContent='All accounts / cards'; all.selected=true;
    sel.appendChild(all);

    try{
      const data = await fetchJson(api('accounts.php'));
      (data.items||[]).forEach(a=>{
        const id = a.account_id || a.id || a.card_id;
        if(!id) return;
        const opt = document.createElement('option');
        opt.value = id;
        opt.dataset.type = a.type || (a.last4 ? 'card' : 'account');
        opt.textContent = a.label || `${(a.provider||'').toString().toUpperCase()} • ${a.display_name||''}`;
        sel.appendChild(opt);
      });

      sel.addEventListener('change', ()=>{
        const chosen = Array.from(sel.selectedOptions).map(o=>o.value);
        if(chosen.length>1 && chosen.includes('__all__')){
          for(const o of sel.options) if(o.value==='__all__') o.selected = false;
        }
        if(chosen.length===0){
          for(const o of sel.options) if(o.value==='__all__') o.selected = true;
        }
      });
    }catch(e){
      $('accountsHelp').textContent = 'Could not load accounts.';
      console.error(e);
    }
  }

  function selectedIds(){
    const ids=[];
    for(const o of $('f-account').selectedOptions){
      if(o.value && o.value!=='__all__') ids.push({id:o.value,type:o.dataset.type||''});
    }
    return ids;
  }

  function buildUrl(keepPage=false){
    const sel = selectedIds();
    const {from,to} = clampDates($('f-from').value, $('f-to').value);
    const catId = $('f-category').value;
    const q = ($('f-q').value||'').trim();

    const u = new URL(api('transactions.php'), window.location.origin);
    if(sel.length===0){ u.searchParams.set('account_id','__all__'); }
    else if(sel.length===1){
      u.searchParams.set('account_id', sel[0].id);
      if(sel[0].type) u.searchParams.set('type', sel[0].type);
    } else {
      u.searchParams.set('account_ids', sel.map(s=>s.id).join(','));
    }
    if(from) u.searchParams.set('from', from);
    if(to)   u.searchParams.set('to', to);
    if(catId) u.searchParams.set('category_id', catId); // <— DB category filter
    if(q) u.searchParams.set('q', q);

    if(!keepPage) state.page = 1;
    u.searchParams.set('page', state.page);
    u.searchParams.set('size', state.size);
    return u.toString();
  }

  async function fetchAndRender(keepPage=false){
    const url = buildUrl(keepPage);
    const key = url.replace(/([?&])page=\d+/, '$1');
    const same = (key === state.lastKey);
    if(!same && keepPage) state.page = 1;
    state.lastKey = key;

    const data = await fetchJson(url);
    render(data);
  }

  function render(data){
    const tbody = $('tbody'); tbody.innerHTML='';

    let spent=0, income=0;
    for(const t of (data.items||[])){
      const a = Number(t.amount||0);
      if(a<0) spent+=a; else income+=a;
      const tr = document.createElement('tr');
      const date = (t.date ?? (t.ts||'')).toString().slice(0,10);
      const desc = t.description || t.merchant || '(no description)';
      const cat  = t.category_name || t.category || 'Other';
      tr.innerHTML = `
        <td class="whitespace-nowrap px-4 py-2 text-sm text-gray-700">${date}</td>
        <td class="px-4 py-2 text-sm text-gray-900">${desc}</td>
        <td class="px-4 py-2 text-sm text-gray-700">${cat}</td>
        <td class="whitespace-nowrap px-4 py-2 text-right text-sm ${a<0?'text-red-600':'text-green-600'}">${fmt(a, t.currency||'GBP')}</td>`;
      tbody.appendChild(tr);
    }

    $('sum-count').textContent  = String(data.total ?? (data.items||[]).length);
    $('sum-spent').textContent  = fmt(spent);
    $('sum-income').textContent = fmt(income);

    if(!data.items || data.items.length===0) $('empty').classList.remove('hidden');
    else $('empty').classList.add('hidden');

    const pages = Math.max(1, Math.ceil((data.total||0)/state.size));
    $('prevBtn').disabled = (state.page<=1);
    $('nextBtn').disabled = (state.page>=pages);
    $('pageMeta').textContent = `Page ${state.page} of ${pages}`;
  }

  // events
  $('filters').addEventListener('submit', e=>{ e.preventDefault(); fetchAndRender(false); });
  $('refreshBtn').addEventListener('click', e=>{ e.preventDefault(); fetchAndRender(false); });
  $('resetBtn').addEventListener('click', ()=>{
    $('f-from').value=''; $('f-to').value=''; $('f-q').value=''; $('f-category').value='';
    for(const o of $('f-account').options) o.selected = (o.value==='__all__');
    fetchAndRender(false);
  });
  $('prevBtn').addEventListener('click', ()=>{ if(state.page>1){ state.page--; fetchAndRender(true); } });
  $('nextBtn').addEventListener('click', ()=>{ state.page++; fetchAndRender(true); });

  // boot
  document.addEventListener('DOMContentLoaded', async ()=>{
    const now=new Date(), y=now.getFullYear(), m=now.getMonth();
    $('f-from').value = new Date(y,m,1).toISOString().slice(0,10);
    $('f-to').value   = new Date().toISOString().slice(0,10);

    await Promise.all([loadCategories(), loadAccounts()]);
    await fetchAndRender(false);
  });
  </script>
</body>
</html>
