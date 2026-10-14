<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gmail.php';
header('Content-Type: application/json; charset=utf-8');
$rawDecision = $_POST['decision'] ?? null;
if (!is_string($rawDecision) || !in_array($rawDecision, ['approve', 'reject'], true)) {
  http_response_code(400);
  echo json_encode(['error'=>'geçersiz karar'], JSON_UNESCAPED_UNICODE);
  exit;
}
$decision = $rawDecision;
$u = require_login_api();

$id = (int)($_POST['id'] ?? 0);
$s = db()->prepare('SELECT * FROM actions WHERE id=? AND user_id=?'); $s->execute([$id, $u['id']]); $act = $s->fetch();
if (!$act) { echo json_encode(['error'=>'bulunamadı']); exit; }

if ($decision === 'reject') {
  db()->prepare('UPDATE actions SET status=\'rejected\' WHERE id=? AND user_id=?')->execute([$id, $u['id']]);
  echo json_encode(['ok'=>true,'status'=>'rejected']); exit;
}

// approve → gerçek gönderim (köprüler bağlanınca). Şimdilik: call için tel: linki, diğerleri hazır bekliyor.
$meta = json_decode($act['meta_json'] ?? '{}', true) ?: [];
$result = ''; $status = 'approved';

if ($act['type'] === 'call') {
  $phone = preg_replace('/[^0-9+]/', '', $act['target'] ?? '');
  $result = 'tel:' . $phone;
  $status = 'sent';
} elseif ($act['type'] === 'whatsapp') {
  if (WA_BRIDGE_URL && $act['target']) {
    $num = preg_replace('/[^0-9]/', '', $act['target']);
    $ch = curl_init(rtrim(WA_BRIDGE_URL,'/') . '/send');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode(['key'=>WA_BRIDGE_KEY,'uid'=>(int)$u['id'],'to'=>$num,'message'=>$act['payload']])]);
    $resp = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) {
      $status = 'failed';
      $result = 'Köprüye ulaşılamadı' . ($cerr ? ' (' . $cerr . ')' : '');
    } elseif (!empty($j['ok'])) {
      $status = 'sent';
      $result = 'Gönderildi' . (!empty($j['id']) ? ' (' . $j['id'] . ')' : '');
    } else {
      $status = 'failed';
      $result = $j['error'] ?? 'Gönderilemedi';
    }
  } else {
    $result = 'WhatsApp köprüsü henüz bağlı değil — numara/QR bağlanınca gidecek.';
    $status = 'approved';
  }
} elseif ($act['type'] === 'email') {
  $to = trim($act['target'] ?? '');
  if (!$to && !empty($meta['email'])) $to = $meta['email'];
  if (gmail_ready() && $to) {
    [$ok, $m] = gmail_send($to, $meta['subject'] ?? 'Mesaj', (string)$act['payload']);
    $result = $m; $status = $ok ? 'sent' : 'failed';
  } elseif (!$to) {
    $result = 'Alıcı e-posta adresi yok.'; $status = 'failed';
  } else {
    $result = 'Gmail henüz bağlı değil — panelden Gmail sayfasından bağla.'; $status = 'approved';
  }
}
db()->prepare('UPDATE actions SET status=?, result=? WHERE id=? AND user_id=?')->execute([$status,$result,$id,$u['id']]);
echo json_encode(['ok'=>true,'status'=>$status,'result'=>$result], JSON_UNESCAPED_UNICODE);
