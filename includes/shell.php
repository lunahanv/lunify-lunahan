<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/**
 * LUNAHAN — uygulama kabugu (shell)
 * Tasarim dili Lunify Aura/Nexo ile ayni: Inter, Tailwind primary paleti,
 * beyaz sol menu, lucide ikonlar, karanlik mod.  (Viktor, 2026-08-28)
 */
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/onboarding.php';
/* 2026-09-09: emlak modulu LUNAHAN'dan cikarildi (kisisel asistan). Emlak = Lunify Anahtar. */

function atlas_nav_items(array $u, string $active = ''): array {
    $L = atlas_lang();
    $tr = $L === 'tr';
    $nav = [
        'chat'     => [$tr ? 'Sohbet'        : 'Chat',      'message-square', 'app.php'],
        'tasks'    => [$tr ? 'Görevler'      : 'Tasks',     'check-circle',   'tasks.php'],
        'contacts' => [$tr ? 'Rehber'        : 'Contacts',  'users',          'contacts.php'],
        'gmail'    => ['Gmail',                              'mail',           'gmail.php'],
        'guide'    => [$tr ? 'Kılavuz'        : 'Guide',     'book-open',      'guide.php'],
    ];
    /* Sektor modulu: emlak (kullanici bazli acilir) */
    $nav['wa'] = ['WhatsApp', 'message-circle', 'wa.php'];
    if (!empty($u['is_admin'])) $nav['links'] = [$tr ? 'Bağlantılar' : 'Connections', 'link', 'links.php'];
    $nav['account'] = [$tr ? 'Hesabım' : 'Account', 'credit-card', 'account.php'];
    if (false) {   // Yonetim ayri kapidan: /yonetim.php (kullanici menusunde gorunmez)
        $nav['admin'] = [$tr ? 'Yönetim' : 'Admin', 'shield', 'admin.php'];
    }
    /* Modul ac/kapa (Viktor 2026-09-10, owner istegi): yonetici modulleri kapatabilir.
       Genel liste  -> settings(user_id=0).modules_off ; kullanici bazli -> settings(user).modules_off
       Yonetim (admin) ve Hesabim asla kapatilmaz. */
    $offGlobal = array_filter(array_map('trim', explode(',', (string)gsetting('modules_off', ''))));
    $offUser   = array_filter(array_map('trim', explode(',', (string)setting('modules_off', ''))));
    $off = array_unique(array_merge($offGlobal, $offUser));
    foreach ($off as $ok) {
        if (in_array($ok, ['admin', 'account'], true)) continue;
        unset($nav[$ok]);
    }
    $saved = json_decode((string)setting('sidebar_order', ''), true);
    if (is_array($saved) && $saved) {
        $ordered = [];
        foreach ($saved as $k) { if (isset($nav[$k])) $ordered[$k] = $nav[$k]; }
        foreach ($nav as $k => $v) { if (!isset($ordered[$k])) $ordered[$k] = $v; }
        $nav = $ordered;
    }
    /* Yonetim ekraninda uygulama modulleri GIZLENIR (sahibin istegi 2026-09-04):
       admin sadece yonetim + ayar niteligindeki ekranlari gorur. */
    if ($active === 'admin' || ($active === 'links' && !empty($u['is_admin'])) || ($active === 'wa' && !empty($u['is_admin']) && ($_GET['adm'] ?? '') === '1')) {
        $adm = [];
        $adm['admin']   = [$tr ? 'Yönetim'  : 'Admin',    'shield',         'admin.php'];
        $adm['wa']      = ['WhatsApp',                     'message-circle', 'wa.php'];
        $adm['links']   = [$tr ? 'Bağlantılar' : 'Connections', 'link',       'links.php'];
        $adm['back']    = [$tr ? '← Uygulamaya dön' : '← Back to app', 'arrow-left', 'app.php'];
        return $adm;
    }
    if (function_exists('ob_show_nav') && ob_show_nav($active)) {
        $nav = ['start' => [$tr ? 'Başlangıç' : 'Getting started', 'rocket', 'getting-started.php']] + $nav;
    }
    return $nav;
}

function atlas_header(string $title, string $active = '', string $subtitle = '', bool $fullHeight = false): void {
    $L = atlas_lang();
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $nav = atlas_nav_items($u, $active);
    [, $obDone, $obTotal] = function_exists('ob_progress') ? ob_progress() : [null, 0, 0];
    $logo = 'assets/lunify-lockup.png';
    $logoDark = 'assets/lunify-lockup-dark.png';
    $initial = mb_strtoupper(mb_substr(trim((string)($u['name'] ?? ($u['email'] ?? 'A'))), 0, 1, 'UTF-8'), 'UTF-8');
    ?><!doctype html>
<html lang="<?= $L ?>" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars(atlas_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($title) ?> — LUNAHAN</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="LUNAHAN">
<meta name="theme-color" content="#ffffff">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { darkMode: 'class', theme: { extend: { colors: { primary: {
  50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',500:'#3b82f6',
  600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a' }}}}};
</script>
<link href="https://unpkg.com/lucide-static@latest/font/lucide.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/app.css?v=<?= @filemtime(__DIR__ . '/../assets/app.css') ?: time() ?>" rel="stylesheet">
<script>
if (localStorage.getItem('atlasDark') === 'true' || (!localStorage.getItem('atlasDark') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
  document.documentElement.classList.add('dark');
}
</script>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#2563eb">
</head>
<body class="h-full bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-200 antialiased" style="font-family:Inter,system-ui,sans-serif">
<div id="app" class="flex h-full">

  <div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden" onclick="atlasSidebar(false)"></div>

  <aside id="sidebar" class="fixed lg:static inset-y-0 left-0 z-40 w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 flex flex-col transform -translate-x-full lg:translate-x-0 transition-transform duration-200">
    <div class="relative px-4 py-6 border-b border-gray-200 dark:border-gray-700">
      <a href="app.php?lang=<?= $L ?>" class="flex flex-col items-center gap-2 hover:opacity-80 transition-opacity">
        <img src="<?= $logo ?>" alt="Lunify" class="h-12 w-auto dark:hidden object-contain">
        <img src="<?= $logoDark ?>" alt="Lunify" class="h-12 w-auto hidden dark:block object-contain">
        <span class="text-[17px] font-bold text-gray-900 dark:text-gray-100 tracking-tight">LUNAHAN</span>
      </a>
      <button onclick="atlasSidebar(false)" class="lg:hidden absolute top-3 right-3 p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Kapat">
        <i class="icon-x" style="font-size:18px"></i>
      </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1">
      <div class="flex items-center justify-between px-2 pb-1">
        <span class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500"><?= $L === 'tr' ? 'Menü' : 'Menu' ?></span>
        <button type="button" onclick="atlasSortReset()" title="<?= $L === 'tr' ? 'Sırayı sıfırla' : 'Reset order' ?>" class="p-1 rounded text-gray-300 hover:text-primary-600 dark:hover:text-primary-300">
          <i class="icon-rotate-ccw" style="font-size:13px"></i>
        </button>
      </div>
      <?php foreach ($nav as $key => [$label, $icon, $href]): $on = ($active === $key); ?>
      <a href="<?= $href ?><?= strpos($href, '?') === false ? '?' : '&' ?>lang=<?= $L ?>" draggable="false" data-page="<?= $key ?>" class="s-item flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $on
            ? 'bg-primary-50 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300'
            : 'text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/60' ?>">
        <span class="s-handle" title="<?= $L === 'tr' ? 'Sürükle' : 'Drag' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9h16M4 15h16"/></svg></span>
        <i class="icon-<?= $icon ?>" style="font-size:17px"></i>
        <span class="flex-1"><?= htmlspecialchars($label) ?></span>
        <?php if ($key === 'start' && $obTotal): ?><span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-primary-600 text-white"><?= $obDone ?>/<?= $obTotal ?></span><?php endif; ?>
        <?php if ($key === 'tasks'): ?><span id="navPending" class="hidden text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-500 text-white"></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="px-3 py-3 border-t border-gray-200 dark:border-gray-700 space-y-3">
      <div class="flex items-center justify-between px-1">
        <?= atlas_lang_switch() ?>
        <button onclick="atlasTheme()" title="Tema" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 dark:hover:text-gray-200">
          <i class="icon-moon dark:hidden" style="font-size:16px"></i>
          <i class="icon-sun hidden dark:inline" style="font-size:16px"></i>
        </button>
      </div>
      <div class="flex items-center gap-2.5 px-1">
        <div class="w-8 h-8 rounded-full bg-primary-600 text-white flex items-center justify-center text-xs font-semibold shrink-0"><?= htmlspecialchars($initial) ?></div>
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-gray-900 dark:text-white truncate"><?= htmlspecialchars((string)($u['name'] ?? '')) ?></div>
          <div class="text-[11px] text-gray-500 dark:text-gray-400 truncate"><?= htmlspecialchars((string)($u['email'] ?? '')) ?></div>
        </div>
        <form method="post" action="app.php?lang=<?= $L ?>" class="inline">
          <input type="hidden" name="logout" value="1">
          <button type="submit" title="<?= t('app_logout') ?>" aria-label="<?= t('app_logout') ?>" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100 dark:hover:bg-gray-700 dark:hover:text-gray-200">
            <i class="icon-log-out" style="font-size:16px"></i>
          </button>
        </form>
      </div>
    </div>
  </aside>

  <main class="flex-1 min-w-0 flex flex-col <?= $fullHeight ? 'overflow-hidden' : 'overflow-y-auto' ?>">
    <div class="lg:hidden sticky top-0 z-30 flex items-center gap-2.5 px-3 py-2.5 bg-white/95 dark:bg-gray-800/95 backdrop-blur border-b border-gray-200 dark:border-gray-700">
      <button onclick="atlasSidebar(true)" class="p-2 -m-0.5 rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Menu">
        <i class="icon-menu" style="font-size:20px"></i>
      </button>
      <span class="text-sm font-semibold text-gray-900 dark:text-gray-100 truncate">LUNAHAN<?= $title !== '' ? ' · ' . htmlspecialchars($title) : '' ?></span>
    </div>
    <div class="p-4 lg:p-6 flex-1 min-h-0 <?= $fullHeight ? 'flex flex-col' : '' ?>">
      <div class="atlas-page mx-auto w-full max-w-5xl <?= $fullHeight ? 'flex-1 min-h-0 flex flex-col' : '' ?>">
        <?php if ($title !== ''): ?>
        <div class="mb-5">
          <h1 class="text-xl lg:text-2xl font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($title) ?></h1>
          <?php if ($subtitle !== ''): ?><p class="text-sm text-gray-500 dark:text-gray-400 mt-1"><?= htmlspecialchars($subtitle) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
<?php }

function atlas_footer(): void { ?>
      </div>
    </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<style>
.s-handle{cursor:grab;opacity:0;transition:opacity .15s;user-select:none;width:12px;margin-left:-8px;display:inline-flex;align-items:center;flex:none}
.s-handle svg{width:12px;height:12px}
.s-item:hover .s-handle,.s-item.atlas-sort-chosen .s-handle{opacity:.55}
@media(max-width:1023px){.s-handle{opacity:.45}}
.atlas-sort-ghost{opacity:.4;background:rgba(59,130,246,.1)!important;border:2px dashed #3b82f6!important}
.atlas-sort-chosen{box-shadow:0 4px 12px rgba(0,0,0,.15)}
</style>
<script>
function atlasSidebar(open){
  var s=document.getElementById('sidebar'), o=document.getElementById('sidebar-overlay');
  if(open){ s.classList.remove('-translate-x-full'); o.classList.remove('hidden'); }
  else { s.classList.add('-translate-x-full'); o.classList.add('hidden'); }
}
function atlasSortPost(action, order){
  return fetch('api/sidebar.php?action=' + action, {method:'POST',headers:{'Content-Type':'application/json'},
    body: JSON.stringify({order: order || []})}).then(r=>r.json());
}
function atlasSortReset(){ atlasSortPost('reset').then(d=>{ if(d && d.ok) location.reload(); }); }
(function(){
  var nav = document.querySelector('#sidebar nav');
  if(!nav || typeof Sortable === 'undefined') return;
  new Sortable(nav, {animation:150, handle:'.s-handle', draggable:'.s-item',
    ghostClass:'atlas-sort-ghost', chosenClass:'atlas-sort-chosen', forceFallback:true,
    onEnd: function(){
      var order = [];
      nav.querySelectorAll('.s-item').forEach(function(el){ order.push(el.getAttribute('data-page')); });
      atlasSortPost('save', order);
    }});
})();
function atlasTheme(){
  var d=document.documentElement.classList.toggle('dark');
  localStorage.setItem('atlasDark', d ? 'true' : 'false');
}
</script>
<script>if('serviceWorker' in navigator){navigator.serviceWorker.register('sw.js').catch(function(){});}</script>
<script>
(function(){
  const meta=document.querySelector('meta[name="csrf-token"]'); const token=meta?meta.content:'';
  document.querySelectorAll('form').forEach(function(f){
    if((f.getAttribute('method')||'get').toLowerCase()!=='post') return;
    if(!f.querySelector('input[name="_csrf"]')){const i=document.createElement('input');i.type='hidden';i.name='_csrf';i.value=token;f.appendChild(i);}
  });
  const original=window.fetch.bind(window);
  window.fetch=function(input,init){
    init=init||{}; const method=String(init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
    const url=new URL(input instanceof Request ? input.url : input,location.href);
    if(url.origin===location.origin && !['GET','HEAD','OPTIONS'].includes(method)){
      const headers=new Headers(input instanceof Request ? input.headers : undefined);
      new Headers(init.headers||{}).forEach((v,k)=>headers.set(k,v)); headers.set('X-CSRF-Token',token);
      init=Object.assign({},init,{headers});
    }
    return original(input,init);
  };
})();
</script>
</body></html>
<?php }
