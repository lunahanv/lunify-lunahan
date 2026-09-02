<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// Atlas — Gmail bağlantı sayfası
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
require_once __DIR__ . '/includes/gmail.php';
$U = require_login();
if (!plan_allows($U, 'gmail')) { header('Location: account.php'); exit; }

$msg = '';
$is_admin = !empty($U['is_admin']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id']) && $is_admin) {
  set_setting('gmail_client_id', trim($_POST['client_id']));
  set_setting('gmail_client_secret', trim($_POST['client_secret'] ?? ''));
  header('Location: gmail.php?saved=1'); exit;
}
if (isset($_GET['disconnect'])) {
  foreach (['refresh_token','access_token','expires_at','address','scopes'] as $k) set_setting('gmail_' . $k, '');
  header('Location: gmail.php'); exit;
}
if (isset($_GET['connect'])) { header('Location: ' . gmail_auth_url()); exit; }
if (isset($_GET['oauth']) && $_GET['oauth'] === 'cb') {
  // Post/Redirect/Get: yetki kodu TEK KULLANIMLIK. Sayfa yenilenince ayni kod
  // Google'a ikinci kez gidip "invalid_grant / Bad Request" veriyordu — bu yuzden
  // sonucu session'a yazip temiz URL'e yonlendiriyoruz. (Viktor 2026-09-01)
  $res = '';
  if (!empty($_GET['code'])) {
    $code = (string)$_GET['code'];
    if (($_SESSION['gmail_last_code'] ?? '') === $code) {
      $res = gmail_ready()
        ? '✅ Google bağlandı: ' . htmlspecialchars(gmail_address())
        : '⚠️ İzin verilmedi.';
    } else {
      $_SESSION['gmail_last_code'] = $code;
      $j = gmail_exchange_code($code);
      if (!empty($j['refresh_token']) || !empty($j['access_token'])) {
        $res = '✅ Google bağlandı: ' . htmlspecialchars(gmail_address());
      } elseif (gmail_ready()) {
        // eski baglanti hala gecerli — kullaniciyi bosuna korkutma
        error_log('[atlas-gmail] exchange failed but connection ready: ' . json_encode($j));
        $res = '✅ Google bağlı: ' . htmlspecialchars(gmail_address());
      } else {
        error_log('[atlas-gmail] exchange failed: ' . json_encode($j));
        $err = (string)($j['error_description'] ?? ($j['error'] ?? ''));
        if ($err === '' || stripos($err, 'bad request') !== false) $err = 'izin kodu geçersiz veya süresi dolmuş — lütfen tekrar bağlan';
        $res = '⚠️ Bağlanamadı: ' . htmlspecialchars($err);
      }
    }
  } else {
    $res = '⚠️ İzin verilmedi.';
  }
  $_SESSION['gmail_msg'] = $res;
  header('Location: gmail.php'); exit;
}
if (!empty($_SESSION['gmail_msg'])) { $msg = $_SESSION['gmail_msg']; unset($_SESSION['gmail_msg']); }
$cid = gmail_cfg('client_id'); $sec = gmail_cfg('client_secret');
$connected = gmail_ready();
$addr = $connected ? gmail_address() : '';
atlas_header('Google', 'gmail', 'Atlas\'ın e-postalarını, takvimini, görevlerini ve Drive dosyalarını yönetebilmesi için Google hesabını bağla.');
?>
<style>
.atlas-page .sub{color:#6b7280;font-size:13px;margin-bottom:14px}
.atlas-page .st{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600}
.atlas-page .st.ok{background:#e6f7ec;color:#0f7a3d}.atlas-page .st.wait{background:#fff3e0;color:#a35b00}
.atlas-page .btn{display:inline-block;padding:10px 16px;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;text-decoration:none}
.atlas-page .btn.grey{background:#eceef6;color:#40424f}
.atlas-page input[type=text]{width:100%;padding:10px 12px;border-radius:10px;margin:6px 0}
.atlas-page code{background:#f2f3f9;padding:2px 6px;border-radius:6px;font-size:12px;word-break:break-all}
.dark .atlas-page code{background:#111827}
</style>

<div class="acard">
  <h3 style="text-transform:none;font-size:16px;color:inherit">Google bağlantısı</h3>
  <div class="sub">Tek bağlantı ile: Gmail (oku/gönder) · Google Takvim · Google Görevler · Google Drive (dosyaları gör, oluştur, düzenle, paylaş) · Google Kişiler (telefon rehberine kaydet)</div>
  <?php if ($msg) echo '<p><b>' . $msg . '</b></p>'; ?>
  <div>
    <?php if ($connected): ?>
      <span class="st ok">✓ Bağlı<?= $addr ? ' · ' . htmlspecialchars($addr) : '' ?></span>
      <?php if (!gmail_has_drive() || !gmail_has_contacts()): ?>
        <p style="margin-top:10px"><span class="st wait"><?= (!gmail_has_drive() && !gmail_has_contacts()) ? 'Drive ve Kişiler izni eksik' : (!gmail_has_drive() ? 'Google Drive izni eksik' : 'Google Kişiler izni eksik') ?></span><br>
        <span class="sub">Drive dosyalarını düzenleyebilmesi ve kişileri telefon rehberine (Google Kişiler) kaydedebilmesi için bağlantıyı bir kez yenile.</span></p>
        <p><a class="btn" href="?connect=1">İzinleri yenile</a>
           <a class="btn grey" href="?disconnect=1">Bağlantıyı kaldır</a></p>
      <?php else: ?>
        <p><a class="btn grey" href="?disconnect=1">Bağlantıyı kaldır</a></p>
      <?php endif; ?>
    <?php elseif ($cid && $sec): ?>
      <span class="st wait">Kimlik kayıtlı — izin verilmesi bekleniyor</span>
      <p><a class="btn" href="?connect=1">Google ile bağlan</a></p>
    <?php else: ?>
      <?php if ($is_admin): ?>
        <span class="st wait">Kurulum gerekli — aşağıdan Google kimlik bilgilerini gir</span>
      <?php else: ?>
        <span class="st wait">Google bağlantısı şu an kullanılamıyor</span>
        <p class="sub">Yöneticiyle iletişime geç.</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  
</div>

<?php if ($is_admin): ?>
<div class="acard">
  <h3 style="text-transform:none;font-size:16px;color:inherit">1. Google kimlik bilgileri</h3>
  <div class="sub">Google Cloud'dan alınan OAuth istemci bilgilerini buraya yapıştır.</div>
  <form method="post">
    <input type="text" name="client_id" value="<?= htmlspecialchars($cid) ?>" placeholder="Client ID (…apps.googleusercontent.com)">
    <input type="text" name="client_secret" value="<?= htmlspecialchars($sec) ?>" placeholder="Client secret (GOCSPX-…)">
    <button type="submit">Kaydet</button>
  </form>
  <p class="sub" style="margin-top:14px"><b>Yönlendirme adresi (Google'a bunu ekle):</b><br>
  <code><?= htmlspecialchars(gmail_redirect_uri()) ?></code></p>
</div>

<div class="acard">
  <h3 style="text-transform:none;font-size:16px;color:inherit">Nasıl alınır (5 dakika)</h3>
  <ol>
    <li><a href="https://console.cloud.google.com/" target="_blank">console.cloud.google.com</a> → üstten yeni proje oluştur (ör. "Atlas")</li>
    <li>Arama kutusuna <b>Gmail API</b> yaz → <b>Enable</b>; aynı şekilde <b>Google Drive API</b>, <b>Calendar API</b> ve <b>Tasks API</b>'yi de etkinleştir</li>
    <li>Sol menü → <b>APIs &amp; Services → OAuth consent screen</b> → External → uygulama adı "Atlas", destek e-postan → kaydet → <b>Test users</b> kısmına kendi Gmail adresini ekle</li>
    <li>Sol menü → <b>Credentials → Create credentials → OAuth client ID</b> → tip: <b>Web application</b></li>
    <li><b>Authorized redirect URIs</b> → <code><?= htmlspecialchars(gmail_redirect_uri()) ?></code> ekle → Create</li>
    <li>Çıkan <b>Client ID</b> ve <b>Client secret</b>'i yukarıdaki kutulara yapıştır → Kaydet → <b>Google ile bağlan</b></li>
  </ol>
</div>

<?php endif; ?>

<?php atlas_footer(); ?>
