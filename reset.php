<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Şifre sıfırlama — e-postadaki bağlantı buraya düşer */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
atlas_session();
$L = atlas_lang();
$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$row = $token ? auth_reset_lookup($token) : null;
$err = ''; $done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $row) {
  if (auth_reset_apply($row, $_POST['password'] ?? '')) $done = true;
  else $err = t('e_weak');
}
?><!doctype html><html lang="<?= $L ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('rs_title') ?></title><link rel="icon" href="assets/lunify-lockup.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css"></head><body class="login-bg">
<div class="login-card">
  <div class="brand"><img class="logo" src="assets/lunify-lockup.png" alt="Lunify"><span class="tag">Atlas</span></div>
  <?php if (!$row): ?>
    <div class="err"><?= t('rs_bad') ?></div>
    <a class="back" href="login.php?mode=forgot&lang=<?= $L ?>"><?= t('login_forgot') ?></a>
  <?php elseif ($done): ?>
    <div class="okmsg"><?= t('rs_ok') ?></div>
    <a class="back" href="login.php?lang=<?= $L ?>"><?= t('login_tab_in') ?></a>
  <?php else: ?>
    <p class="muted"><?= htmlspecialchars($row['email']) ?></p>
    <?php if ($err) echo '<div class="err">'.$err.'</div>'; ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="password" name="password" placeholder="<?= t('rs_new') ?>" required minlength="8" autofocus>
      <button type="submit"><?= t('rs_btn') ?></button>
    </form>
  <?php endif; ?>
</div></body></html>
