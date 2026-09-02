<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — sohbet ekrani (sadece sohbet; ayarlar ayri sayfalarda) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();

$L = atlas_lang();

if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: index.php?lang=' . $L); exit; }

$U = current_user();
if (!$U || $U['status'] !== 'active') { header('Location: login.php?lang=' . $L); exit; }

require_once __DIR__ . '/includes/onboarding.php';
if (!ob_was_seen() && !isset($_GET['nostart'])) { header('Location: getting-started.php?lang=' . $L); exit; }

// JS'e aktarilacak metinler
$JS = [];
foreach (['js_greet','js_noaction','js_norem','js_nocontact','js_approve','js_reject','js_call',
          'js_noreply','js_connerr','js_mic_no','js_mic_on','js_mic_deny','js_mic_err',
          'js_mic_ready','js_mic_none','js_wa_conn','js_wa_qr','js_wa_off','js_wa_scan','js_wa_wait',
          'js_wa_logout','js_wa_confirm','js_wa_num'] as $k) {
  $JS[substr($k, 3)] = t($k);
}

atlas_header($L === 'tr' ? 'Sohbet' : 'Chat', 'chat',
  $L === 'tr' ? 'Atlas asistanınız — normal cümlelerle iş yaptırın.' : 'Your Atlas assistant — get things done in plain language.', true);
?>
<div class="chat-wrap">
<aside class="conv-col">
  <button type="button" id="convNew" class="conv-new">+ <?= $L === 'tr' ? 'Yeni sohbet' : 'New chat' ?></button>
  <div id="convList" class="conv-list"></div>
</aside>
<section class="chat-shell">
  <div id="feed" class="feed"></div>
  <form id="cmdForm" class="composer">
    <input id="cmd" placeholder="<?= t('app_ph') ?>" autocomplete="off">
    <button type="button" id="mic" class="mic" title="<?= t('app_mic') ?>">🎤</button>
    <button id="send"><?= t('app_send') ?></button>
  </form>
  <div class="hint" id="micHint"></div>
</section>
</div>
<script>
const ATLAS_LANG = <?= json_encode($L) ?>;
const T = <?= json_encode($JS, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
<?php atlas_footer(); ?>
