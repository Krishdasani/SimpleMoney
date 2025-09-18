<?php
// /public/partials/navbar.php
// Helper to mark the active page
$current = $current ?? ''; // set this before include: $current = 'dashboard' | 'transactions' | ...
function navItem(string $href, string $label, string $key, string $current): string {
  $active = $key === $current;
  $base   = 'px-3 py-2 rounded-md text-sm font-medium';
  $on     = 'bg-blue-600 text-white';
  $off    = 'text-gray-700 hover:bg-gray-100';
  return "<a href=\"$href\" class=\"$base ".($active?$on:$off)."\">$label</a>";
}
?>
<header class="bg-white border-b border-gray-200">
  <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="flex h-16 items-center justify-between">
      <!-- Left: Brand -->
      <div class="flex items-center gap-3">
        <a href="/SimpleMoney/public/dashboard.php" class="flex items-center gap-2">
          <span class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-blue-600/10">
            <!-- pound icon -->
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-5 w-5 text-blue-600"><path fill-rule="evenodd" d="M12 2.25a.75.75 0 01.75.75v1.5h2.25a.75.75 0 010 1.5H12.75V9a3.75 3.75 0 01-2.77 3.612.75.75 0 00-.48.705V14.25h6a.75.75 0 010 1.5h-6v1.5h7.5a.75.75 0 010 1.5H3.75a.75.75 0 010-1.5H9v-1.5H6.75a.75.75 0 010-1.5H9v-1.45a5.25 5.25 0 003.75-5.05V6H9.75a.75.75 0 010-1.5H12V3a.75.75 0 01.75-.75z" clip-rule="evenodd"/></svg>
          </span>
          <span class="text-lg font-semibold text-gray-900">SimpleMoney</span>
        </a>
      </div>

      <!-- Desktop nav -->
      <nav class="hidden md:flex items-center gap-2">
        <?= navItem('/SimpleMoney/public/dashboard.php',     'Dashboard',   'dashboard',   $current) ?>
        <?= navItem('/SimpleMoney/public/transactions.php',  'Transactions','transactions',$current) ?>
        <?= navItem('/SimpleMoney/public/connect.php',       'Connect Bank','connect',      $current) ?>
        <?= navItem('/SimpleMoney/public/budgets.php',       'Budgets',     'budgets',     $current) ?>
        <?= navItem('/SimpleMoney/public/insights.php',      'Insights',    'insights',    $current) ?>
        <?= navItem('/SimpleMoney/public/settings.php',      'Settings',    'settings',    $current) ?>
      </nav>

      <!-- Right: Logout (desktop) -->
      <div class="hidden md:flex items-center">
        <a href="/SimpleMoney/public/auth/logout.php"
           class="px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100">Logout</a>
      </div>

      <!-- Mobile menu button -->
      <button id="sm-nav-toggle" class="md:hidden inline-flex items-center justify-center rounded-md p-2 text-gray-700 hover:bg-gray-100" aria-label="Open menu">
        <svg id="sm-nav-icon" class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor">
          <path id="sm-nav-icon-bars" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 12h16M4 17h16"/>
        </svg>
      </button>
    </div>
  </div>

  <!-- Mobile menu -->
  <div id="sm-nav-panel" class="md:hidden hidden border-t border-gray-200">
    <div class="space-y-1 px-4 py-3">
      <div class="grid grid-cols-2 gap-2">
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/dashboard.php',    'Dashboard',   'dashboard',   $current) ?></div>
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/transactions.php', 'Transactions','transactions',$current) ?></div>
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/connect.php',      'Connect Bank','connect',      $current) ?></div>
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/budgets.php',      'Budgets',     'budgets',     $current) ?></div>
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/insights.php',     'Insights',    'insights',    $current) ?></div>
        <div class="col-span-2"><?= navItem('/SimpleMoney/public/settings.php',     'Settings',    'settings',    $current) ?></div>
      </div>
      <a href="/SimpleMoney/public/auth/logout.php" class="block mt-2 px-3 py-2 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-100">Logout</a>
    </div>
  </div>
</header>

<script>
// Mobile menu toggle
document.addEventListener('DOMContentLoaded', function(){
  const btn = document.getElementById('sm-nav-toggle');
  const panel = document.getElementById('sm-nav-panel');
  if(!btn || !panel) return;
  btn.addEventListener('click', () => {
    panel.classList.toggle('hidden');
  });
});
</script>
