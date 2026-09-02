<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — yönetim paneli (müşteriler, paketler, Google OAuth ayarları) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
$me = require_admin();
$L = atlas_lang();
$msg = '';
$pairCode = ''; $pairPhone = ''; $pairUid = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  if ($act === 'plan') {
    $uid = (int)$_POST['uid'];
    $months = max(0, (int)($_POST['months'] ?? 1));
    $until = $months ? date('Y-m-d H:i:s', strtotime('+' . $months . ' months')) : null;
    set_plan($uid, $_POST['plan'] ?? 'starter', $until, $_POST['status'] ?? 'active', 'admin');
    $msg = 'Paket güncellendi.';
  } elseif ($act === 'status') {
    db()->prepare('UPDATE users SET status=? WHERE id=? AND id<>?')
       ->execute([$_POST['status'] === 'suspended' ? 'suspended' : 'active', (int)$_POST['uid'], $me['id']]);
    $msg = 'Hesap durumu güncellendi.';
  } elseif ($act === 'google') {
    set_gsetting('google_client_id', trim($_POST['client_id'] ?? ''));
    if (trim($_POST['client_secret'] ?? '') !== '') set_gsetting('google_client_secret', trim($_POST['client_secret']));
    $msg = 'Google ayarları kaydedildi.';
  } elseif ($act === 'pair') {
    /* Musteri adina WhatsApp eslestirme kodu uret: musteri hic web sayfasi acmaz,
       kodu telefonda WhatsApp > Bagli cihazlar > Telefon numarasiyla baglan ekranina girer. */
    $puid = (int)($_POST['uid'] ?? 0);
    $ph   = preg_replace('/[^0-9]/', '', (string)($_POST['phone'] ?? ''));
    if (!$puid || strlen($ph) < 10) {
      $msg = 'Müşteri ve numara (ülke koduyla) gerekli.';
    } else {
      $url = rtrim(WA_BRIDGE_URL, '/') . '/pair?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . $puid;
      $ch = curl_init($url);
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>40,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-Bridge-Key: ' . WA_BRIDGE_KEY],
        CURLOPT_POSTFIELDS=>json_encode(['key'=>WA_BRIDGE_KEY, 'uid'=>$puid, 'phone'=>$ph])]);
      $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
      if (!empty($j['code'])) { $pairCode = (string)$j['code']; $pairPhone = $ph; $pairUid = $puid; }
      else { $msg = 'Kod alınamadı: ' . ($j['error'] ?? 'bilinmeyen hata'); }
    }
  } elseif ($act === 'admin') {
    db()->prepare('UPDATE users SET is_admin=? WHERE id=? AND id<>?')
       ->execute([(int)($_POST['is_admin'] ?? 0), (int)$_POST['uid'], $me['id']]);
    $msg = 'Yetki güncellendi.';
  }
}

$users = db()->query('SELECT u.*, (SELECT COUNT(*) FROM commands c WHERE c.user_id=u.id AND c.created_at>=DATE_FORMAT(NOW(),"%Y-%m-01")) AS used
                      FROM users u ORDER BY u.id')->fetchAll();
$plans = all_plans();
$reqs = db()->query('SELECT s.*, u.email FROM subscription_log s JOIN users u ON u.id=s.user_id
                     WHERE s.action=\'request\' ORDER BY s.id DESC LIMIT 15')->fetchAll();
atlas_header($L==='tr' ? 'Yönetim' : 'Admin', 'admin', $L==='tr' ? 'Müşteriler, paketler ve Google bağlantı ayarları.' : 'Customers, plans and Google connection settings.');
?>

<?php if ($msg): ?><div class="okmsg"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<section class="acard">
  <h3>Müşteriler (<?= count($users) ?>)</h3>
  <div class="tbl">
  <table>
    <tr><th>#</th><th>E-posta</th><th>Paket</th><th>Durum</th><th>Bitiş</th><th>Bu ay</th><th>Paket ata</th><th></th></tr>
    <?php foreach ($users as $x): $pl = plan_row($x['plan_code']); ?>
    <tr>
      <td><?= $x['id'] ?></td>
      <td><?= htmlspecialchars($x['email']) ?><?= $x['is_admin'] ? ' <b title="yönetici">★</b>' : '' ?><br>
          <small><?= htmlspecialchars((string)$x['name']) ?> · <?= date('d.m.Y', strtotime($x['created_at'])) ?></small></td>
      <td><?= htmlspecialchars($pl['name_tr']) ?></td>
      <td><?= htmlspecialchars($x['plan_status']) ?> / <?= htmlspecialchars($x['status']) ?></td>
      <td><?= $x['plan_until'] ? date('d.m.Y', strtotime($x['plan_until'])) : '—' ?></td>
      <td><?= (int)$x['used'] ?> / <?= (int)$pl['monthly_commands'] < 0 ? '∞' : (int)$pl['monthly_commands'] ?></td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="act" value="plan"><input type="hidden" name="uid" value="<?= $x['id'] ?>">
          <select name="plan"><?php foreach ($plans as $pp): ?>
            <option value="<?= $pp['code'] ?>"<?= $pp['code']===$x['plan_code']?' selected':'' ?>><?= htmlspecialchars($pp['name_tr']) ?></option>
          <?php endforeach; ?></select>
          <select name="status">
            <?php foreach (['active','trial','past_due','canceled'] as $st): ?>
            <option value="<?= $st ?>"<?= $st===$x['plan_status']?' selected':'' ?>><?= $st ?></option><?php endforeach; ?>
          </select>
          <input type="number" name="months" value="1" min="0" max="36" style="width:56px" title="kaç ay">
          <button type="submit">Kaydet</button>
        </form>
      </td>
      <td>
        <form method="post" class="inline">
          <input type="hidden" name="act" value="status"><input type="hidden" name="uid" value="<?= $x['id'] ?>">
          <input type="hidden" name="status" value="<?= $x['status']==='active'?'suspended':'active' ?>">
          <button type="submit"><?= $x['status']==='active'?'Askıya al':'Aktifleştir' ?></button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  </div>
</section>

<section class="acard">
  <h3>WhatsApp bağlama kodu (QR'sız)</h3>
  <p class="muted">Müşteri QR okutmakla uğraşmasın: numarasını yaz, çıkan 8 haneli kodu telefonda söyle.
    O da telefonunda <b>WhatsApp → Ayarlar → Bağlı cihazlar → Cihaz bağla → “Telefon numarasıyla bağlan”</b>
    yapıp kodu girer. Hiç web sayfası açmaz.</p>
  <?php if ($pairCode): ?>
    <div style="border:1px solid #d7dbe8;padding:14px;margin:10px 0">
      <div class="muted">+<?= htmlspecialchars($pairPhone) ?> için kod (birkaç dakika geçerli)</div>
      <div style="font-size:38px;font-weight:700;letter-spacing:8px"><?= htmlspecialchars(substr($pairCode,0,4) . '-' . substr($pairCode,4)) ?></div>
    </div>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="act" value="pair">
    <select name="uid">
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= $pairUid === (int)$u['id'] ? 'selected' : '' ?>>
          #<?= (int)$u['id'] ?> · <?= htmlspecialchars($u['name'] ?: $u['email']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="phone" placeholder="905321112233" value="<?= htmlspecialchars($pairPhone) ?>">
    <button type="submit">Kod üret</button>
  </form>
</section>

<section class="acard">
  <h3>Paket talepleri</h3>
  <div class="list">
    <?php if (!$reqs): ?><div class="muted">Talep yok.</div><?php endif; ?>
    <?php foreach ($reqs as $r): ?>
      <div class="row"><b><?= htmlspecialchars($r['email']) ?></b> → <?= htmlspecialchars($r['plan_code']) ?>
        <small><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="acard">
  <h3>Google ile giriş ayarları</h3>
  <p class="muted">Google Cloud Console → OAuth istemcisi. İzinli yönlendirme adresi:<br>
    <code><?= htmlspecialchars(google_redirect_uri()) ?></code></p>
  <form method="post">
    <input type="hidden" name="act" value="google">
    <input type="text" name="client_id" placeholder="Client ID" value="<?= htmlspecialchars(gsetting('google_client_id','')) ?>">
    <input type="password" name="client_secret" placeholder="Client Secret (değiştirmek için doldur)">
    <button type="submit">Kaydet</button>
  </form>
  <p class="muted">Durum: <?= google_oauth_ready() ? '✅ Google ile giriş açık' : '⚠️ Henüz yapılandırılmadı' ?></p>
</section>

<?php atlas_footer(); ?>
