<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * LUNAHAN — YÖNETİM GİRİŞİ (kullanıcı girişinden tamamen ayrı kapı)
 */
require_once __DIR__ . '/includes/auth.php';
atlas_session();
$L = (($_GET['lang'] ?? $_POST['lang'] ?? 'tr') === 'en') ? 'en' : 'tr';
$err = '';

if (($_GET['out'] ?? '') === '1') { unset($_SESSION['admin_gate']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $em = strtolower(trim((string)($_POST['email'] ?? '')));
  $pw = (string)($_POST['password'] ?? '');
  $r = auth_login($em, $pw);
  if ($r['ok']) {
    $u = current_user();
    if ($u && !empty($u['is_admin'])) {
      $_SESSION['admin_gate'] = 1;
      header('Location: admin.php'); exit;
    }
    unset($_SESSION['admin_gate']);
    session_destroy();
    $err = $L === 'en' ? 'This account has no admin rights.' : 'Bu hesabın yönetim yetkisi yok.';
  } else {
    $err = $L === 'en' ? 'Wrong e-mail or password.' : 'E-posta veya şifre hatalı.';
  }
  usleep(400000);
}

$u = current_user();
if ($u && !empty($u['is_admin']) && !empty($_SESSION['admin_gate'])) { header('Location: admin.php'); exit; }
?><!doctype html><html lang="<?= $L ?>"><head>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="LUNAHAN">
<meta name="theme-color" content="#ffffff"><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>LUNAHAN — <?= $L === 'en' ? 'Admin sign in' : 'Yönetim girişi' ?></title>
<link rel="icon" href="assets/lunify-lockup.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,Arial,sans-serif;
  background:radial-gradient(1000px 600px at 20% -10%,#2b2560,#0f0d1c 60%);color:#eee}
.box{width:100%;max-width:380px;background:#161329;border:1px solid #2c2748;border-radius:18px;padding:30px 26px;
  box-shadow:0 24px 60px rgba(0,0,0,.45)}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.brand b{letter-spacing:.14em;font-size:15px}
.tag{color:#c9a227;font-size:12px;letter-spacing:.18em;text-transform:uppercase;margin:0 0 20px}
label{display:block;font-size:12px;color:#9c96b8;margin:14px 0 6px}
input{width:100%;padding:12px 13px;border-radius:10px;border:1px solid #322c52;background:#0f0d1c;color:#fff;font-size:14px}
button{width:100%;margin-top:20px;padding:12px;border:0;border-radius:10px;background:#c9a227;color:#161329;
  font-weight:700;font-size:14px;cursor:pointer}
.err{margin-top:16px;background:#3a1a24;border:1px solid #6b2b3c;color:#ffb4c2;padding:10px 12px;border-radius:10px;font-size:13px}
.foot{margin-top:18px;font-size:12px;color:#6f6a86;text-align:center}
.foot a{color:#9c96b8}
</style></head><body>
<form class="box" method="post">
  <input type="hidden" name="lang" value="<?= $L ?>">
  <div class="brand"><img src="assets/lunify-lockup.png" alt="" height="26"><b>LUNAHAN</b></div>
  <p class="tag"><?= $L === 'en' ? 'Admin area' : 'Yönetim alanı' ?></p>
  <label><?= $L === 'en' ? 'Admin e-mail' : 'Yönetici e-postası' ?></label>
  <input type="email" name="email" required autofocus autocomplete="username">
  <label><?= $L === 'en' ? 'Password' : 'Şifre' ?></label>
  <input type="password" name="password" required autocomplete="current-password">
  <button type="submit"><?= $L === 'en' ? 'Sign in' : 'Giriş' ?></button>
  <?php if ($err !== ''): ?><div class="err"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <p class="foot"><?= $L === 'en' ? 'Customer login:' : 'Müşteri girişi:' ?>
    <a href="login.php?lang=<?= $L ?>">login.php</a> ·
    <a href="yonetim.php?lang=<?= $L === 'en' ? 'tr' : 'en' ?>"><?= $L === 'en' ? 'TR' : 'EN' ?></a></p>
</form>
</body></html>
