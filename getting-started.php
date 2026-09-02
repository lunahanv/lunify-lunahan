<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — Baslangic Rehberi sayfasi (Getting Started) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
require_once __DIR__ . '/includes/onboarding.php';
atlas_session();
$L = atlas_lang();
$U = require_login();
ob_handle_action();

$first = !ob_was_seen();
ob_mark_seen();
$tr = ob_is_tr();

atlas_header($tr ? 'Başlangıç Rehberi' : 'Getting Started', 'start',
  $tr ? 'Atlas’ı 8 adımda tam kapasite çalışır hale getirin.'
      : 'Get Atlas fully working in eight short steps.');
?>
<?php if ($first) ob_render_welcome(); ?>
<?php ob_render('page'); ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <a href="guide.php?lang=<?= $L ?>" class="block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:border-primary-400">
    <div class="text-sm font-semibold text-gray-900 dark:text-white"><?= $tr ? 'Kullanım Kılavuzu' : 'User Guide' ?></div>
    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
      <?= $tr ? 'Ekran görüntülü, bölüm bölüm anlatım.' : 'Section-by-section walkthrough with screenshots.' ?>
    </div>
  </a>
  <a href="app.php?lang=<?= $L ?>" class="block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:border-primary-400">
    <div class="text-sm font-semibold text-gray-900 dark:text-white"><?= $tr ? 'Asistanla Sohbet' : 'Chat with Atlas' ?></div>
    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
      <?= $tr ? 'Ne yapabileceğini sorun; düz cümleyle iş yaptırın.' : 'Ask what it can do, then delegate in plain language.' ?>
    </div>
  </a>
  <a href="account.php?lang=<?= $L ?>" class="block bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-5 hover:border-primary-400">
    <div class="text-sm font-semibold text-gray-900 dark:text-white"><?= $tr ? 'Hesabım' : 'My Account' ?></div>
    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
      <?= $tr ? 'Paket, kullanım ve şifre ayarları.' : 'Plan, usage and password settings.' ?>
    </div>
  </a>
</div>

<p class="text-xs text-gray-400 mt-6">
  <?= $tr ? 'Bu sayfaya her zaman sol menüdeki “Başlangıç” maddesinden dönebilirsiniz.'
          : 'You can always come back from “Getting started” in the left menu.' ?>
</p>
<?php atlas_footer(); ?>
