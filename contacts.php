<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** LUNAHAN — rehber */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
$L = atlas_lang();
$U = require_login();
$JS = [];
foreach (['js_noaction','js_norem','js_nocontact','js_connerr'] as $k) $JS[substr($k,3)] = t($k);
atlas_header(t('app_contacts'), 'contacts',
  $L === 'tr' ? 'LUNAHAN’ın tanıdığı kişiler; sohbette “… kişisini ekle” diyerek çoğaltabilirsin.' : 'People LUNAHAN knows — add more by asking in chat.');
?>
<section class="acard">
  <h3><?= $L === 'tr' ? 'WhatsApp rehberi' : 'WhatsApp contacts' ?></h3>
  <p class="muted"><?= $L === 'tr'
     ? 'Bağlı WhatsApp hattındaki kişileri tek tıkla LUNAHAN rehberine aktar; sonra “Ahmet’e mesaj at” demen yeter, numara yazmana gerek kalmaz.'
     : 'Import the contacts of your connected WhatsApp line into LUNAHAN.' ?></p>
  <button id="wa-import" class="btn"><?= $L === 'tr' ? 'WhatsApp rehberini içe aktar' : 'Import WhatsApp contacts' ?></button>
  <span id="wa-import-msg" class="muted" style="margin-left:10px"></span>
</section>
<section class="acard"><h3><?= t('app_contacts') ?></h3><div id="contacts" class="list"></div></section>
<script>const ATLAS_LANG = <?= json_encode($L) ?>; const T = <?= json_encode($JS, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
<script>
document.getElementById('wa-import').addEventListener('click', async function () {
  const b = this, m = document.getElementById('wa-import-msg');
  b.disabled = true; m.textContent = 'Aktarılıyor…';
  try {
    const r = await fetch('api/wa.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=import_contacts'});
    const j = await r.json();
    if (j.ok) {
      m.textContent = j.found + ' kişi bulundu · ' + j.added + ' eklendi · ' + j.skipped + ' zaten vardı';
      if (j.found === 0) m.textContent = 'Köprüde kayıtlı kişi yok — WhatsApp sayfasından hattı yeniden bağlarsan (QR) rehber senkronlanır.';
      setTimeout(() => location.reload(), 1200);
    } else { m.textContent = 'Hata: ' + (j.error || 'bilinmiyor'); }
  } catch (e) { m.textContent = 'Bağlantı hatası'; }
  b.disabled = false;
});
</script>
<?php atlas_footer(); ?>
