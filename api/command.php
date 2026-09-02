<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

require_once __DIR__ . '/../includes/agent.php';
require_once __DIR__ . '/../includes/i18n.php';
header('Content-Type: application/json; charset=utf-8');
$u = require_login_api();

$q = quota_check($u);
if (!$q['ok']) {
  $msg = [
    'quota'    => t('q_quota'),
    'expired'  => t('q_expired'),
    'inactive' => t('q_inactive'),
    'suspended'=> t('q_suspended'),
  ][$q['err']] ?? t('q_inactive');
  echo json_encode(['error'=>$msg, 'code'=>$q['err'], 'upgrade'=>'account.php'], JSON_UNESCAPED_UNICODE);
  exit;
}

$text = trim($_POST['text'] ?? '');
if ($text === '') { echo json_encode(['error'=>'boş komut']); exit; }

/* Aktif sohbet (konusma). Yoksa ilk mesajdan baslik uretip yeni sohbet ac. */
$conv = (int)($_POST['conv'] ?? 0);
$convNew = false;
if ($conv) {
  $chk = db()->prepare('SELECT 1 FROM conversations WHERE id=? AND user_id=?');
  $chk->execute([$conv, $u['id']]);
  if (!$chk->fetchColumn()) $conv = 0;
}
if (!$conv) {
  $title = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 60);
  $cn = db()->prepare('INSERT INTO conversations(user_id,title) VALUES(?,?)');
  $cn->execute([$u['id'], $title !== '' ? $title : 'Yeni sohbet']);
  $conv = (int)db()->lastInsertId();
  $convNew = true;
}
atlas_conv($conv);

$ins = db()->prepare('INSERT INTO commands(user_id,conv_id,user_text,status) VALUES(?,?,?,\'running\')');
$ins->execute([$u['id'], $conv, $text]);
$cid = db()->lastInsertId();

$out = atlas_process($text, atlas_lang());

$upd = db()->prepare('UPDATE commands SET agent_reply=?, tools_json=?, status=\'done\' WHERE id=? AND user_id=?');
$upd->execute([$out['reply'], json_encode($out['tools'], JSON_UNESCAPED_UNICODE), $cid, $u['id']]);

db()->prepare('UPDATE conversations SET updated_at=NOW() WHERE id=? AND user_id=?')->execute([$conv, $u['id']]);
$ctitle = db()->prepare('SELECT title FROM conversations WHERE id=?');
$ctitle->execute([$conv]);

$p = plan_row($u['plan_code']);
echo json_encode(['id'=>$cid, 'conv'=>$conv, 'conv_new'=>$convNew, 'conv_title'=>(string)$ctitle->fetchColumn(), 'reply'=>$out['reply'], 'tools'=>$out['tools'],
  'usage'=>['used'=>usage_month((int)$u['id']), 'limit'=>(int)$p['monthly_commands']]], JSON_UNESCAPED_UNICODE);
