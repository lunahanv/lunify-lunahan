/* Copyright (c) 2025-2026 Lunify A.S. Tum haklari saklidir / All rights reserved.
   Ilan afisi sablon motoru. window.POSTER_TPL = [{n, pal, sizes, build(D)}] */
(function () {
  var SVG = {
    home: '<path d="M3 10.5 12 3l9 7.5V21H3z" fill="none" stroke="currentColor" stroke-width="2"/>',
    sofa: '<path d="M4 12a2 2 0 1 1 0 4v3h16v-3a2 2 0 1 1 0-4V9a3 3 0 0 0-3-3H7a3 3 0 0 0-3 3z" fill="none" stroke="currentColor" stroke-width="2"/>',
    pin: '<path d="M12 22s7-6.4 7-11a7 7 0 1 0-14 0c0 4.6 7 11 7 11z" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="11" r="2.4" fill="currentColor"/>',
    cap: '<path d="M2 8l10-4 10 4-10 4z" fill="currentColor"/><path d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5" fill="none" stroke="currentColor" stroke-width="2"/>',
    tree: '<path d="M12 3l5 7h-3l4 6H6l4-6H7z" fill="currentColor"/><rect x="11" y="16" width="2" height="5" fill="currentColor"/>',
    ruler: '<rect x="3" y="8" width="18" height="8" rx="1" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 8v4M12 8v4M16 8v4" stroke="currentColor" stroke-width="2"/>',
    car: '<path d="M4 16v-3l2-5h12l2 5v3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="7.5" cy="17" r="1.6" fill="currentColor"/><circle cx="16.5" cy="17" r="1.6" fill="currentColor"/>',
    fire: '<path d="M12 3c1 4 5 5 5 9a5 5 0 0 1-10 0c0-2 1-3 2-4 .3 2 1.5 2.5 2 2 .8-.8-.5-4 1-7z" fill="currentColor"/>',
    wifi: '<path d="M4 9a12 12 0 0 1 16 0M7 12.5a8 8 0 0 1 10 0" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="17" r="1.8" fill="currentColor"/>',
    stairs: '<path d="M4 20h4v-4h4v-4h4V8h4" fill="none" stroke="currentColor" stroke-width="2"/>',
    plug: '<path d="M9 2v5M15 2v5" stroke="currentColor" stroke-width="2"/><path d="M6 7h12v4a6 6 0 0 1-12 0z" fill="currentColor"/><path d="M12 17v5" stroke="currentColor" stroke-width="2"/>',
    lift: '<rect x="4" y="3" width="16" height="18" rx="1.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 3v18M8 8l1.6-2 1.6 2M13 16l1.6 2 1.6-2" stroke="currentColor" stroke-width="1.8" fill="none"/>',
    balcony: '<rect x="3" y="10" width="18" height="10" fill="none" stroke="currentColor" stroke-width="2"/><path d="M3 14h18M8 10v10M12 10v10M16 10v10M5 10V5h14v5" stroke="currentColor" stroke-width="1.6" fill="none"/>',
    build: '<rect x="4" y="3" width="16" height="18" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2" stroke="currentColor" stroke-width="2"/>',
    check: '<path d="M4 13l5 5L20 6" fill="none" stroke="currentColor" stroke-width="2.6"/>',
    bath: '<path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M7 12V6a2 2 0 0 1 4 0" fill="none" stroke="currentColor" stroke-width="2"/>',
    phone: '<path d="M6.6 10.8c1.2 2.4 3.2 4.4 5.6 5.6l2-2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.5.6.6 0 1 .4 1 1V19c0 .6-.4 1-1 1C10.6 20 4 13.4 4 5c0-.6.4-1 1-1h3.2c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.5.1.4 0 .8-.2 1z" fill="currentColor"/>'
  };
  function icoFor(t) {
    t = (t || '').toLocaleLowerCase('tr');
    if (/abonelik|elektrik|su\b|priz|sayaç/.test(t)) return 'plug';
    if (/eşyal|mobil|koltuk|beyaz eşya/.test(t)) return 'sofa';
    if (/internet|wifi|fiber|aidat/.test(t)) return 'wifi';
    if (/asansör/.test(t)) return 'lift';
    if (/balkon|teras/.test(t)) return 'balcony';
    if (/temiz|bakım|hemen taşın|kullanıma hazır/.test(t)) return 'check';
    if (/banyo|duş|çamaşır/.test(t)) return 'bath';
    if (/üniversite|okul|kampüs/.test(t)) return 'cap';
    if (/park|çaml|orman|yeşil|forum|bahçe/.test(t)) return 'tree';
    if (/m2|m²|metre|alan/.test(t)) return 'ruler';
    if (/otopark|garaj|araç/.test(t)) return 'car';
    if (/ısıt|kombi|doğalgaz|klima/.test(t)) return 'fire';
    if (/kat|zemin|bina|site|daire seçenek/.test(t)) return 'stairs';
    if (/\+\d|oda|villa|dükkan|arsa/.test(t)) return 'home';
    return 'pin';
  }
  function ico(name, cls) { return '<svg viewBox="0 0 24 24"' + (cls ? ' class="' + cls + '"' : '') + '>' + SVG[name] + '</svg>'; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function bg(u) { return u ? ' style="background-image:url(&quot;' + String(u).replace(/"/g, '%22') + '&quot;)"' : ''; }
  function money(v) { return esc(v).replace(/([A-Za-zŞşĞğ₺]+)\s*$/, '<small>$1</small>'); }
  function logoTag(D) { return D.logo ? '<img class="p-logo" src="' + D.logo + '" alt="">' : ''; }
  function photo(D, i) { return '<div class="ph"' + bg(D.photos[i]) + '></div>'; }

  /* ---------- T1 · Klasik ---------- */
  function t1(D) {
    var n = D.photos.length || 4, cells = '', i;
    for (i = 0; i < n; i++) {
      var wide = (n === 1 || (n === 3 && i === 2) || (n === 5 && i === 4)) ? ' wide' : '';
      cells += '<div class="ph' + wide + '"' + bg(D.photos[i]) + '></div>';
    }
    var side = D.feat.slice(0, 7).map(function (f) {
      return '<div class="f">' + ico(icoFor(f)) + '<span>' + esc(f) + '</span></div>';
    }).join('');
    return logoTag(D) +
      '<div class="p-head">' +
      '<div class="p-headrow"><div class="p-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--acc)" stroke-width="1.8"><path d="M3 10.5 12 3l9 7.5V21H3z"/><rect x="9" y="12" width="6" height="6"/></svg></div>' +
      '<div style="flex:1;min-width:0"><div class="p-kick">' + esc(D.kick) + '</div><div class="p-title">' + esc(D.title) + '</div></div></div>' +
      (D.sub ? '<div style="text-align:center"><span class="p-sub">' + esc(D.sub) + '</span></div>' : '') +
      (D.badge ? '<div class="p-badge">' + esc(D.badge) + '</div>' : '') + '</div>' +
      '<div class="p-body"><div class="p-grid' + (n >= 5 ? ' n3' : '') + '" style="grid-template-rows:' + (n <= 2 ? '1fr' : '1fr 1fr') + '">' + cells + '</div>' +
      '<div class="p-side">' + side + '</div></div>' +
      '<div class="p-price"><div class="p-pbox"><div class="p-only">' + esc(D.only) + '</div><div class="p-amt">' + money(D.price) + '</div></div>' +
      (D.note ? '<div class="p-note">' + esc(D.note) + '</div>' : '') + '</div>' +
      '<div class="p-foot">' + ico('phone') + '<i>' + esc(D.phoneLabel) + '</i><b>' + esc(D.phone) + '</b></div>';
  }

  /* ---------- T2 · Yesil kare ---------- */
  function t2(D) {
    var feats = D.feat.slice(0, 6).map(function (f) {
      return '<div class="t2f"><div class="t2circ">' + ico(icoFor(f)) + '</div><span>' + esc(f) + '</span></div>';
    }).join('');
    var strip = D.photos.slice(1, 5), s = '';
    if (!strip.length) strip = [null, null, null, null];
    strip.forEach(function (u) { s += '<div class="ph"' + bg(u) + '></div>'; });
    var seal = '';
    if (D.seal) {
      var parts = String(D.seal).split('|');
      seal = '<div class="t2seal">' + ico(icoFor(parts[0])) + '<b>' + esc(parts[0].trim()) + '</b>' +
        (parts[1] ? '<i>' + esc(parts[1].trim()) + '</i>' : '') + '</div>';
    }
    var noteLines = String(D.note || '').split(/\s*\|\s*|\n/).filter(Boolean);
    return logoTag(D) + seal +
      '<div class="t2top"><div class="t2hero ph"' + bg(D.photos[0]) + '></div><div class="t2fade"></div>' +
      '<div class="t2info">' +
      '<div class="t2kick">' + ico('pin') + esc(D.kick) + '</div>' +
      '<div class="t2title">' + esc(D.title) + '</div>' +
      (D.sub ? '<div class="t2sub scr">' + esc(D.sub) + '</div><div class="t2rule"></div>' : '') +
      '<div class="t2feats">' + feats + '</div></div>' +
      (D.slogan ? '<div class="t2slogan"><span class="scr">' + esc(D.slogan) + '</span></div>' : '') +
      '</div>' +
      '<div class="t2strip" style="--n:' + strip.length + '">' + s + '</div>' +
      '<div class="t2foot"><div class="t2fl">' + ico('pin') +
      '<div><b>' + esc(noteLines[0] || D.kick) + '</b>' + (noteLines[1] ? '<i>' + esc(noteLines[1]) + '</i>' : '') + '</div></div>' +
      (D.badge ? '<div class="t2fl" style="flex:0 0 auto">' + ico('build') + '<div><i>' + esc(D.badge) + '</i></div></div>' : '') +
      '<div class="t2phone"><em>' + esc(D.phoneLabel) + '</em><b>' + esc(D.phone) + '</b></div></div>';
  }

  /* ---------- T3 · Koyu krem-gold dikey ---------- */
  function t3(D) {
    var caps = D.caps, cards = '';
    var pool = D.photos.slice(1, 4);
    if (!pool.length) pool = [null, null, null];
    pool.forEach(function (u, i) {
      cards += '<div class="t3card"><div class="ph"' + bg(u) + '></div>' + (caps[i] ? '<b>' + esc(caps[i]) + '</b>' : '') + '</div>';
    });
    var fs = D.feat.slice(0, 5).map(function (f) {
      return '<div class="t3fi">' + ico(icoFor(f)) + '<span>' + esc(f) + '</span></div>';
    }).join('');
    var seal = D.seal ? '<div class="t3seal"><span>' + esc(String(D.seal).replace(/\|/g, ' ')) + '</span></div>' : '';
    return logoTag(D) +
      '<div class="t3top"><div class="t3hero ph"' + bg(D.photos[0]) + '></div>' +
      '<div class="t3info"><div class="t3kick scr">' + esc(D.kick) + '</div>' +
      '<div class="t3title">' + esc(D.title) + '</div>' +
      (D.badge ? '<div class="t3pill">' + esc(D.badge) + '</div>' : '') +
      (D.sub ? '<div class="t3scr scr">' + esc(D.sub) + '</div>' : '') +
      (D.price ? '<div class="t3txt">' + esc(D.price) + (D.note ? ' · ' + esc(D.note) : '') + '</div>' : '') +
      seal + '</div></div>' +
      (D.slogan ? '<div class="t3band scr">' + esc(D.slogan) + '</div>' : '') +
      '<div class="t3cards" style="--n:' + pool.length + '">' + cards + '</div>' +
      '<div class="t3feats" style="--nf:' + Math.max(1, Math.min(5, D.feat.length)) + '">' + fs + '</div>' +
      '<div class="t3foot"><div class="t3fbox">' + ico('phone') + '<div><i>' + esc(D.phoneLabel) + '</i><b>' + esc(D.phone) + '</b></div></div>' +
      '<div class="t3loc">' + ico('pin') + '<em>Konum</em><b>' + esc(String(D.note || D.kick).split(/\s*\|\s*|\n/)[0]) + '</b>' +
      '<span class="scr">' + esc(D.slogan || '') + '</span></div></div>';
  }

  /* ---------- T4 · Lacivert-gold kare ---------- */
  function t4(D) {
    var feats = D.feat.slice(0, 6).map(function (f) {
      return '<div class="t4f">' + ico(icoFor(f)) + '<span>' + esc(f) + '</span></div>';
    }).join('');
    var pool = D.photos.slice(1, 4); if (!pool.length) pool = [null, null, null];
    var cards = '';
    pool.forEach(function (u, i) {
      cards += '<div class="t4card"><div class="ph"' + bg(u) + '></div>' + (D.caps[i] ? '<b>' + esc(D.caps[i]) + '</b>' : '') + '</div>';
    });
    var loc = String(D.note || D.kick).split(/\s*\|\s*|\n/).filter(Boolean);
    return logoTag(D) +
      '<div class="t4top"><div class="t4hero ph"' + bg(D.photos[0]) + '></div>' +
      '<div class="t4info"><div class="t4kick">' + esc(D.kick) + '</div>' +
      (D.sub ? '<div class="t4scr scr">' + esc(D.sub) + '</div>' : '') +
      '<div class="t4title">' + esc(D.title) + '</div>' +
      (D.badge ? '<div class="t4pill">' + esc(D.badge) + '</div>' : '') +
      '<div class="t4feats">' + feats + '</div>' +
      (D.slogan ? '<div class="t4slogan"><span class="scr">' + esc(D.slogan) + '</span></div>' : '') +
      '</div>' +
      '<div class="t4loc">' + ico('pin') + '<i>' + esc(loc[0] || '') + '</i>' + (loc[1] ? '<b>' + esc(loc[1]) + '</b>' : '') + '</div></div>' +
      '<div class="t4cards" style="--n:' + pool.length + '">' + cards + '</div>' +
      '<div class="t4foot"><div class="t4fl">' + ico('phone') + '<div><i>' + esc(D.phoneLabel) + '</i><b>' + esc(D.phone) + '</b></div></div>' +
      (D.seal ? '<div class="t4fr scr">' + esc(String(D.seal).replace(/\|/g, ' · ')) + '</div>' : '') + '</div>';
  }

  window.POSTER_TPL = [
    { id: 'tpl2', n: 'Kare · Yeşil vitrin', pal: { dark: '#3c4a1e', acc: '#8a7a2c', pop: '#5b6b28' }, size: '1080x1080' },
    { id: 'tpl4', n: 'Kare · Lacivert-gold', pal: { dark: '#16233f', acc: '#c19a4b', pop: '#c19a4b' }, size: '1080x1080' },
    { id: 'tpl3', n: 'Dikey · Koyu krem-gold', pal: { dark: '#241d17', acc: '#c9a052', pop: '#8a6b32' }, size: '1000x1500' },
    { id: 'tpl1', n: 'Dikey · Klasik sarı', pal: { dark: '#12275c', acc: '#ffd400', pop: '#e11d2e' }, size: '1000x1500' }
  ];
  window.POSTER_BUILD = { tpl1: t1, tpl2: t2, tpl3: t3, tpl4: t4 };
  window.POSTER_ICO = { SVG: SVG, icoFor: icoFor };
})();
