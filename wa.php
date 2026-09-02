<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// Atlas — WhatsApp bağlantı sayfası (kullanıcıya özel QR ile kendi hattını bağlar)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
require_login();

function wa_call(string $path, array $post = null) {
  if (!WA_BRIDGE_URL) return ['error' => 'köprü ayarlı değil'];
  // Çok oturumlu köprü: her istek kullanıcı kimliğiyle (uid) gider.
  $uid = atlas_uid();
  $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . $path . (strpos($path, '?') === false ? '?' : '&')
    . 'key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . $uid);
  $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Bridge-Key: ' . WA_BRIDGE_KEY]];
  if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($post + ['key' => WA_BRIDGE_KEY, 'uid' => $uid]); }
  curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true) ?: ['error' => 'yanıt yok'];
}

if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');
  if ($_GET['api'] === 'status') { echo json_encode(wa_call('/status?init=1')); exit; }
  if ($_GET['api'] === 'logout') { echo json_encode(wa_call('/logout', [])); exit; }
  if ($_GET['api'] === 'pair') {
    $ph = preg_replace('/[^0-9]/', '', (string)($_GET['phone'] ?? ''));
    if (strlen($ph) < 10) { echo json_encode(['error' => 'Numarayi ulke koduyla yaz, orn. 905321112233']); exit; }
    echo json_encode(wa_call('/pair', ['phone' => $ph])); exit;
  }
  echo json_encode(['error' => 'bilinmeyen']); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_owner'])) {
  set_setting('wa_owner', ''); set_setting('wa_owner_name', '');
  header('Location: wa.php?ok=1'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_owner'])) {
  $nums = preg_split('/[,;\s]+/', (string)($_POST['owner_numbers'] ?? ''));
  $clean = [];
  foreach ($nums as $n) { $n = preg_replace('/[^0-9]/', '', (string)$n); if ($n !== '') $clean[] = $n; }
  set_setting('wa_owner', implode(',', array_unique($clean)));
  header('Location: wa.php?ok=1'); exit;
}
$waOwner = (string)setting('wa_owner', '');
$waOwnerName = (string)setting('wa_owner_name', '');
atlas_header('WhatsApp', 'wa', 'Atlas\'ı WhatsApp\'tan kullanmak için numaranı QR ile bağla.');
?>
<style>
.atlas-page #qr img{width:260px;height:260px;display:block;margin:10px auto;border-radius:12px}
.atlas-page .st{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600}
.atlas-page .st.ok{background:#e6f7ec;color:#0f7a3d}.atlas-page .st.wait{background:#fff3e0;color:#a35b00}
.atlas-page .sub{color:#6b7280;font-size:13px;margin-bottom:14px}
.atlas-page .steps{font-size:14px;line-height:1.6}
.atlas-page input[type=text]{width:100%;padding:10px 12px;border-radius:10px;margin:8px 0}
</style>

<div class="acard">
  <h3 style="text-transform:none;font-size:16px;color:inherit">WhatsApp bağlantısı</h3>
  <div class="sub">Atlas'ı WhatsApp'tan kullanmak için numaranı bağla.</div>
  <div id="stat"><span class="st wait">yükleniyor…</span></div>
  <div id="qr"></div>
  <div class="steps" id="steps">
    <b>Nasıl bağlanır:</b><br>
    1. Bağlayacağın telefonda WhatsApp'ı aç<br>
    2. <b>Ayarlar → Bağlı cihazlar → Cihaz bağla</b><br>
    3. Yukarıdaki kareyi okut
  </div>
  <div id="acts"></div>
  <hr style="border:0;border-top:1px solid #eef0f7;margin:18px 0">
  <div class="sub">
    <b>Atlas'a kim yazabilir?</b><br>
    1) <b>Bu telefonun kendi numarası</b> — yani QR'ı okuttuğun hat (&quot;Kendine mesaj&quot; sohbeti). Her zaman serbest.<br>
    2) Aşağıya eklediğin numaralar.<br>
    Başka biri yazarsa Atlas <b>cevap vermez</b>, mesajı sessizce yok sayar.
    <span style="color:#0f7a3d">Otomatik &quot;ilk yazan sahip olur&quot; kuralı yok.</span>
  </div>
  <form method="post" style="margin-top:8px">
    <label style="font-size:13px;font-weight:600">İzinli numaralar (virgülle ayır, ülke koduyla)</label>
    <input type="text" name="owner_numbers" placeholder="905462411118, 447727820350"
           value="<?= htmlspecialchars($waOwner) ?>">
    <button type="submit" name="save_owner" value="1">Kaydet</button>
    <?php if ($waOwner): ?>
      <button type="submit" name="reset_owner" value="1"
              style="background:#f1f2f6;color:#333">Listeyi temizle</button>
    <?php endif; ?>
  </form>
  <div class="sub" style="margin-top:6px">
    Şu an izinli: <b><?= $waOwner ? htmlspecialchars($waOwner) : 'sadece hattın kendi numarası' ?></b>
  </div>
  <hr style="border:0;border-top:1px solid #eef0f7;margin:18px 0">
  <div id="pairbox">
    <h3 style="text-transform:none;font-size:15px;color:inherit;margin-bottom:2px">QR okutmak istemiyorsan: numarayla bağlan</h3>
    <div class="sub">Kamera, kare okutma yok. Numaranı yaz, Atlas 8 haneli bir kod verir; o kodu telefonundaki
      WhatsApp'a girersin ve bağlanır.</div>
    <input type="text" id="pphone" placeholder="905321112233 (ülke koduyla, başında + yok)">
    <button type="button" id="pbtn">Bağlantı kodu al</button>
    <div id="pout" style="margin-top:10px"></div>
  </div>

</div>
<script>
async function tick(){
  try{
    const j = await (await fetch('wa.php?api=status')).json();
    const s = document.getElementById('stat'), q = document.getElementById('qr'), a = document.getElementById('acts');
    if(j.status==='connected'){
      s.innerHTML='<span class="st ok">✓ Bağlı'+(j.number?' · +'+j.number:'')+'</span>';
      q.innerHTML=''; document.getElementById('steps').style.display='none';
      var pb=document.getElementById('pairbox'); if(pb) pb.style.display='none';
      a.innerHTML='<button onclick="logout()">Bağlantıyı kes</button>';
    } else if(j.status==='qr' && j.qr){
      s.innerHTML='<span class="st wait">QR bekleniyor — okut</span>';
      if(!q.dataset.q || q.dataset.q!==j.qr){ q.dataset.q=j.qr; q.innerHTML='<img src="'+j.qr+'">'; }
      document.getElementById('steps').style.display='';
      a.innerHTML='';
    } else {
      s.innerHTML='<span class="st wait">'+(j.status||j.error||'hazırlanıyor')+'…</span>'; q.innerHTML='';
    }
  }catch(e){}
}
async function logout(){ if(!confirm('WhatsApp bağlantısı kesilsin mi?'))return; await fetch('wa.php?api=logout'); setTimeout(tick,1500); }

const pbtn = document.getElementById('pbtn');
if (pbtn) pbtn.addEventListener('click', async function(){
  const ph = (document.getElementById('pphone').value || '').replace(/[^0-9]/g,'');
  const out = document.getElementById('pout');
  if (ph.length < 10) { out.innerHTML = '<span style="color:#b00">Numarayı ülke koduyla yaz: 905321112233</span>'; return; }
  pbtn.disabled = true; out.textContent = 'Kod hazırlanıyor…';
  try {
    const j = await (await fetch('wa.php?api=pair&phone=' + ph)).json();
    if (j.code) {
      out.innerHTML = '<div style="font-size:34px;font-weight:700;letter-spacing:6px;margin:6px 0">'
        + String(j.code).replace(/(.{4})(.{4})/, '$1-$2') + '</div>'
        + '<div style="font-size:14px;line-height:1.7">Şimdi o telefonda: <b>WhatsApp → Ayarlar → Bağlı cihazlar →'
        + ' Cihaz bağla</b> → alttaki <b>“Telefon numarasıyla bağlan”</b> → bu kodu gir.<br>'
        + '<span style="color:#6b7280">Kod birkaç dakika geçerli. Süre geçerse yeni kod al.</span></div>';
    } else {
      out.innerHTML = '<span style="color:#b00">' + (j.error || 'kod alınamadı') + '</span>';
    }
  } catch(e) { out.innerHTML = '<span style="color:#b00">bağlantı hatası</span>'; }
  pbtn.disabled = false;
});

tick(); setInterval(tick, 3000);
</script>
<?php atlas_footer(); ?>
