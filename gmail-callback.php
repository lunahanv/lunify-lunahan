<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// Atlas — Google OAuth callback (temiz yol; Google Console query string kabul etmiyor)
$_GET['oauth'] = 'cb';
require __DIR__ . '/gmail.php';
