<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth_guard.php';
$userId  = requireUser($pdo);
$current = 'connect';

/* Compute the correct /public base and TL start link (no hardcoding) */
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$pos    = stripos($script, '/public/');
$PUBLIC_BASE = ($pos !== false) ? substr($script, 0, $pos + 7) : '/SimpleMoney/public';
$TL_START    = $PUBLIC_BASE . '/auth/tl_start.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>SimpleMoney • Connect Bank</title>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    html{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif}
    .btn-icon:active{transform:scale(.97)}
  </style>
</head>
<body class="bg-gray-50">
  <?php if (file_exists(__DIR__.'/partials/navbar.php')) include __DIR__ . '/partials/navbar.php'; ?>

  <main class="mx-auto max-w-5xl p-4 md:p-6">
    <h1 class="text-2xl font-semibold tracking-tight">Connect your bank</h1>
    <p class="mt-2 text-gray-600">Link a bank or credit card via TrueLayer to import accounts and transactions.</p>

    <div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
      <!-- DIRECT link to TrueLayer start (same logic as your old page) -->
      <a href="<?= htmlspecialchars($TL_START) ?>"
         class="inline-flex items-center rounded-md bg-blue-600 px-4 py-2 text-white shadow-sm hover:bg-blue-700">
        Connect with TrueLayer
      </a>

      <hr class="my-4 border-gray-200"/>

      <h2 class="text-lg font-semibold">Connected banks</h2>
      <div id="connList" class="mt-3 space-y-3"></div>

      <div id="emptyState" class="mt-6 hidden rounded-lg border border-dashed p-8 text-center text-gray-500">
        No connections yet. Click <span class="font-semibold">Connect with TrueLayer</span> to get started.
      </div>
    </div>
  </main>

  <script>
  // Server-provided paths (no guessing on the client)
  const PUBLIC_BASE = <?= json_encode($PUBLIC_BASE) ?>;
  const TL_START    = <?= json_encode($TL_START) ?>;

  const elList  = document.getElementById('connList');
  const elEmpty = document.getElementById('emptyState');

  // Fallback avatar when provider logo is missing/broken
  function buildFallbackAvatar(){
    const wrap = document.createElement('div');
    wrap.className = 'flex h-9 w-9 items-center justify-center rounded-full bg-blue-100 ring-1 ring-blue-200';
    wrap.innerHTML = `
      <svg viewBox="0 0 24 24" class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M3 10.5l9-6 9 6" />
        <path d="M5 10.5h14v8.5H5z" />
        <path d="M9 13.5v5M15 13.5v5" />
      </svg>`;
    return wrap;
  }

  // Better colored icons (no hover titles)
  function iconReconnect(){
    const b = document.createElement('a');
    b.className = 'btn-icon rounded-full p-2 text-blue-600 hover:bg-blue-50 focus:outline-none';
    // We set href later per-connection
    b.innerHTML = `
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9">
        <path d="M3 12a9 9 0 1 0 3-6.75" stroke-linecap="round"/>
        <path d="M3 3v6h6" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>`;
    return b;
  }
  function iconDelete(){
    const b = document.createElement('button');
    b.className = 'btn-icon rounded-full p-2 text-rose-600 hover:bg-rose-50 focus:outline-none';
    b.innerHTML = `
      <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9">
        <path d="M3 6h18M10 11v6M14 11v6" stroke-linecap="round"/>
        <path d="M8 6v-1.2A2.8 2.8 0 0 1 10.8 2h2.4A2.8 2.8 0 0 1 16 4.8V6" />
        <path d="M6.5 6.5v11A2.5 2.5 0 0 0 9 20h6a2.5 2.5 0 0 0 2.5-2.5v-11" />
      </svg>`;
    return b;
  }

  // Safer JSON parsing (helps surface 404/HTML issues)
  async function parseJsonSafe(r){
    const txt = await r.text();
    try { return JSON.parse(txt); } catch(_) { throw new Error(`Bad JSON (${r.status}): ${txt.slice(0,180)}…`); }
  }

  // Load connections with pretty labels + logos
  async function loadConnections(){
    elList.innerHTML = '';
    try{
      // Uses the small API that enriches labels/logos by probing TL once per connection
      const r = await fetch(`${PUBLIC_BASE}/api/connections.php`, {credentials:'same-origin'});
      const data = await parseJsonSafe(r);
      const items = data?.items || [];
      if (!items.length){ elEmpty.classList.remove('hidden'); return; }
      elEmpty.classList.add('hidden');

      items.forEach(row => {
        const li = document.createElement('div');
        li.className = 'group flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3 transition-colors hover:bg-gray-50';

        const left = document.createElement('div');
        left.className = 'flex items-center gap-3 min-w-0';

        // Logo or fallback avatar
        if (row.logo_uri){
          const imgWrap = document.createElement('div');
          imgWrap.className = 'h-9 w-9 overflow-hidden rounded-full ring-1 ring-gray-200 bg-white';
          const img = document.createElement('img');
          img.src = row.logo_uri;
          img.alt = row.provider_label || 'Bank';
          img.className = 'h-9 w-9 object-cover';
          img.onerror = () => { imgWrap.replaceWith(buildFallbackAvatar()); };
          imgWrap.appendChild(img);
          left.appendChild(imgWrap);
        } else {
          left.appendChild(buildFallbackAvatar());
        }

        // Labels
        const texts = document.createElement('div');
        texts.className = 'min-w-0';
        const title = document.createElement('div');
        title.className = 'truncate text-sm font-medium text-gray-900';
        title.textContent = row.provider_label || 'Bank';
        const sub = document.createElement('div');
        sub.className = 'truncate text-xs text-gray-500';
        sub.textContent = 'connected ' + (row.connected_at_human || row.connected_at || '');
        texts.appendChild(title); texts.appendChild(sub);
        left.appendChild(texts);

        // Right controls
        const right = document.createElement('div');
        right.className = 'ml-3 flex items-center gap-2 shrink-0';

        // Reconnect goes DIRECTLY to tl_start.php (like your old flow).
        // If tl_start.php supports reauth params, it can read action=reauth&id.
        const btnRe = iconReconnect();
        btnRe.href = `${TL_START}?action=reauth&id=${encodeURIComponent(row.id)}`;

        // Delete uses a tiny API
        const btnDel = iconDelete();
        btnDel.addEventListener('click', ()=> del(row.id, row.provider_label));

        right.appendChild(btnRe);
        right.appendChild(btnDel);

        li.appendChild(left);
        li.appendChild(right);
        elList.appendChild(li);
      });

    }catch(e){
      console.error(e);
      alert('Failed to load connections.');
    }
  }

  async function del(id, label){
    if (!confirm(`Delete connection ${label || '#'+id}?`)) return;
    try{
      const r = await fetch(`${PUBLIC_BASE}/api/connection_delete.php`, {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json','Accept':'application/json'},
        body: JSON.stringify({ id })
      });
      const data = await parseJsonSafe(r);
      if (r.ok && data?.ok) {
        await loadConnections();
      } else {
        alert('Could not delete connection.');
      }
    }catch(e){
      console.error(e); alert('Network error deleting connection.');
    }
  }

  document.addEventListener('DOMContentLoaded', loadConnections);
  </script>
</body>
</html>
