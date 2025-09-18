<?php
// public/partials/chat_widget.php
// Lightweight floating chat widget (bottom-right) for SimpleMoney

if (session_status() === PHP_SESSION_NONE) session_start();
$smUserKey = 'sm_chat_' . ($_SESSION['user_id'] ?? 'guest');
?>
<!-- Chat FAB -->
<button id="sm-chat-fab"
        class="fixed bottom-4 right-4 z-50 inline-flex items-center gap-2 rounded-full bg-blue-600 px-4 py-3 text-white shadow-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-600"
        aria-label="Open Spend Coach">
  <svg viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5"><path d="M20 2H4a2 2 0 00-2 2v18l4-4h14a2 2 0 002-2V4a2 2 0 00-2-2z"/></svg>
  <span class="hidden sm:inline">Ask SimpleMoney</span>
</button>

<!-- Chat Panel -->
<div id="sm-chat-panel"
     class="fixed bottom-20 right-4 z-50 w-[92vw] max-w-md translate-y-4 opacity-0 pointer-events-none transition-all duration-200">
  <div class="flex h-[70vh] max-h-[640px] flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl">
    <!-- Header -->
    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
      <div>
        <div class="text-sm font-semibold text-gray-900">Spend Coach (beta)</div>
        <div class="text-xs text-gray-500">Ask about your spending, budgets, and trends</div>
      </div>
      <button id="sm-chat-close" class="rounded-md p-2 text-gray-500 hover:bg-gray-100" aria-label="Close chat">
        <svg viewBox="0 0 24 24" fill="none" class="h-5 w-5"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      </button>
    </div>

    <!-- Messages -->
    <div id="sm-chat-log" class="flex-1 space-y-3 overflow-auto bg-gray-50 px-3 py-3"></div>

    <!-- Input -->
    <form id="sm-chat-form" class="border-t border-gray-200 p-3">
      <div class="flex gap-2">
        <input id="sm-chat-input" type="text" autocomplete="off" placeholder="e.g., What did I spend most on this month?"
               class="min-w-0 flex-1 rounded-md border-gray-300 focus:border-blue-600 focus:ring-blue-600"/>
        <button class="rounded-md bg-blue-600 px-4 text-sm font-medium text-white hover:bg-blue-700">Send</button>
      </div>
      <p class="mt-2 text-[11px] leading-4 text-gray-500">Tips: “groceries last month”, “unusual charges this week”, “am I over budget?”</p>
    </form>
  </div>
</div>

<script>
(function(){
  const FAB   = document.getElementById('sm-chat-fab');
  const PANEL = document.getElementById('sm-chat-panel');
  const CLOSE = document.getElementById('sm-chat-close');
  const LOG   = document.getElementById('sm-chat-log');
  const FORM  = document.getElementById('sm-chat-form');
  const INPUT = document.getElementById('sm-chat-input');
  const STORAGE_KEY = <?= json_encode($smUserKey) ?>;

  // Auto-detect /public base so it works under any folder depth
  const PUBLIC_BASE = (() => {
    const p = location.pathname.toLowerCase();
    const i = p.indexOf('/public/');
    return i >= 0 ? location.pathname.slice(0, i + 7) : '/SimpleMoney/public';
  })();
  const CHAT_API = `${PUBLIC_BASE}/api/chat.php`; // <-- correct endpoint

  let sending = false;

  function openPanel(){
    PANEL.classList.remove('pointer-events-none','translate-y-4','opacity-0');
    INPUT.focus();
  }
  function closePanel(){
    PANEL.classList.add('pointer-events-none','translate-y-4','opacity-0');
  }

  function saveHistory(){
    const msgs = Array.from(LOG.querySelectorAll('[data-role="msg"]')).map(el=>({
      who: el.getAttribute('data-who'),
      html: el.querySelector('[data-inner]').innerHTML
    })).slice(-40);
    localStorage.setItem(STORAGE_KEY, JSON.stringify(msgs));
  }
  function loadHistory(){
    const raw = localStorage.getItem(STORAGE_KEY);
    if(!raw) {
      tip('Tip: "Where did I spend the most this month?"');
      tip('Tip: "Any unusual charges this week?"');
      return;
    }
    try {
      const msgs = JSON.parse(raw);
      msgs.forEach(m => bubbleHTML(m.html, m.who));
    } catch(_) {}
  }

  function bubble(text, who='bot'){
    const wrap = document.createElement('div');
    wrap.setAttribute('data-role','msg');
    wrap.setAttribute('data-who', who);
    wrap.className = (who==='you' ? 'text-right' : 'text-left');
    wrap.innerHTML = `
      <div class="${who==='you' ? 'bg-blue-600 text-white' : 'bg-white text-gray-900 border'} inline-block max-w-[90%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm">
        <div data-inner>${escapeHtml(text)}</div>
      </div>`;
    LOG.appendChild(wrap);
    LOG.scrollTop = LOG.scrollHeight;
    saveHistory();
  }
  function bubbleHTML(html, who='bot'){
    const wrap = document.createElement('div');
    wrap.setAttribute('data-role','msg');
    wrap.setAttribute('data-who', who);
    wrap.className = (who==='you' ? 'text-right' : 'text-left');
    wrap.innerHTML = `
      <div class="${who==='you' ? 'bg-blue-600 text-white' : 'bg-white text-gray-900 border'} inline-block max-w-[90%] whitespace-pre-wrap rounded-lg px-3 py-2 text-sm">
        <div data-inner>${html}</div>
      </div>`;
    LOG.appendChild(wrap);
    LOG.scrollTop = LOG.scrollHeight;
  }
  function tip(t){ bubble(t, 'bot'); }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  function thinking(){
    const id = 'sm-thinking';
    const div = document.createElement('div');
    div.id = id;
    div.className = 'text-left';
    div.innerHTML = `
      <div class="inline-flex items-center gap-2 rounded-lg border bg-white px-3 py-2 text-sm text-gray-700">
        <span>Thinking</span>
        <span class="inline-flex">
          <span class="h-1 w-1 animate-bounce rounded-full bg-gray-400 mr-0.5"></span>
          <span class="h-1 w-1 animate-bounce rounded-full bg-gray-400 mr-0.5" style="animation-delay:.1s"></span>
          <span class="h-1 w-1 animate-bounce rounded-full bg-gray-400" style="animation-delay:.2s"></span>
        </span>
      </div>`;
    LOG.appendChild(div);
    LOG.scrollTop = LOG.scrollHeight;
    return () => { const n = document.getElementById(id); if(n) n.remove(); };
  }

  async function ask(q){
    if (sending) return;
    sending = true;
    const stopThink = thinking();
    try{
      const r = await fetch(CHAT_API, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({message:q})
      });
      let data;
      try { data = await r.json(); } catch { data = null; }

      stopThink();
      sending = false;

      if (!data) {
        bubble('Sorry—unexpected response from server.', 'bot');
        return;
      }
      if (data.ok === false) {
        const detail = data.detail ? ` (${String(data.detail)})` : '';
        let msg = 'Sorry—something went wrong.';
        if (data.error === 'missing_api_key') msg = 'API key is not configured on the server.';
        else if (data.error === 'method_not_allowed') msg = 'Chat API only accepts POST.';
        else if (data.error === 'missing_message') msg = 'Please type a message.';
        bubble(`${msg}${detail}`, 'bot');
        return;
      }

      const reply = (data && data.reply) ? String(data.reply) : 'Sorry, I could not find that.';
      bubble(reply, 'bot');
    }catch(e){
      stopThink();
      sending = false;
      bubble('Sorry—network error calling the chat service.', 'bot');
      console.error(e);
    }
  }

  FAB.addEventListener('click', openPanel);
  CLOSE.addEventListener('click', closePanel);

  // Submit (Enter), optional: Ctrl+Enter also sends
  FORM.addEventListener('submit', (e)=>{
    e.preventDefault();
    const q = INPUT.value.trim();
    if(!q) return;
    bubble(q, 'you');
    INPUT.value = '';
    ask(q);
  });
  INPUT.addEventListener('keydown', (e)=>{
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      e.preventDefault();
      FORM.dispatchEvent(new Event('submit', {cancelable:true}));
    }
  });

  // Esc closes panel
  document.addEventListener('keydown', (e)=>{ if(e.key === 'Escape') closePanel(); });

  loadHistory();
})();
</script>
