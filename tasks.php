<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — onay kuyrugu + hatirlaticilar */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
$L = atlas_lang();
$U = require_login();
$JS = [];
foreach (['js_noaction','js_norem','js_nocontact','js_approve','js_reject','js_call','js_connerr'] as $k) $JS[substr($k,3)] = t($k);
atlas_header($L === 'tr' ? 'Görevler' : 'Tasks', 'tasks',
  $L === 'tr' ? 'Onayını bekleyen işler ve kurulmuş hatırlatıcılar.' : 'Actions waiting for your approval and your reminders.');
?>
<div class="grid2">
  <section class="acard"><h3><?= t('app_queue') ?></h3><div id="actions" class="list"></div></section>
  <section class="acard"><h3><?= t('app_rem') ?></h3><div id="reminders" class="list"></div></section>
</div>
<script>const ATLAS_LANG = <?= json_encode($L) ?>; const T = <?= json_encode($JS, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js?v=<?= @filemtime(__DIR__ . '/assets/app.js') ?: time() ?>"></script>
<?php atlas_footer(); ?>
