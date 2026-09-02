<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — giriş / kayıt / şifremi unuttum */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
atlas_session();
$L = atlas_lang();
if (current_user()) { header('Location: app.php?lang=' . $L); exit; }

$mode = $_GET['mode'] ?? 'in';           // in | up | forgot
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  if ($act === 'in') {
    $r = auth_login($_POST['email'] ?? '', $_POST['password'] ?? '');
    if ($r['ok']) { header('Location: app.php?lang=' . $L); exit; }
    $err = $r['err'] === 'suspended' ? t('e_suspended') : t('e_bad'); $mode = 'in';
  } elseif ($act === 'up') {
    $r = auth_register($_POST['email'] ?? '', $_POST['password'] ?? '', trim($_POST['name'] ?? ''), $L);
    if ($r['ok']) { auth_start_session($r['user']); header('Location: getting-started.php?lang=' . $L); exit; }
    $err = t('e_' . $r['err']); $mode = 'up';
  } elseif ($act === 'forgot') {
    $mode = 'forgot';
    $u = auth_find_by_email($_POST['email'] ?? '');
    $ok = t('fp_sent');
    if ($u) {
      $token = auth_reset_create($u);
      $link = rtrim(APP_URL, '/') . '/reset.php?token=' . $token . '&lang=' . $L;
      $html = '<div style="font-family:Inter,Arial,sans-serif;max-width:520px">'
        . '<h2 style="color:#4b3f8f">Lunify Atlas</h2>'
        . '<p>' . ($L === 'en' ? 'We received a password reset request for your Atlas account.'
                              : 'Atlas hesabın için şifre sıfırlama talebi aldık.') . '</p>'
        . '<p><a href="' . htmlspecialchars($link) . '" style="background:#c9a227;color:#111;padding:12px 20px;'
        . 'border-radius:10px;text-decoration:none;font-weight:600">'
        . ($L === 'en' ? 'Set a new password' : 'Yeni şifre belirle') . '</a></p>'
        . '<p style="color:#666;font-size:13px">' . ($L === 'en'
            ? 'The link is valid for 1 hour. If you did not request this, ignore this email.'
            : 'Bağlantı 1 saat geçerlidir. Bu talebi sen yapmadıysan bu e-postayı yok sayabilirsin.') . '</p>'
        . '<p style="color:#999;font-size:12px">' . htmlspecialchars($link) . '</p></div>';
      $m = atlas_send_mail($u['email'], t('mail_subject'), $html);
      if (!$m['ok']) { $ok = ''; $err = t('fp_mailerr'); error_log('atlas reset mail: ' . $m['err']); }
    }
  }
}

$gready = google_oauth_ready();
if ($gready) { $_SESSION['gstate'] = $_SESSION['gstate'] ?? bin2hex(random_bytes(16)); }
?><!doctype html><html lang="<?= $L ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('login_title') ?></title>
<link rel="icon" href="assets/lunify-lockup.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css"></head>
<body class="login-bg">
<div class="lang-corner"><?= atlas_lang_switch() ?></div>
<div class="login-card">
  <div class="brand"><img class="logo" src="assets/lunify-lockup.png" alt="Lunify"><span class="tag">Atlas</span></div>
  <?php if ($mode !== 'forgot'): ?>
  <div class="tabs">
    <a class="<?= $mode==='in'?'on':'' ?>" href="?mode=in&lang=<?= $L ?>"><?= t('login_tab_in') ?></a>
    <a class="<?= $mode==='up'?'on':'' ?>" href="?mode=up&lang=<?= $L ?>"><?= t('login_tab_up') ?></a>
  </div>
  <?php endif; ?>
  <?php if ($err) echo '<div class="err">'.htmlspecialchars($err).'</div>'; ?>
  <?php if ($ok)  echo '<div class="okmsg">'.htmlspecialchars($ok).'</div>'; ?>

  <?php if ($mode === 'in'): ?>
    <p class="muted"><?= t('login_sub') ?></p>
    <form method="post">
      <input type="hidden" name="act" value="in">
      <input type="email" name="email" placeholder="<?= t('login_email') ?>" required autofocus>
      <input type="password" name="password" placeholder="<?= t('login_pass') ?>" required>
      <button type="submit"><?= t('login_btn') ?></button>
    </form>
    <a class="back" href="?mode=forgot&lang=<?= $L ?>"><?= t('login_forgot') ?></a>

  <?php elseif ($mode === 'up'): ?>
    <p class="muted"><?= t('login_signup_note') ?></p>
    <form method="post">
      <input type="hidden" name="act" value="up">
      <input type="text" name="name" placeholder="<?= t('login_name') ?>">
      <input type="email" name="email" placeholder="<?= t('login_email') ?>" required>
      <input type="password" name="password" placeholder="<?= t('login_pass2') ?>" required minlength="8">
      <button type="submit"><?= t('login_signup_btn') ?></button>
    </form>

  <?php else: ?>
    <p class="muted"><?= t('fp_sub') ?></p>
    <form method="post">
      <input type="hidden" name="act" value="forgot">
      <input type="email" name="email" placeholder="<?= t('login_email') ?>" required autofocus>
      <button type="submit"><?= t('fp_btn') ?></button>
    </form>
    <a class="back" href="?mode=in&lang=<?= $L ?>">← <?= t('login_tab_in') ?></a>
  <?php endif; ?>

  <?php if ($gready && $mode !== 'forgot'): ?>
    <div class="or"><span><?= t('login_or') ?></span></div>
    <a class="gbtn" href="<?= htmlspecialchars(google_auth_url($_SESSION['gstate'])) ?>">
      <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.4 5.4 2.5 13.2l7.8 6.1C12.2 13.3 17.6 9.5 24 9.5z"/><path fill="#4285F4" d="M46.5 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.7c-.6 3-2.3 5.5-4.8 7.2l7.4 5.8c4.3-4 6.8-9.9 6.8-17.5z"/><path fill="#FBBC05" d="M10.3 28.7a14.5 14.5 0 0 1 0-9.4l-7.8-6.1a24 24 0 0 0 0 21.6l7.8-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 12-2.1 16-5.9l-7.4-5.8c-2.1 1.4-4.9 2.3-8.6 2.3-6.4 0-11.8-3.8-13.7-9.3l-7.8 6.1C6.4 42.6 14.6 48 24 48z"/></svg>
      <span><?= t('login_google') ?></span>
    </a>
  <?php endif; ?>
  <a class="back" href="index.php?lang=<?= $L ?>"><?= t('login_back') ?></a>
  <div class="foot-note">© <?= date('Y') ?> <a href="https://www.lunify.world" target="_blank" rel="noopener">Lunify A.Ş.</a></div>
</div>
</body></html>
