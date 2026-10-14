<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 */

/** LUNAHAN — Baglantilar: diger Lunify uygulamalarini LUNAHAN'a bagla (Viktor 2026-09-09). */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
require_once __DIR__ . '/includes/connect.php';
atlas_session();
$L = atlas_lang();
$u = require_login();
/* Baglantilar ekrani SADECE admin (sahibin istegi 2026-09-09) */
if (empty($u['is_admin'])) { header('Location: app.php?lang=' . $L); exit; }
$tr = $L !== 'en';
$msg = '';
$err = '';

/* Bilinen Lunify uygulamalari — kullanici sadece token giriyor. */
$known = [
  'anahtar' => ['Lunify Anahtar', 'https://anahtar.lunify.world', $tr ? 'Emlak portföyü, müşteri takibi, emsal/değerleme' : 'Real-estate portfolio, leads, valuation'],
  'aura'    => ['Lunify Aura',    'https://aura.lunify.world',    $tr ? 'Randevu ve müşteri yönetimi' : 'Appointments and clients'],
  /* nexo: sahip karari 2026-09-09 - Nexo'ya dokunulmuyor, baglanti kapali */
  'pulse'   => ['Lunify Pulse',   'https://lunify-pulse.com.tr',   $tr ? 'Operasyon takibi' : 'Operations tracking'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = (string)($_POST['act'] ?? '');
  if ($act === 'add') {
    $app  = (string)($_POST['app'] ?? '');
    $base = trim((string)($_POST['base_url'] ?? ($known[$app][1] ?? '')));
    $tokn = trim((string)($_POST['token'] ?? ''));
    [$n, $e] = lh_link_add((int)$u['id'], $app, $base, $tokn);
    if ($e !== '') $err = ($tr ? 'Bağlanamadı: ' : 'Could not connect: ') . $e;
    else $msg = $tr ? "Bağlandı ✓ — $n araç eklendi." : "Connected ✓ — $n tools added.";
  } elseif ($act === 'refresh') {
    [$n, $e] = lh_link_refresh((int)($_POST['id'] ?? 0));
    if ($e !== '') $err = ($tr ? 'Yenilenemedi: ' : 'Refresh failed: ') . $e;
    else $msg = $tr ? "Güncellendi ✓ — $n araç." : "Updated ✓ — $n tools.";
  } elseif ($act === 'del') {
    db()->prepare('DELETE FROM lh_links WHERE id=? AND user_id=?')->execute([(int)($_POST['id'] ?? 0), (int)$u['id']]);
    $msg = $tr ? 'Bağlantı kaldırıldı.' : 'Connection removed.';
  }
}

lh_autolink((int)$u['id']);
$links = lh_links((int)$u['id'], false);
$byApp = [];
foreach ($links as $l) $byApp[$l['app_key']] = $l;

atlas_header($tr ? 'Bağlantılar' : 'Connections', 'links',
  $tr ? 'Diğer Lunify uygulamalarınızı LUNAHAN’a bağlayın — veriler o uygulamada kalır.'
      : 'Connect your other Lunify apps to LUNAHAN — data stays in those apps.');
?>
<style>
  .lk-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px}
  .lk{background:#fff;border:1px solid #e6e8ef;border-radius:14px;padding:18px}
  .lk h4{margin:0 0 4px;font-size:16px}
  .lk .desc{color:#6b7280;font-size:13px;margin-bottom:12px}
  .lk .st{display:inline-block;font-size:12px;padding:3px 9px;border-radius:999px;margin-bottom:10px}
  .st.ok{background:#e8f7ee;color:#16794a}.st.err{background:#fdeaea;color:#a52222}.st.no{background:#f1f2f6;color:#6b7280}
  .lk input[type=text]{width:100%;padding:9px 11px;border:1px solid #d9dce5;border-radius:9px;font-size:13px;margin-bottom:8px}
  .lk button{padding:8px 14px;border:0;border-radius:9px;background:#4f46e5;color:#fff;font-weight:600;cursor:pointer;font-size:13px}
  .lk button.gray{background:#eef0f6;color:#374151}
  .lk .row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .lk .tools{font-size:12px;color:#6b7280;margin-top:8px;line-height:1.5}
  .okmsg{background:#e8f7ee;color:#16794a;padding:10px 14px;border-radius:10px;margin-bottom:14px}
  .ermsg{background:#fdeaea;color:#a52222;padding:10px 14px;border-radius:10px;margin-bottom:14px}
  .howto{background:#f7f8fc;border:1px solid #e6e8ef;border-radius:14px;padding:16px 18px;margin-bottom:18px;font-size:14px;line-height:1.65;color:#374151}
</style>

<?php if ($msg) echo '<div class="okmsg">' . htmlspecialchars($msg) . '</div>'; ?>
<?php if ($err) echo '<div class="ermsg">' . htmlspecialchars($err) . '</div>'; ?>

<div class="howto">
  <?php if ($tr): ?>
    <b>Nasıl çalışır?</b> Bağladığınız uygulamanın araçları LUNAHAN sohbetine eklenir; “Bodrum’daki ilanlarımı listele”
    dediğinizde LUNAHAN o uygulamadaki gerçek verinizi kullanır. <b>Veri kopyalanmaz</b>, ilgili uygulamada kalır.<br>
    <b>Bağlanma anahtarı almak için:</b> ilgili uygulamaya girin → <i>Bağlantılar / LUNAHAN</i> sayfasından anahtar oluşturun ve buraya yapıştırın.
  <?php else: ?>
    <b>How it works:</b> the connected app’s tools appear inside LUNAHAN chat; asking “list my listings in Bodrum”
    uses your real data in that app. <b>Nothing is copied</b> — data stays there.<br>
    <b>To get a key:</b> open that app → <i>Connections / LUNAHAN</i> page → create a key and paste it here.
  <?php endif; ?>
</div>

<div class="lk-grid">
<?php foreach ($known as $key => [$name, $base, $desc]):
  $l = $byApp[$key] ?? null;
  $tools = $l ? (array)json_decode((string)$l['tools_json'], true) : [];
  $names = array_slice(array_map(fn($t) => $t['function']['name'] ?? '', $tools), 0, 6);
  $auto  = isset(lh_auto_apps()[$key]);
?>
  <div class="lk">
    <h4><?= htmlspecialchars($name) ?></h4>
    <div class="desc"><?= htmlspecialchars($desc) ?></div>
    <?php if ($l && $l['status'] === 'ok'): ?>
      <span class="st ok"><?= $tr ? ($auto ? 'Otomatik bağlı' : 'Bağlı') : ($auto ? 'Auto-connected' : 'Connected') ?> · <?= count($tools) ?> <?= $tr ? 'araç' : 'tools' ?></span>
      <div class="tools"><?= htmlspecialchars(implode(', ', $names)) ?><?= count($tools) > 6 ? ' …' : '' ?></div>
      <div class="row" style="margin-top:12px">
        <form method="post"><input type="hidden" name="act" value="refresh"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="gray" type="submit"><?= $tr ? 'Araçları yenile' : 'Refresh tools' ?></button></form>
        <form method="post" onsubmit="return confirm('<?= $tr ? 'Bağlantı kaldırılsın mı?' : 'Remove connection?' ?>')">
          <input type="hidden" name="act" value="del"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
          <button class="gray" type="submit"><?= $tr ? 'Kaldır' : 'Remove' ?></button></form>
      </div>
    <?php else: ?>
      <?php if ($l): ?><span class="st err"><?= $tr ? 'Hata' : 'Error' ?>: <?= htmlspecialchars(mb_substr((string)$l['last_error'], 0, 60)) ?></span>
      <?php else: ?><span class="st no"><?= $tr ? 'Bağlı değil' : 'Not connected' ?></span><?php endif; ?>
      <?php if ($auto): ?>
        <div class="tools"><?= $tr ? 'Bu uygulama <b>varsayılan olarak otomatik</b> bağlanır — anahtar girmeniz gerekmez. Aynı e-posta ile hesabınız varsa sohbette kendiliğinden devreye girer.' : 'This app connects <b>automatically by default</b> — no key needed.' ?></div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="act" value="add">
        <input type="hidden" name="app" value="<?= htmlspecialchars($key) ?>">
        <input type="text" name="token" placeholder="<?= $tr ? 'Bağlanma anahtarı' : 'Connection key' ?>" required>
        <input type="text" name="base_url" value="<?= htmlspecialchars($l['base_url'] ?? $base) ?>" placeholder="https://…">
        <button type="submit"><?= $tr ? 'Bağlan' : 'Connect' ?></button>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php atlas_footer(); ?>
