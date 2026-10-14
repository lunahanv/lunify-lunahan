<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// LUNAHAN — Google OAuth callback (temiz yol; Google Console query string kabul etmiyor)
/* Anahtar icin OAuth koprusu: state=axg ise kodu Anahtar'in Gmail callback'ine devret. */
if (($_GET['state'] ?? '') === 'axg') {
  $q = http_build_query(array_intersect_key($_GET, array_flip(['code','scope','authuser','prompt','error'])));
  header('Location: https://anahtar.lunify.world/gmail-callback.php?' . $q); exit;
}
$_GET['oauth'] = 'cb';
require __DIR__ . '/gmail.php';
