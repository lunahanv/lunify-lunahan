<?php
/** LUNAHAN — e-posta dogrulama (Viktor 2026-09-09) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
atlas_session();
$L = atlas_lang();
$en = ($L === 'en');

/* Yeniden gonderme: verify.php?resend=1&email=... */
if (($_GET['resend'] ?? '') === '1') {
  $u = auth_find_by_email((string)($_GET['email'] ?? ''));
  if ($u && empty($u['email_verified'])) auth_verify_send($u, $L);
  $msg = $en ? 'If the address is registered, a new confirmation link has been sent.'
             : 'Adres kayıtlıysa yeni bir doğrulama bağlantısı gönderildi.';
  $ok = null;
} else {
  $u = auth_verify_apply((string)($_GET['token'] ?? ''));
  $ok = (bool)$u;
  if ($ok) { auth_start_session($u); }
  $msg = $ok
    ? ($en ? 'Your e-mail is confirmed. Welcome!' : 'E-postan doğrulandı. Hoş geldin!')
    : ($en ? 'This link is invalid or expired. Request a new one from the login page.'
           : 'Bağlantı geçersiz ya da süresi dolmuş. Giriş sayfasından yenisini isteyebilirsin.');
}
?><!doctype html><html lang="<?= $L ?>"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(APP_NAME) ?> — <?= $en ? 'E-mail confirmation' : 'E-posta doğrulama' ?></title>
<link rel="icon" href="assets/lunify-lockup.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,Arial,sans-serif;
background:radial-gradient(1000px 600px at 20% -10%,#2b2560,#0f0d1c 60%);color:#eee}
.b{max-width:420px;background:#161329;border:1px solid #2c2748;border-radius:18px;padding:30px 26px;text-align:center}
h1{font-size:18px;margin:0 0 10px}p{color:#b9b3d0;font-size:14px;line-height:1.5}
a.btn{display:inline-block;margin-top:18px;background:#c9a227;color:#161329;padding:11px 20px;border-radius:10px;
text-decoration:none;font-weight:700;font-size:14px}</style></head><body>
<div class="b">
  <h1><?= htmlspecialchars(APP_NAME) ?></h1>
  <p><?= htmlspecialchars($msg) ?></p>
  <a class="btn" href="<?= $ok ? 'app.php?lang=' . $L : 'login.php?lang=' . $L ?>">
    <?= $ok ? ($en ? 'Open the app' : 'Uygulamaya git') : ($en ? 'Back to login' : 'Girişe dön') ?></a>
</div></body></html>
