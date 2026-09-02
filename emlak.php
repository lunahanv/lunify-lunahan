<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — Emlak modülü paneli: portföy + müşteri talepleri + eşleşmeler */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
require_once __DIR__ . '/includes/when.php';
require_once __DIR__ . '/includes/agent.php';
atlas_session();
$L = atlas_lang();
$U = require_login();
if (!re_enabled((int)$U['id'])) { header('Location: app.php?lang=' . $L); exit; }

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $act = $_POST['act'] ?? '';
  if ($act === 'listing_status' && !empty($_POST['id'])) {
    $msg = re_tool_update_listing(['id' => (int)$_POST['id'], 'status' => (string)($_POST['status'] ?? 'aktif')]);
  } elseif ($act === 'lead_status' && !empty($_POST['id'])) {
    $msg = re_tool_update_lead(['id' => (int)$_POST['id'], 'status' => (string)($_POST['status'] ?? 'aktif')]);
  } elseif ($act === 'del_listing' && !empty($_POST['id'])) {
    $s = db()->prepare('DELETE FROM re_listings WHERE id=? AND user_id=?'); $s->execute([(int)$_POST['id'], (int)$U['id']]);
    $msg = 'Portföy kaydı silindi.';
  } elseif ($act === 'del_lead' && !empty($_POST['id'])) {
    $s = db()->prepare('DELETE FROM re_leads WHERE id=? AND user_id=?'); $s->execute([(int)$_POST['id'], (int)$U['id']]);
    $msg = 'Müşteri kaydı silindi.';
  }
}

$uid = (int)$U['id'];
$ls = db()->prepare('SELECT * FROM re_listings WHERE user_id=? ORDER BY FIELD(status,\'aktif\') DESC, id DESC LIMIT 200');
$ls->execute([$uid]); $listings = $ls->fetchAll(PDO::FETCH_ASSOC);
$le = db()->prepare('SELECT * FROM re_leads WHERE user_id=? ORDER BY FIELD(status,\'aktif\') DESC, id DESC LIMIT 200');
$le->execute([$uid]); $leads = $le->fetchAll(PDO::FETCH_ASSOC);
$tl = function ($v) { return $v === null ? '—' : number_format((float)$v, 0, ',', '.') . ' TL'; };

atlas_header('Emlak', 'emlak', $L === 'tr'
  ? 'Portföyün ve müşteri taleplerin. Kayıt eklemek için sohbete ya da WhatsApp’a yazman/sesli söylemen yeterli — Atlas buraya işler.'
  : 'Your portfolio and client requests.');
?>
<?php if ($msg): ?><section class="acard"><p><?= htmlspecialchars($msg) ?></p></section><?php endif; ?>

<section class="acard">
  <h3>Bugünün özeti</h3>
  <?php
    $now = date('Y-m-d H:i:s'); $p1 = date('Y-m-d H:i:s', strtotime('+1 day'));
    $c1 = db()->prepare("SELECT COUNT(*) FROM re_listings WHERE user_id=? AND status='aktif'"); $c1->execute([$uid]);
    $c2 = db()->prepare("SELECT COUNT(*) FROM re_leads WHERE user_id=? AND status='aktif'"); $c2->execute([$uid]);
    $c3 = db()->prepare("SELECT COUNT(*) FROM re_leads WHERE user_id=? AND status='aktif' AND next_followup_at IS NOT NULL AND next_followup_at <= ?"); $c3->execute([$uid, $p1]);
  ?>
  <p class="muted">Aktif portföy: <b><?= (int)$c1->fetchColumn() ?></b> · Aktif müşteri: <b><?= (int)$c2->fetchColumn() ?></b> · 24 saat içinde takip: <b><?= (int)$c3->fetchColumn() ?></b></p>
</section>

<section class="acard">
  <h3>Portföy (<?= count($listings) ?>)</h3>
  <?php if (!$listings): ?><p class="muted">Henüz kayıt yok. WhatsApp’tan “Portföye ekle: Kadıköy’de 3+1 satılık daire, 5 milyon 800 bin…” diye yazman ya da sesli söylemen yeterli.</p><?php endif; ?>
  <?php foreach ($listings as $r): $m = re_match_for_listing_rows((int)$r['id'], 3); ?>
    <div class="list" style="border-bottom:1px solid rgba(0,0,0,.07);padding:10px 0">
      <div><b>#<?= (int)$r['id'] ?> · <?= htmlspecialchars($r['title']) ?></b>
        <span class="muted">· <?= htmlspecialchars($r['deal']) ?> · <?= htmlspecialchars((string)$r['rooms']) ?>
        <?= $r['area_m2'] ? ' · ' . (int)$r['area_m2'] . ' m²' : '' ?> · <?= $tl($r['price']) ?>
        <?= $r['status'] !== 'aktif' ? ' · <b>' . htmlspecialchars($r['status']) . '</b>' : '' ?></span></div>
      <div class="muted"><?= htmlspecialchars((string)$r['location']) ?>
        <?= $r['owner_name'] ? ' · sahibi: ' . htmlspecialchars($r['owner_name']) . ' ' . htmlspecialchars((string)$r['owner_phone']) : '' ?>
        <?= $r['features'] ? ' · ' . htmlspecialchars($r['features']) : '' ?></div>
      <?php if ($m): ?><div class="muted">🤝 uyan müşteri: <?php
        echo htmlspecialchars(implode(', ', array_map(fn($x) => $x['name'] . ' (' . $x['skor'] . ')', $m))); ?></div><?php endif; ?>
      <form method="post" style="margin-top:6px;display:inline-flex;gap:6px;align-items:center;vertical-align:middle">
        <input type="hidden" name="act" value="listing_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="status">
          <?php foreach (['aktif','satildi','kiralandi','pasif'] as $st): ?>
          <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Durumu kaydet</button>
      </form>
      <form method="post" onsubmit="return confirm('Bu portföy kaydı silinsin mi?')" style="margin-top:6px;display:inline-flex;margin-left:8px;vertical-align:middle">
        <input type="hidden" name="act" value="del_listing"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn" type="submit">Sil</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<section class="acard">
  <h3>Müşteri talepleri (<?= count($leads) ?>)</h3>
  <?php if (!$leads): ?><p class="muted">Henüz müşteri talebi yok. “Ahmet Bey Kadıköy’de 3+1 arıyor, bütçesi 6 milyon” demen yeterli.</p><?php endif; ?>
  <?php foreach ($leads as $r): $m = re_match_for_lead_rows((int)$r['id'], 3); ?>
    <div class="list" style="border-bottom:1px solid rgba(0,0,0,.07);padding:10px 0">
      <div><b>#<?= (int)$r['id'] ?> · <?= htmlspecialchars($r['name']) ?></b>
        <span class="muted">· <?= htmlspecialchars((string)$r['phone']) ?> · <?= htmlspecialchars($r['deal']) ?> ·
        <?= htmlspecialchars((string)$r['rooms']) ?> · <?= htmlspecialchars((string)$r['location']) ?> · bütçe <?= $tl($r['budget_max']) ?>
        <?= $r['status'] !== 'aktif' ? ' · <b>kapalı</b>' : '' ?></span></div>
      <div class="muted">Takip: <?= $r['next_followup_at'] ? date('d.m.Y H:i', strtotime($r['next_followup_at'])) : 'kurulmadı' ?></div>
      <?php if ($m): ?><div class="muted">🏠 uyan portföy: <?php
        echo htmlspecialchars(implode(', ', array_map(fn($x) => '#' . $x['id'] . ' ' . $x['title'] . ' (' . $x['skor'] . ')', $m))); ?></div><?php endif; ?>
      <form method="post" style="margin-top:6px;display:inline-flex;gap:6px;align-items:center;vertical-align:middle">
        <input type="hidden" name="act" value="lead_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <select name="status">
          <option value="aktif" <?= $r['status'] === 'aktif' ? 'selected' : '' ?>>aktif</option>
          <option value="kapali" <?= $r['status'] !== 'aktif' ? 'selected' : '' ?>>kapalı</option>
        </select>
        <button class="btn" type="submit">Durumu kaydet</button>
      </form>
      <form method="post" onsubmit="return confirm('Bu müşteri kaydı silinsin mi?')" style="margin-top:6px;display:inline-flex;margin-left:8px;vertical-align:middle">
        <input type="hidden" name="act" value="del_lead"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn" type="submit">Sil</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php atlas_footer(); ?>
