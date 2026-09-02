<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Google ile giriş — OAuth callback */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
atlas_session();
$L = atlas_lang();
if (!google_oauth_ready()) { header('Location: login.php?lang=' . $L); exit; }
$state = $_GET['state'] ?? '';
if (!$state || !hash_equals((string)($_SESSION['gstate'] ?? ''), $state)) { header('Location: login.php?lang=' . $L); exit; }
$code = $_GET['code'] ?? '';
if (!$code) { header('Location: login.php?lang=' . $L); exit; }
$p = google_exchange($code);
if (!$p) { header('Location: login.php?mode=in&lang=' . $L . '&g=err'); exit; }
$r = google_login($p);
if (!$r['ok']) { header('Location: login.php?mode=in&lang=' . $L . '&g=sus'); exit; }
header('Location: app.php?lang=' . $L);
