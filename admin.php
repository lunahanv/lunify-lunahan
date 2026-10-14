<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** LUNAHAN — yönetim paneli (müşteriler, paketler, Google OAuth ayarları) */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
/* Yonetim paneli AYRI kapidan girilir: yonetim.php */
$__u = current_user();
if (!$__u || empty($__u['is_admin']) || empty($_SESSION['admin_gate'])) { header('Location: yonetim.php'); exit; }
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
  } elseif ($act === 'newuser') {
    $nm = trim((string)($_POST['nu_name'] ?? ''));
    $em = strtolower(trim((string)($_POST['nu_email'] ?? '')));
    $pw = (string)($_POST['nu_pass'] ?? '');
    $pl = (string)($_POST['nu_plan'] ?? 'trial');
    $mo = max(0, (int)($_POST['nu_months'] ?? 3));
    if (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($pw) < 8 || $nm === '') {
      $msg = 'Ad, geçerli e-posta ve en az 8 karakter şifre gerekli.';
    } else {
      $ex = db()->prepare('SELECT id FROM users WHERE email=?'); $ex->execute([$em]);
      if ($ex->fetch()) { $msg = 'Bu e-posta zaten kayıtlı.'; }
      else {
        db()->prepare('INSERT INTO users(email,pass_hash,name,is_admin,status,lang,created_at) VALUES(?,?,?,0,?,?,NOW())')
           ->execute([$em, password_hash($pw, PASSWORD_DEFAULT), $nm, 'active', 'tr']);
        $nid = (int)db()->lastInsertId();
        $until = $mo ? date('Y-m-d H:i:s', strtotime('+' . $mo . ' months')) : null;
        set_plan($nid, $pl, $until, 'active', 'admin-create');
        $msg = 'Kullanıcı oluşturuldu: ' . $em;
      }
    }
  } elseif ($act === 'resetpw') {
    $uid = (int)($_POST['uid'] ?? 0);
    $pw  = (string)($_POST['newpass'] ?? '');
    if (strlen($pw) < 8) { $msg = 'Şifre en az 8 karakter olmalı.'; }
    else {
      db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')->execute([password_hash($pw, PASSWORD_DEFAULT), $uid]);
      $msg = 'Şifre güncellendi.';
    }
  } elseif ($act === 'llm') {
    /* AI baglantisi (API anahtari/model) — sahibin istegi 2026-09-04 */
    set_gsetting('llm_base',  trim($_POST['llm_base'] ?? ''));
    set_gsetting('llm_model', trim($_POST['llm_model'] ?? ''));
    if (trim($_POST['llm_key'] ?? '') !== '') set_gsetting('llm_key', trim($_POST['llm_key']));
    if (!empty($_POST['llm_key_clear'])) set_gsetting('llm_key', '');
    set_gsetting('llm_base2',  trim($_POST['llm_base2'] ?? ''));
    set_gsetting('llm_model2', trim($_POST['llm_model2'] ?? ''));
    if (trim($_POST['llm_key2'] ?? '') !== '') set_gsetting('llm_key2', trim($_POST['llm_key2']));
    if (!empty($_POST['llm_key2_clear'])) set_gsetting('llm_key2', '');
    $msg = 'AI bağlantı ayarları kaydedildi.';
  } elseif ($act === 'search') {
    /* Web arama saglayicisi (Viktor 2026-09-10): anahtar yoksa ajan guncel bilgiye ulasamiyor. */
    $sp = strtolower(trim((string)($_POST['search_provider'] ?? 'serper')));
    set_gsetting('search_provider', in_array($sp, ['serper','brave'], true) ? $sp : 'serper');
    if (trim($_POST['search_key'] ?? '') !== '') set_gsetting('search_api_key', trim($_POST['search_key']));
    if (!empty($_POST['search_key_clear'])) set_gsetting('search_api_key', '');
    $msg = 'Web arama ayarları kaydedildi.';
    if (!empty($_POST['search_test'])) {
      require_once __DIR__ . '/includes/agent.php';
      $res = function_exists('atlas_search_api') ? atlas_search_api('dolar kuru') : [];
      $msg .= ' Test: ' . (count($res) > 0 ? ('BAŞARILI — ' . count($res) . ' sonuç geldi.') : 'BAŞARISIZ — sonuç alınamadı, anahtarı/sağlayıcıyı kontrol et.');
    }
  } elseif ($act === 'userllm') {
    /* Kisisel API anahtarlari (Viktor 2026-09-10): kullanici basina SINIRSIZ anahtar,
       her anahtarin amaci var (genel = metin, gorsel = gorsel/PDF okuma, hepsi). */
    require_once __DIR__ . '/includes/agent.php';
    atlas_user_ai_schema();
    $uid   = (int)($_POST['uid'] ?? 0);
    $rid   = (int)($_POST['rid'] ?? 0);
    $pur   = (string)($_POST['purpose'] ?? 'genel');
    if (!in_array($pur, ['genel', 'gorsel', 'hepsi'], true)) $pur = 'genel';
    $slot  = max(0, min(99, (int)($_POST['slot'] ?? 0)));
    $label = mb_substr(trim((string)($_POST['u_label'] ?? '')), 0, 60);
    $base  = trim((string)($_POST['u_base'] ?? ''));
    $mdl   = trim((string)($_POST['u_model'] ?? ''));
    $key   = trim((string)($_POST['u_key'] ?? ''));
    if (!empty($_POST['u_delete'])) {
      if ($rid > 0) { db()->prepare('DELETE FROM user_ai_keys WHERE id=?')->execute([$rid]); $msg = 'Anahtar silindi.'; }
      else { $msg = 'Silinecek anahtar seçilmedi.'; }
    } elseif ($uid <= 0 && $rid <= 0) { $msg = 'Kullanıcı seçilmedi.'; }
    elseif ($rid > 0) {
      db()->prepare('UPDATE user_ai_keys SET purpose=?, slot=?, label=?, api_base=?, model=?, is_active=1 WHERE id=?')
         ->execute([$pur, $slot, $label, $base, $mdl, $rid]);
      if ($key !== '') db()->prepare('UPDATE user_ai_keys SET api_key=? WHERE id=?')->execute([function_exists('hsecSeal') ? hsecSeal($key) : $key, $rid]);
      $msg = 'Anahtar güncellendi.';
    } elseif ($key === '') { $msg = 'API anahtarı gerekli.'; }
    else {
      db()->prepare('INSERT INTO user_ai_keys (user_id, slot, purpose, label, api_base, api_key, model) VALUES (?,?,?,?,?,?,?)')
         ->execute([$uid, $slot, $pur, $label, $base, function_exists('hsecSeal') ? hsecSeal($key) : $key, $mdl]);
      $msg = 'Anahtar eklendi.';
    }
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
  } elseif ($act === 'provision' || $act === 'unprovision') {
    /* 2026-09-09 sahip karari: uygulama baglantilarini sadece yonetici kurar.
       Musteri hicbir token gormez; karsi uygulamanin lh_admin.php ucundan
       o kullanicinin adina anahtar aliriz. */
    require_once __DIR__ . '/includes/connect.php';
    $pu = db()->prepare('SELECT id, email FROM users WHERE id=?');
    $pu->execute([(int)($_POST['puid'] ?? 0)]);
    $pUser = $pu->fetch(PDO::FETCH_ASSOC);
    $appKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['app'] ?? '')));
    $catalog = lh_app_catalog();
    if (!$pUser || !isset($catalog[$appKey])) {
      $msg = 'Kullanıcı veya uygulama seçilmedi.';
    } else {
      $base = $catalog[$appKey]['url'];
      $ch = curl_init($base . '/lh_admin.php');
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-LH-Admin: ' . (defined('LH_ADMIN_KEY') ? LH_ADMIN_KEY : '')],
        CURLOPT_POSTFIELDS=>json_encode([
          'act'   => $act === 'provision' ? 'issue' : 'revoke',
          'email' => $pUser['email'],
        ], JSON_UNESCAPED_UNICODE)]);
      $raw = (string)curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
      $j = json_decode($raw, true);
      if (!is_array($j)) {
        $msg = strtoupper($appKey) . ' yanıt vermedi: ' . ($cerr ?: mb_substr($raw, 0, 120));
      } elseif (!empty($j['error'])) {
        $msg = strtoupper($appKey) . ': ' . $j['error'];
      } elseif ($act === 'unprovision') {
        db()->prepare('DELETE FROM lh_links WHERE user_id=? AND app_key=?')->execute([(int)$pUser['id'], $appKey]);
        $msg = strtoupper($appKey) . ' bağlantısı kaldırıldı ve karşı taraftaki anahtar iptal edildi.';
      } else {
        [$n, $e] = lh_link_add((int)$pUser['id'], $appKey, $base, (string)($j['token'] ?? ''));
        $msg = $e === '' ? strtoupper($appKey) . ' bağlandı: ' . $pUser['email'] . ' — ' . $n . ' araç hazır.'
                         : strtoupper($appKey) . ' bağlanamadı: ' . $e;
      }
    }
  } elseif ($act === 'linkref') {
    require_once __DIR__ . '/includes/connect.php';
    [$n, $e] = lh_link_refresh((int)($_POST['lid'] ?? 0));
    $msg = $e === '' ? "Bağlantı yenilendi: $n araç." : "Bağlantı hatası: $e";
  } elseif ($act === 'linkdel') {
    db()->prepare('DELETE FROM lh_links WHERE id=?')->execute([(int)($_POST['lid'] ?? 0)]);
    $msg = 'Bağlantı kaldırıldı.';
  } elseif ($act === 'deluser') {
    /* Kullanici silme — sahibin istegi 2026-09-10. Kendini ve son yoneticiyi silemez.
       FK yok: kullanici_id tasiyan tum tablolar elle temizlenir. */
    $duid = (int)($_POST['uid'] ?? 0);
    $conf = strtolower(trim((string)($_POST['confirm_email'] ?? '')));
    $q = db()->prepare('SELECT id,email,is_admin FROM users WHERE id=?'); $q->execute([$duid]);
    $du = $q->fetch(PDO::FETCH_ASSOC);
    $adminCount = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_admin=1')->fetchColumn();
    if (!$du) {
      $msg = 'Kullanıcı bulunamadı.';
    } elseif ($duid === (int)$me['id']) {
      $msg = 'Kendi hesabınızı silemezsiniz.';
    } elseif ((int)$du['is_admin'] === 1 && $adminCount <= 1) {
      $msg = 'Son yönetici hesabı silinemez.';
    } elseif ($conf !== 'delete') {
      $msg = 'Silme onaylanmadı: onay kutusuna DELETE yazılmalı.';
    } else {
      $notes = [];
      /* a) Bagli Lunify uygulamalarindaki anahtarlari iptal et */
      require_once __DIR__ . '/includes/connect.php';
      $catalog = lh_app_catalog();
      $lq = db()->prepare('SELECT app_key FROM lh_links WHERE user_id=?'); $lq->execute([$duid]);
      foreach ($lq->fetchAll(PDO::FETCH_COLUMN) as $ak) {
        if (!isset($catalog[$ak])) continue;
        $ch = curl_init($catalog[$ak]['url'] . '/lh_admin.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true,
          CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'X-LH-Admin: ' . (defined('LH_ADMIN_KEY') ? LH_ADMIN_KEY : '')],
          CURLOPT_POSTFIELDS=>json_encode(['act'=>'revoke','email'=>$du['email']], JSON_UNESCAPED_UNICODE)]);
        $raw = (string)curl_exec($ch); curl_close($ch);
        $jj = json_decode($raw, true);
        $notes[] = strtoupper($ak) . (is_array($jj) && empty($jj['error']) ? ' anahtarı iptal edildi' : ' anahtarı iptal EDİLEMEDİ');
      }
      /* b) WhatsApp koprusunden oturumu kapat (kopru DB'de degil) */
      if (defined('WA_BRIDGE_URL') && defined('WA_BRIDGE_KEY')) {
        $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . '/logout?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . $duid);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]);
        $raw = (string)curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
        $notes[] = $cerr === '' ? 'WhatsApp oturumu kapatıldı' : 'WhatsApp köprüsüne ulaşılamadı';
      }
      /* c) Kullaniciya ait satirlari temizle (paylasilan havuz satirlari user_id=0, etkilenmez) */
      $tables = ['actions','ai_usage_log','atlas_ai_providers','commands','contacts','conversations',
                 'lh_files','lh_links','memories','password_resets','re_comps','re_leads','re_listings',
                 'reminders','settings','subscription_log','user_ai_keys'];
      $wiped = 0;
      foreach ($tables as $t) {
        $st = db()->prepare('DELETE FROM `' . $t . '` WHERE user_id=?');
        $st->execute([$duid]); $wiped += $st->rowCount();
      }
      db()->prepare('DELETE FROM users WHERE id=?')->execute([$duid]);
      $msg = 'Kullanıcı silindi: ' . $du['email'] . ' (#' . $duid . ') — ' . $wiped . ' ilişkili kayıt temizlendi.'
           . ($notes ? ' · ' . implode(' · ', $notes) : '');
    }
  } elseif ($act === 'admin') {
    db()->prepare('UPDATE users SET is_admin=? WHERE id=? AND id<>?')
       ->execute([(int)($_POST['is_admin'] ?? 0), (int)$_POST['uid'], $me['id']]);
    $msg = 'Yetki güncellendi.';
  }
}

/* Baglanabilecek Lunify uygulamalari (yonetici provizyonu icin). */
function lh_app_catalog(): array {
  return [
    'anahtar' => ['name' => 'Lunify Anahtar (emlak)', 'url' => 'https://anahtar.lunify.world'],
  ];
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
            <button type="button" class="lnk-reset" onclick="atlasResetPw(<?= (int)$x['id'] ?>, '<?= htmlspecialchars($x['email'], ENT_QUOTES) ?>')">Şifre sıfırla</button>
        <?php if ((int)$x['id'] !== (int)$me['id']): ?>
            <button type="button" class="lnk-reset" style="color:#b00020" onclick="lhDelUser(<?= (int)$x['id'] ?>, '<?= htmlspecialchars($x['email'], ENT_QUOTES) ?>')">Sil</button>
        <?php endif; ?>
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
          #<?= (int)$u['id'] ?> · <?= htmlspecialchars(($u['name'] ?: '—') . ' · ' . $u['email']) ?></option>
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
  <h3>Uygulama bağlantıları (senkron)</h3>
  <?php
    require_once __DIR__ . '/includes/connect.php';
    lh_links_schema();
    $allLinks = db()->query('SELECT l.*, u.email FROM lh_links l JOIN users u ON u.id=l.user_id ORDER BY u.email, l.app_key')->fetchAll(PDO::FETCH_ASSOC);
  ?>
  <p class="muted">Bağlantıları <b>sadece siz</b> kurarsınız. Kullanıcı tarafında hiçbir teknik adım yok — o yalnızca WhatsApp’ını okutur, Gmail’ini bağlar. Aşağıdan kullanıcıyı ve uygulamayı seçip “Bağla” demeniz yeterli; anahtar arka planda üretilir.</p>
  <form method="post" style="margin:0 0 14px">
    <input type="hidden" name="act" value="provision">
    <select name="puid" required>
      <option value="">Kullanıcı seçin…</option>
      <?php foreach (db()->query('SELECT id,email,name FROM users ORDER BY email')->fetchAll(PDO::FETCH_ASSOC) as $cu): ?>
        <option value="<?= (int)$cu['id'] ?>"><?= htmlspecialchars($cu['email']) ?><?= $cu['name'] ? ' — ' . htmlspecialchars($cu['name']) : '' ?></option>
      <?php endforeach; ?>
    </select>
    <select name="app" required>
      <?php foreach (lh_app_catalog() as $k => $a): ?>
        <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($a['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit">Bağla</button>
  </form>
  <p class="muted">“Yenile” karşı uygulamadan araç listesini tekrar çeker (senkron); “Kaldır” bağlantıyı keser ve karşı taraftaki anahtarı iptal eder. Veriler karşı uygulamada kalır. “Yenile” karşı uygulamadan araç listesini tekrar çeker (senkron); “Kaldır” bağlantıyı keser. Veriler karşı uygulamada kalır.</p>
  <?php if (!$allLinks): ?><div class="muted">Henüz bağlantı yok.</div><?php else: ?>
  <table>
    <tr><th>Kullanıcı</th><th>Uygulama</th><th>Adres</th><th>Durum</th><th>Araç</th><th>Son senkron</th><th></th></tr>
    <?php foreach ($allLinks as $l): $tls = (array)json_decode((string)$l['tools_json'], true); ?>
      <tr>
        <td><?= htmlspecialchars($l['email']) ?></td>
        <td><b><?= htmlspecialchars(strtoupper($l['app_key'])) ?></b></td>
        <td><small><?= htmlspecialchars($l['base_url']) ?></small></td>
        <td><?= $l['status'] === 'ok' ? '✓ bağlı' : '⚠ ' . htmlspecialchars(mb_substr((string)$l['last_error'], 0, 40)) ?></td>
        <td><?= count($tls) ?></td>
        <td><small><?= $l['tools_at'] ? date('d.m.Y H:i', strtotime($l['tools_at'])) : '—' ?></small></td>
        <td>
          <form method="post" style="display:inline"><input type="hidden" name="act" value="linkref">
            <input type="hidden" name="lid" value="<?= (int)$l['id'] ?>"><button type="submit">Yenile</button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Bağlantı kaldırılsın mı?')">
            <input type="hidden" name="act" value="unprovision">
            <input type="hidden" name="puid" value="<?= (int)$l['user_id'] ?>">
            <input type="hidden" name="app" value="<?= htmlspecialchars($l['app_key']) ?>"><button type="submit">Kaldır</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
</section>

<section class="acard">
  <h3>Yeni kullanıcı</h3>
  <form method="post">
    <input type="hidden" name="act" value="newuser">
    <input type="text" name="nu_name" placeholder="Ad Soyad" required>
    <input type="email" name="nu_email" placeholder="E-posta" required>
    <input type="text" name="nu_pass" placeholder="Şifre (en az 8 karakter)" required minlength="8">
    <select name="nu_plan">
      <?php foreach ($plans as $pl): ?>
        <option value="<?= htmlspecialchars($pl['code']) ?>" <?= $pl['code'] === 'trial' ? 'selected' : '' ?>><?= htmlspecialchars($pl['name_tr'] ?: $pl['code']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="number" name="nu_months" value="3" min="0" max="120" style="max-width:90px" title="Süre (ay)">
    <button type="submit">Oluştur</button>
  </form>
  <p class="muted">Kullanıcı hemen giriş yapabilir; şifreyi kendisiyle paylaş.</p>
</section>

<section class="acard">
  <h3>AI bağlantısı (API anahtarı)</h3>
  <?php
    require_once __DIR__ . '/includes/agent.php';
    $llmKey = atlas_llm_key();
    $llmMask = $llmKey === '' ? '—' : (substr($llmKey, 0, 8) . str_repeat('•', 12) . substr($llmKey, -4));
    $llmFromDb = trim((string)gsetting('llm_key', '')) !== '';
  ?>
  <p class="muted">Asistanın kullandığı sağlayıcı ve model. Boş bırakılan alanlar sunucu ayar dosyasındaki değeri kullanır.<br>
    Aktif anahtar: <code><?= htmlspecialchars($llmMask) ?></code> (<?= $llmFromDb ? 'panelden' : 'ayar dosyasından' ?>)</p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="act" value="llm">
    <input type="text" name="llm_base" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="API adresi (örn. https://openrouter.ai/api/v1)" value="<?= htmlspecialchars(atlas_llm_base()) ?>">
    <input type="text" name="llm_model" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Model (örn. openai/gpt-5.6-luna)" value="<?= htmlspecialchars(atlas_llm_model()) ?>">
    <input type="password" name="llm_key" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="API anahtarı (değiştirmek için doldur)">
    <label class="muted"><input type="checkbox" name="llm_key_clear" value="1"> Panel anahtarını sil (ayar dosyasındakine dön)</label>
    <?php
      $b2 = trim((string)gsetting('llm_base2','')); if ($b2==='' && defined('LLM_BASE2')) $b2 = LLM_BASE2;
      $m2 = trim((string)gsetting('llm_model2','')); if ($m2==='' && defined('LLM_MODEL2')) $m2 = LLM_MODEL2;
      $k2 = trim((string)gsetting('llm_key2','')); if ($k2==='' && defined('LLM_KEY2')) $k2 = LLM_KEY2;
      $k2m = $k2==='' ? '—' : (substr($k2,0,6) . str_repeat('•',10) . substr($k2,-4));
    ?>
    <p class="muted" style="margin-top:14px"><b>Yedek sağlayıcı</b> — asıl sağlayıcı hata verirse istek otomatik buraya düşer.
      Aktif yedek anahtar: <code><?= htmlspecialchars($k2m) ?></code></p>
    <input type="text" name="llm_base2" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Yedek API adresi (örn. https://api.deepseek.com/v1)" value="<?= htmlspecialchars($b2) ?>">
    <input type="text" name="llm_model2" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Yedek model (örn. deepseek-chat)" value="<?= htmlspecialchars($m2) ?>">
    <input type="password" name="llm_key2" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Yedek API anahtarı (değiştirmek için doldur)">
    <label class="muted"><input type="checkbox" name="llm_key2_clear" value="1"> Yedek panel anahtarını sil</label>
    <button type="submit">Kaydet</button>
  </form>
</section>

<section class="acard">
  <h3>Web arama (API anahtarı)</h3>
  <?php
    $sKey = trim((string)gsetting('search_api_key', ''));
    $sProv = strtolower(trim((string)gsetting('search_provider', 'serper')));
    $sMask = $sKey === '' ? '— tanımsız —' : (substr($sKey, 0, 6) . str_repeat('•', 10) . substr($sKey, -4));
  ?>
  <p class="muted">Asistanın güncel bilgi (kur, hava, haber, ilan) bulabilmesi için gerekli. <b>Anahtar yoksa</b> arama motorları bot koruması yüzünden boş/alakasız sonuç döner ve sohbette “İşlem çok uzun sürdü” hatası çıkar.<br>
    Aktif anahtar: <code><?= htmlspecialchars($sMask) ?></code> · sağlayıcı: <code><?= htmlspecialchars($sProv) ?></code></p>
  <form method="post" autocomplete="off">
    <input type="hidden" name="act" value="search">
    <select name="search_provider">
      <option value="serper" <?= $sProv === 'serper' ? 'selected' : '' ?>>Serper (Google)</option>
      <option value="brave" <?= $sProv === 'brave' ? 'selected' : '' ?>>Brave Search</option>
    </select>
    <input type="password" name="search_key" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Arama API anahtarı (değiştirmek için doldur)">
    <label class="muted"><input type="checkbox" name="search_key_clear" value="1"> Anahtarı sil</label>
    <label class="muted"><input type="checkbox" name="search_test" value="1" checked> Kaydettikten sonra test et</label>
    <button type="submit">Kaydet</button>
  </form>
</section>

<section class="acard">
  <h3>Kişisel API anahtarları (kullanıcıya özel · çoklu)</h3>
  <?php
    atlas_user_ai_schema();
    $uaUsers = db()->query('SELECT id, name, email FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $uaRows  = db()->query('SELECT k.*, u.name AS uname, u.email AS uemail FROM user_ai_keys k LEFT JOIN users u ON u.id=k.user_id ORDER BY k.user_id, k.purpose, k.slot')->fetchAll(PDO::FETCH_ASSOC);
    if (function_exists('hsecOpenRow')) $uaRows = array_map('hsecOpenRow', $uaRows);
    $uaMask = function ($k) { $k = trim((string)$k); return $k === '' ? '—' : (substr($k, 0, 8) . str_repeat('•', 10) . substr($k, -4)); };
    $uaPur  = ['genel' => 'Genel (metin)', 'gorsel' => 'Görsel & PDF', 'hepsi' => 'Hepsi'];
  ?>
  <p class="muted">Bir kullanıcıya <b>istediğin kadar</b> anahtar tanımlayabilirsin. Her anahtarın <b>amacı</b> vardır:
    <b>Genel (metin)</b> normal sohbet/metin işleri, <b>Görsel &amp; PDF</b> fotoğraf/PDF okuma, <b>Hepsi</b> her iş.
    İş tipine uygun anahtarlar <b>Sıra</b> numarasına göre denenir (0 = ilk), hata verirse sıradakine, en son uygulama geneli ayara düşer.<br>
    Model kutusuna <code>auto</code> yazarsan OpenRouter'daki <b>en ucuz uygun model</b> otomatik seçilir (günde bir yenilenir).
    Ekonomik kurulum önerisi: metin → DeepSeek (<code>https://api.deepseek.com/v1</code> · <code>deepseek-chat</code>),
    görsel/PDF → OpenRouter (<code>https://openrouter.ai/api/v1</code> · <code>auto</code>).</p>
  <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:12px">
    <tr style="text-align:left;color:#888"><th>Kullanıcı</th><th>Amaç</th><th>Sıra</th><th>Etiket</th><th>API adresi</th><th>Model</th><th>Anahtar</th><th></th></tr>
    <?php foreach ($uaRows as $ur): ?>
      <tr style="border-top:1px solid #eee">
        <td><?= htmlspecialchars($ur['uname'] ?: ($ur['uemail'] ?: ('#' . (int)$ur['user_id']))) ?></td>
        <td><?= htmlspecialchars($uaPur[(string)($ur['purpose'] ?? 'genel')] ?? 'Genel (metin)') ?></td>
        <td><?= (int)$ur['slot'] ?></td>
        <td><?= htmlspecialchars((string)($ur['label'] ?? '')) ?></td>
        <td><?= htmlspecialchars($ur['api_base'] ?: '(genel)') ?></td>
        <td><?= htmlspecialchars($ur['model'] ?: '(genel)') ?></td>
        <td><code><?= htmlspecialchars($uaMask($ur['api_key'])) ?></code></td>
        <td style="white-space:nowrap">
          <a href="#uaForm" onclick="uaEdit(<?= (int)$ur['id'] ?>,'<?= htmlspecialchars((string)($ur['purpose'] ?? 'genel'), ENT_QUOTES) ?>',<?= (int)$ur['slot'] ?>,'<?= htmlspecialchars((string)($ur['label'] ?? ''), ENT_QUOTES) ?>','<?= htmlspecialchars((string)$ur['api_base'], ENT_QUOTES) ?>','<?= htmlspecialchars((string)$ur['model'], ENT_QUOTES) ?>')">Düzenle</a>
          &nbsp;<a href="#" style="color:#c00" onclick="uaDel(<?= (int)$ur['id'] ?>);return false">Sil</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$uaRows): ?><tr><td colspan="8" class="muted">Henüz kişisel anahtar yok.</td></tr><?php endif; ?>
  </table>
  <form method="post" autocomplete="off" id="uaForm">
    <input type="hidden" name="act" value="userllm">
    <input type="hidden" name="rid" id="uaRid" value="0">
    <select name="uid" id="uaUid">
      <option value="">— kullanıcı seç —</option>
      <?php foreach ($uaUsers as $uu): ?><option value="<?= (int)$uu['id'] ?>">#<?= (int)$uu['id'] ?> · <?= htmlspecialchars(($uu['name'] ?: '—') . ' · ' . $uu['email']) ?></option><?php endforeach; ?>
    </select>
    <select name="purpose" id="uaPurpose">
      <option value="genel">Genel (metin/sohbet)</option>
      <option value="gorsel">Görsel &amp; PDF okuma</option>
      <option value="hepsi">Hepsi</option>
    </select>
    <input type="number" name="slot" id="uaSlot" value="0" min="0" max="99" style="width:70px" title="Sıra: 0 ilk denenir">
    <input type="text" name="u_label" id="uaLabel" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Etiket (ör. DeepSeek)">
    <input type="text" name="u_base" id="uaBase" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="API adresi (boş = genel ayar)">
    <input type="text" name="u_model" id="uaModel" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="Model (boş = genel, auto = en ucuz)">
    <input type="password" name="u_key" autocomplete="new-password" data-lpignore="true" data-form-type="other" spellcheck="false" placeholder="API anahtarı (düzenlerken boş = değişmez)">
    <button type="submit">Kaydet</button>
    <button type="button" onclick="uaReset()">Yeni / temizle</button>
  </form>
  <form method="post" id="uaDelForm" style="display:none">
    <input type="hidden" name="act" value="userllm">
    <input type="hidden" name="u_delete" value="1">
    <input type="hidden" name="rid" id="uaDelRid">
  </form>
  <script>
  function uaEdit(id, pur, slot, label, base, model) {
    document.getElementById('uaRid').value = id;
    document.getElementById('uaPurpose').value = pur || 'genel';
    document.getElementById('uaSlot').value = slot;
    document.getElementById('uaLabel').value = label || '';
    document.getElementById('uaBase').value = base || '';
    document.getElementById('uaModel').value = model || '';
  }
  function uaReset() {
    document.getElementById('uaForm').reset();
    document.getElementById('uaRid').value = 0;
  }
  function uaDel(id) {
    if (!confirm('Bu anahtar silinsin mi?')) return;
    document.getElementById('uaDelRid').value = id;
    document.getElementById('uaDelForm').submit();
  }
  </script>
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

<form method="post" id="atlasPwForm" style="display:none">
  <input type="hidden" name="act" value="resetpw">
  <input type="hidden" name="uid" id="atlasPwUid">
  <input type="hidden" name="newpass" id="atlasPwVal">
</form>
<form method="post" id="lhDelForm" style="display:none">
  <input type="hidden" name="act" value="deluser">
  <input type="hidden" name="uid" id="lhDelUid">
  <input type="hidden" name="confirm_email" id="lhDelMail">
</form>
<script>
function lhDelUser(uid, email) {
  var c = prompt('DİKKAT: ' + email + ' hesabı ve tüm verileri kalıcı olarak silinecek.\nOnaylamak için DELETE yazın:');
  if (!c) return;
  if (c.trim().toLowerCase() !== 'delete') { alert('Onay yazısı DELETE değil, silme iptal edildi.'); return; }
  document.getElementById('lhDelUid').value = uid;
  document.getElementById('lhDelMail').value = c.trim();
  document.getElementById('lhDelForm').submit();
}
function atlasResetPw(uid, email) {
  var p = prompt('Yeni şifre (' + email + ') — en az 8 karakter:');
  if (!p) return;
  if (p.length < 8) { alert('Şifre en az 8 karakter olmalı.'); return; }
  document.getElementById('atlasPwUid').value = uid;
  document.getElementById('atlasPwVal').value = p;
  document.getElementById('atlasPwForm').submit();
}
</script>

<?php atlas_footer(); ?>
