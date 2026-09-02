<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — hesabım, paketim, abonelik */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
$L = atlas_lang();
$u = require_login();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['act'] ?? '') === 'request') {
    $code = preg_replace('/[^a-z_]/', '', $_POST['plan'] ?? '');
    db()->prepare('INSERT INTO subscription_log(user_id,plan_code,action,note) VALUES(?,?,\'request\',?)')
       ->execute([$u['id'], $code, 'panelden talep']);
    @atlas_send_mail(SMTP_FROM, 'Atlas — paket talebi',
      '<p><b>' . htmlspecialchars($u['email']) . '</b> "' . htmlspecialchars($code) . '" paketini talep etti.</p>');
    $msg = t('acc_req_sent');
  } elseif (($_POST['act'] ?? '') === 'pass') {
    $new = $_POST['password'] ?? '';
    if (mb_strlen($new) >= 8) {
      db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')
         ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
      $msg = t('acc_pass_ok');
    } else { $msg = t('e_weak'); }
  }
}

$p = plan_row($u['plan_code']);
$used = usage_month((int)$u['id']);
$lim = (int)$p['monthly_commands'];
$pct = $lim > 0 ? min(100, round($used * 100 / $lim)) : 0;
$nm = fn($r) => $L === 'en' ? $r['name_en'] : $r['name_tr'];
require_once __DIR__ . '/includes/onboarding.php';
ob_mark('ob_account_seen');
atlas_header(t('acc_title'), 'account', $L==='tr' ? 'Paketiniz, kullanımınız ve hesap ayarlarınız.' : 'Your plan, usage and account settings.');
?>

  <section class="acard">
    <h3><?= t('acc_title') ?></h3>
    <?php if ($msg) echo '<div class="okmsg">'.htmlspecialchars($msg).'</div>'; ?>
    <div class="kv"><span><?= t('login_email') ?></span><b><?= htmlspecialchars($u['email']) ?></b></div>
    <div class="kv"><span><?= t('acc_plan') ?></span><b><?= htmlspecialchars($nm($p)) ?></b></div>
    <div class="kv"><span><?= t('acc_status') ?></span><b><?= htmlspecialchars($u['plan_status']) ?></b></div>
    <div class="kv"><span><?= t('acc_until') ?></span><b><?= $u['plan_until'] ? date('d.m.Y', strtotime($u['plan_until'])) : '—' ?></b></div>
    <div class="kv"><span><?= t('acc_usage') ?></span><b><?= $used ?> / <?= $lim < 0 ? t('acc_unlimited') : $lim ?></b></div>
    <?php if ($lim > 0): ?><div class="bar"><i style="width:<?= $pct ?>%"></i></div><?php endif; ?>
    <form method="post" class="pass-form">
      <input type="hidden" name="act" value="pass">
      <input type="password" name="password" placeholder="<?= t('rs_new') ?>" minlength="8" required>
      <button type="submit"><?= t('acc_change_pass') ?></button>
    </form>
  </section>

  <section class="acard">
    <h3><?= t('acc_plans') ?></h3>
    <div class="plans">
      <?php foreach (all_plans() as $pl): if ($pl['code'] === 'trial' && $u['plan_code'] !== 'trial') continue; ?>
        <div class="plan<?= $pl['code'] === $u['plan_code'] ? ' cur' : '' ?>">
          <div class="pname"><?= htmlspecialchars($nm($pl)) ?></div>
          <div class="pprice"><?= $L === 'en' ? 'Free for 3 months' : '3 ay ücretsiz' ?><small><?= $L === 'en' ? ' · no credit card' : ' · kredi kartı yok' ?></small></div>
          <ul>
            <li><?= (int)$pl['monthly_commands'] < 0 ? t('acc_unlimited') : (int)$pl['monthly_commands'] ?> <?= t('acc_cmds') ?></li>
            <li><?= (int)$pl['max_contacts'] < 0 ? t('acc_unlimited') : (int)$pl['max_contacts'] ?> <?= t('acc_contacts') ?></li>
            <li><?= $pl['wa_enabled'] ? '✓' : '—' ?> <?= t('acc_wa') ?></li>
            <li><?= $pl['gmail_enabled'] ? '✓' : '—' ?> <?= t('acc_gmail') ?></li>
          </ul>
          <?php if ($pl['code'] === $u['plan_code']): ?>
            <div class="curlbl"><?= t('acc_current') ?></div>
          <?php else: ?>
            <form method="post"><input type="hidden" name="act" value="request">
              <input type="hidden" name="plan" value="<?= htmlspecialchars($pl['code']) ?>">
              <button type="submit"><?= t('acc_choose') ?></button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

<?php atlas_footer(); ?>
