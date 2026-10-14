/* LUNAHAN — minimal service worker: sadece kurulabilirlik + statik varlik onbellegi. */
const CACHE = 'lunahan-v1';
const STATIC = ['assets/app.css', 'assets/lunify-lockup.png', 'assets/icon-192.png'];
self.addEventListener('install', e => { self.skipWaiting(); e.waitUntil(caches.open(CACHE).then(c => c.addAll(STATIC).catch(() => {}))); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(k => Promise.all(k.filter(x => x !== CACHE).map(x => caches.delete(x))))); self.clients.claim(); });
self.addEventListener('fetch', e => {
  const r = e.request;
  if (r.method !== 'GET') return;                    // POST'lar her zaman aga gider
  const u = new URL(r.url);
  if (u.origin !== location.origin) return;
  if (!/\.(css|js|png|jpg|svg|woff2?)$/.test(u.pathname)) return;   // sayfalar hep canli
  e.respondWith(caches.match(r).then(c => c || fetch(r).then(res => {
    const cp = res.clone(); caches.open(CACHE).then(ch => ch.put(r, cp)); return res;
  }).catch(() => c)));
});
