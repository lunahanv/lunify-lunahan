/*
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */
const feed = document.getElementById('feed');
const form = document.getElementById('cmdForm');
const cmd = document.getElementById('cmd');

function mdToHtml(s){
  s = (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
  s = s.replace(/^###\s*(.+)$/gm,'<b class="h">$1</b>');
  s = s.replace(/^##\s*(.+)$/gm,'<b class="h">$1</b>');
  s = s.replace(/^#\s*(.+)$/gm,'<b class="h">$1</b>');
  s = s.replace(/^\s*[-*]\s+/gm,'• ');
  s = s.replace(/\*\*([^*]+)\*\*/g,'<b>$1</b>');
  s = s.replace(/(^|[^*])\*([^*\n]+)\*/g,'$1<i>$2</i>');
  s = s.replace(/`([^`]+)`/g,'<code>$1</code>');
  const safeHref = u => u.replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g,(_,label,url)=>'<a href="'+safeHref(url)+'" target="_blank" rel="noopener">'+label+'</a>');
  s = s.replace(/(^|\s)(https?:\/\/[^\s<]+)/g,(_,prefix,url)=>prefix+'<a href="'+safeHref(url)+'" target="_blank" rel="noopener">'+url+'</a>');
  s = s.replace(/^---+$/gm,'<hr>');
  return s;
}

function bubble(text, cls, tools){
  if(!feed) return null;
  const d = document.createElement('div');
  d.className = 'msg ' + cls;
  if(cls==='bot') d.innerHTML = mdToHtml(text); else d.textContent = text;
  if (tools && tools.length){
    const t = document.createElement('div'); t.className='tools';
    t.textContent = '🔧 ' + tools.map(x=>x.tool).join(', ');
    d.appendChild(t);
  }
  feed.appendChild(d); feed.scrollTop = feed.scrollHeight;
  return d;
}


/* --- Dosya ekleme (Viktor 2026-09-09): panelden PDF/foto/ses yukleme --- */
let LH_PENDING = [];   // {id, filename, kind}
const attachBtn = document.getElementById('attach');
const fileInput = document.getElementById('fileInput');
const attachHint = document.getElementById('attachHint');

function lhRenderPending(){
  if(!attachHint) return;
  if(!LH_PENDING.length){ attachHint.textContent=''; return; }
  attachHint.textContent = '📎 ' + LH_PENDING.map(f=>f.filename).join(', ')
    + (ATLAS_LANG==='tr' ? ' — mesajınla birlikte gönderilecek' : ' — will be sent with your message');
}

if(attachBtn && fileInput){
  attachBtn.addEventListener('click', ()=> fileInput.click());
  fileInput.addEventListener('change', async ()=>{
    if(!fileInput.files.length) return;
    const fd = new FormData();
    for(const f of fileInput.files) fd.append('files[]', f);
    fd.append('conv', ATLAS_CONV || 0);
    if(attachHint) attachHint.textContent = (ATLAS_LANG==='tr'?'Dosya okunuyor…':'Reading file…');
    try{
      const r = await fetch('api/upload.php',{method:'POST',body:fd});
      if(r.status===401){ location.href='login.php'; return; }
      const j = await r.json();
      if(j.error){ if(attachHint) attachHint.textContent = '⚠️ ' + j.error; }
      else {
        (j.files||[]).forEach(f=>LH_PENDING.push(f));
        lhRenderPending();
        (j.errors||[]).forEach(e=>bubble('⚠️ '+e,'bot'));
        const bad = (j.files||[]).filter(f=>f.status!=='ok').map(f=>f.filename);
        if(bad.length) bubble('⚠️ ' + bad.join(', ') + (ATLAS_LANG==='tr'
          ? ' dosyasının içeriği okunamadı; yine de gönderebilirsin.'
          : ' could not be read; you can still send it.'),'bot');
      }
    }catch(e){ if(attachHint) attachHint.textContent = '⚠️ ' + (T.connerr||'bağlantı hatası'); }
    fileInput.value='';
  });
}

if(form) form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  const text = cmd.value.trim();
  if(!text && !LH_PENDING.length) return;
  const fids = LH_PENDING.map(f=>f.id);
  const label = LH_PENDING.length ? (text ? text + '\n' : '') + '📎 ' + LH_PENDING.map(f=>f.filename).join(', ') : text;
  bubble(label,'me'); cmd.value=''; if(window.lhMicReset) window.lhMicReset(); LH_PENDING=[]; lhRenderPending();
  const wait = bubble('…','bot');
  try{
    const r = await fetch('api/command.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'text='+encodeURIComponent(text)+'&conv='+ATLAS_CONV+(fids.length?'&file_ids='+fids.join(','):'')});
    if(r.status===401){ location.href='login.php'; return; }
    const j = await r.json();
    wait.remove();
    if(j.error && j.upgrade){ bubble(j.error + ' → <a href="'+j.upgrade+'">'+(T.plans||'Paketler')+'</a>','bot'); }
    else bubble(j.reply || j.error || T.noreply,'bot', j.tools);
    if(j.conv){ ATLAS_CONV = j.conv; loadConvs(); }
    loadState();
  }catch(err){ wait.remove(); bubble(T.connerr,'bot'); }
});

function esc(s){return (s||'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));}

async function loadState(){
  try{
    const sr = await fetch('api/state.php');
    if(sr.status===401){ location.href='login.php'; return; }
    const j = await sr.json();
    // actions
    const ab = document.getElementById('actions');
    if(ab){
    ab.innerHTML = j.actions.filter(a=>a.status!=='rejected').length? '' : '<div class="empty">'+T.noaction+'</div>';
    j.actions.forEach(a=>{
      if(a.status==='rejected') return;
      const meta = a.meta_json? JSON.parse(a.meta_json):{};
      const el = document.createElement('div'); el.className='item';
      let inner = `<div class="t">${a.type} <span class="badge ${a.status}">${a.status}</span></div>`;
      inner += `<div class="s">${esc(a.target||meta.name||'')}</div>`;
      if(a.payload) inner += `<div style="margin-top:4px">${esc(a.payload.slice(0,140))}</div>`;
      if(a.status==='pending'){
        inner += `<div class="row"><button class="btn-sm btn-ok" onclick="decide(${a.id},'approve')">${T.approve}</button>
          <button class="btn-sm btn-no" onclick="decide(${a.id},'reject')">${T.reject}</button></div>`;
      }
      if(a.type==='call' && a.status==='sent' && a.result && a.result.startsWith('tel:')){
        inner += `<div class="row"><a class="btn-sm btn-call" href="${a.result}">${T.call}</a></div>`;
      } else if(a.result && a.status!=='pending'){
        inner += `<div class="s" style="margin-top:6px">${esc(a.result.slice(0,120))}</div>`;
      }
      el.innerHTML = inner; ab.appendChild(el);
    });
    }
    const np = document.getElementById('navPending');
    if(np){ const n = j.actions.filter(a=>a.status==='pending').length;
      if(n){ np.textContent = n; np.classList.remove('hidden'); } else { np.classList.add('hidden'); } }
    // reminders
    const rb = document.getElementById('reminders');
    if(rb){
    rb.innerHTML = j.reminders.length? '' : '<div class="empty">'+T.norem+'</div>';
    j.reminders.forEach(r=>{
      const el=document.createElement('div');el.className='item';
      el.innerHTML=`<div>${esc(r.body)}</div><div class="s">${esc(r.when_text||'')}</div>`;rb.appendChild(el);
    });
    }
    // contacts
    const cb = document.getElementById('contacts');
    if(cb){
    cb.innerHTML = j.contacts.length? '' : '<div class="empty">'+T.nocontact+'</div>';
    j.contacts.forEach(c=>{
      const el=document.createElement('div');el.className='item';
      el.innerHTML=`<div class="t" style="text-transform:none">${esc(c.name)}</div><div class="s">${esc(c.phone||'')} ${esc(c.email||'')}</div>`;cb.appendChild(el);
    });
    }
  }catch(e){}
}

async function decide(id,decision){
  await fetch('api/approve.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`id=${id}&decision=${decision}`});
  loadState();
}

let ATLAS_CONV = 0;

function convTitle(t){ return (t||'').length > 34 ? t.slice(0,34)+'…' : (t||''); }

async function loadConvs(){
  const box = document.getElementById('convList');
  if(!box) return;
  try{
    const r = await fetch('api/conversations.php?action=list');
    if(r.status===401){ location.href='login.php'; return; }
    const j = await r.json();
    const items = j.items || [];
    box.innerHTML = items.length ? '' : '<div class="empty">'+(T.conv_none||'')+'</div>';
    items.forEach(c=>{
      const el = document.createElement('div');
      el.className = 'conv-item' + (Number(c.id)===Number(ATLAS_CONV) ? ' on' : '');
      el.innerHTML = '<span class="ct">'+esc(convTitle(c.title))+'</span>'
        + '<span class="cx"><button class="ci" title="ad değiştir" data-a="ren">✎</button>'
        + '<button class="ci" title="sil" data-a="del">🗑</button></span>';
      el.querySelector('.ct').onclick = ()=>openConv(c.id);
      el.querySelector('[data-a=ren]').onclick = async (e)=>{
        e.stopPropagation();
        const t = prompt(T.conv_ren||'', c.title); if(!t) return;
        await fetch('api/conversations.php?action=rename',{method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},
          body:'id='+c.id+'&title='+encodeURIComponent(t)});
        loadConvs();
      };
      el.querySelector('[data-a=del]').onclick = async (e)=>{
        e.stopPropagation();
        if(!confirm(T.conv_del||'?')) return;
        await fetch('api/conversations.php?action=delete',{method:'POST',
          headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'id='+c.id});
        if(Number(c.id)===Number(ATLAS_CONV)) newConv(true);
        loadConvs();
      };
      box.appendChild(el);
    });
  }catch(e){}
}

async function openConv(id){
  if(!feed) return;
  ATLAS_CONV = Number(id);
  try{ sessionStorage.setItem('atlasConv', ATLAS_CONV); }catch(e){}
  feed.innerHTML = '';
  try{
    const j = await (await fetch('api/conversations.php?action=messages&id='+ATLAS_CONV)).json();
    (j.items||[]).forEach(c=>{ bubble(c.user_text,'me'); if(c.agent_reply) bubble(c.agent_reply,'bot'); });
    if(!(j.items||[]).length) bubble(T.greet,'bot');
  }catch(e){}
  loadConvs();
}

function newConv(silent){
  ATLAS_CONV = 0;
  try{ sessionStorage.removeItem('atlasConv'); localStorage.removeItem('atlasConv'); }catch(e){}
  if(cmd) cmd.value='';
  if(window.lhMicReset) window.lhMicReset();
  if(typeof LH_PENDING!=='undefined'){ LH_PENDING=[]; if(window.lhRenderPending) lhRenderPending(); }
  if(feed){ feed.innerHTML=''; bubble(T.greet,'bot'); }
  if(!silent) loadConvs();
}

async function initChat(){
  if(!feed) return;
  const btn = document.getElementById('convNew');
  if(btn) btn.onclick = ()=>newConv();
  let items = [];
  try{ items = (await (await fetch('api/conversations.php?action=list')).json()).items || []; }catch(e){}
  /* Viktor 2026-09-10: her yeni giris/sekme BOS sohbetle baslar; eski sohbet
     sadece ayni sekmede yenilenince veya listeden secilince devam eder. */
  let want = 0;
  try{ want = Number(sessionStorage.getItem('atlasConv')||0); }catch(e){}
  if(want && !items.some(c=>Number(c.id)===want)) want = 0;
  if(want) await openConv(want); else { newConv(); }
}
initChat();
loadState();
setInterval(loadState, 8000);
/* ---------- Sesli komut (Web Speech API) ---------- */
(function(){
  const mic  = document.getElementById('mic');
  const hint = document.getElementById('micHint');
  if(!mic) return;
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if(!SR){
    mic.style.display='none';
    hint.textContent = T.mic_no;
    return;
  }
  const rec = new SR();
  rec.lang = (typeof ATLAS_LANG!=='undefined' && ATLAS_LANG==='en') ? 'en-US' : 'tr-TR';
  rec.interimResults = true;
  rec.continuous = true;          // sessizlikte kesme, dinlemeye devam et
  let wantListen = false;         // kullanıcı dinlemeyi açık tutmak istiyor mu
  let baseText = '';              // mikrofon açılmadan önce kutuda yazan metin
  let finalText = '';             // bu oturumda kesinleşen konuşma
  let suppressUntil = 0;          // bu zamana kadar gelen dikte sonuclari yutulur

  function startRec(){
    baseText = cmd.value.trim();
    finalText = '';
    try{ rec.start(); }catch(e){}
  }
  function suppressing(){ return Date.now() < suppressUntil; }

  mic.addEventListener('click', ()=>{
    if(wantListen){                       // kapat
      wantListen = false;
      try{ rec.stop(); }catch(e){}
      return;
    }
    wantListen = true;
    startRec();
  });

  rec.onstart = ()=>{
    mic.classList.add('rec');
    hint.textContent = T.mic_on;
  };

  rec.onerror = (e)=>{
    if(e.error==='no-speech' || e.error==='aborted') return;   // sessizlik normal, devam
    wantListen = false; mic.classList.remove('rec');
    hint.textContent = (e.error==='not-allowed' || e.error==='service-not-allowed')
      ? T.mic_deny
      : T.mic_err;
  };

  rec.onresult = (ev)=>{
    if(suppressing()){ if(cmd) cmd.value=''; baseText=''; finalText=''; return; }  // gonderim sonrasi gec sonuclari yut
    let interim='';
    for(let i=ev.resultIndex;i<ev.results.length;i++){
      const t = ev.results[i][0].transcript;
      if(ev.results[i].isFinal) finalText += t + ' '; else interim += t;
    }
    cmd.value = (baseText ? baseText + ' ' : '') + (finalText + interim).trim();
  };

  // Tarayıcı sessizlikte kendiliğinden durdurursa, kullanıcı kapatmadıysa devam ettir
  rec.onend = ()=>{
    if(wantListen){
      baseText = suppressing() ? '' : cmd.value.trim();
      finalText = '';
      if(suppressing() && cmd) cmd.value='';
      try{ rec.start(); }catch(e){ wantListen=false; mic.classList.remove('rec'); }
      return;
    }
    mic.classList.remove('rec');
    hint.textContent = cmd.value.trim()
      ? T.mic_ready
      : T.mic_none;
  };

  /* Viktor 2026-09-09: gonderdikten sonra dikte tamponunu da sifirla.
     Aksi halde mikrofon acik kalirsa onresult eski metni geri yaziyordu. */
  window.lhMicReset = function(){
    baseText = ''; finalText = '';
    suppressUntil = Date.now() + 1500;          // 1.5 sn boyunca gec sonuclari yut
    if(cmd) cmd.value = '';
    if(wantListen){
      try{ rec.abort(); }catch(e){ try{ rec.stop(); }catch(e2){} }   // onend yeniden baslatir
      [80,250,600,1000,1400].forEach(ms=>setTimeout(()=>{
        if(suppressing() && cmd && cmd.value.trim()){ cmd.value=''; baseText=''; finalText=''; }
      }, ms));
    }
  };

  // Gönderdikten sonra ipucunu temizle
  form.addEventListener('submit', ()=>{ if(!wantListen) hint.textContent=''; });
})();

/* ---------- WhatsApp durumu / QR ---------- */
async function loadWa(){
  const box = document.getElementById('wa');
  if(!box) return;
  try{
    const j = await (await fetch('api/wa.php')).json();
    const st = j.status || 'offline';
    if(st === 'connected'){
      box.innerHTML = `<div class="wa-row"><span class="dot ok"></span>
        <span class="wa-state">${esc(T.wa_conn)}</span></div>
        <div class="wa-num">${esc(T.wa_num)}: +${esc(j.number||'')}</div>
        <button class="wa-btn" id="waOut">${esc(T.wa_logout)}</button>`;
      const b = document.getElementById('waOut');
      if(b) b.onclick = async () => {
        if(!confirm(T.wa_confirm)) return;
        b.disabled = true;
        await fetch('api/wa.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=logout'});
        setTimeout(loadWa, 2500);
      };
    } else if(st === 'qr' && j.qr){
      box.innerHTML = `<div class="wa-row"><span class="dot warn"></span>
        <span class="wa-state">${esc(T.wa_qr)}</span></div>
        <img class="qr" src="${j.qr}" alt="WhatsApp QR">
        <div class="wa-help">${esc(T.wa_scan)}</div>`;
    } else if(st === 'starting' || st === 'reconnecting' || st === 'connecting'){
      box.innerHTML = `<div class="wa-row"><span class="dot warn"></span>
        <span class="wa-state">${esc(T.wa_wait)}</span></div>`;
    } else {
      box.innerHTML = `<div class="wa-row"><span class="dot err"></span>
        <span class="wa-state">${esc(T.wa_off)}</span></div>`;
    }
  }catch(e){}
}
loadWa();
setInterval(loadWa, 12000);
