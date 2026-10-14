<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* LUNAHAN — hatirlatma/takip gonderici (systemd timer: atlas-notify, 5 dakikada bir).
   Zamani gelen hatirlatmalari ve emlak takiplerini kullanicinin KENDI WhatsApp hattina yazar. */
require_once '/opt/lunify/lunahan/includes/db.php';
date_default_timezone_set('Europe/Istanbul');

function nt_bridge_me(int $uid): ?string {
  $url = rtrim(WA_BRIDGE_URL, '/') . '/status?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . $uid;
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>15,
    CURLOPT_HTTPHEADER=>['X-Bridge-Key: ' . WA_BRIDGE_KEY]]);
  $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
  $me = (string)($j['me'] ?? $j['number'] ?? '');
  $me = preg_replace('/[^0-9]/', '', $me);
  return $me !== '' ? $me : null;
}

function nt_send(int $uid, string $to, string $msg): bool {
  $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . '/send');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>30,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['key'=>WA_BRIDGE_KEY,'uid'=>$uid,'to'=>$to,'message'=>$msg], JSON_UNESCAPED_UNICODE)]);
  $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
  return !empty($j['ok']);
}

$now = date('Y-m-d H:i:s');
$sent = 0;

/* 1) Zamani gelen genel hatirlatmalar */
$q = db()->prepare("SELECT id,user_id,body,due_at FROM reminders WHERE status='open' AND due_at IS NOT NULL AND due_at <= ? ORDER BY due_at LIMIT 50");
$q->execute([$now]);
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $uid = (int)$r['user_id'];
  $me = nt_bridge_me($uid);
  if (!$me) continue;                                  // hat bagli degil: bir sonraki turda dene
  $ok = nt_send($uid, $me, "⏰ Hatırlatma\n" . $r['body']);
  if ($ok) {
    db()->prepare("UPDATE reminders SET status='done' WHERE id=?")->execute([$r['id']]);
    $sent++;
  }
}

/* 2) Zamani gelen emlak takipleri */
try {
  $q2 = db()->prepare("SELECT l.id,l.user_id,l.name,l.phone,l.budget_max,l.rooms,l.location,l.deal
                       FROM re_leads l WHERE l.status='aktif' AND l.next_followup_at IS NOT NULL AND l.next_followup_at <= ? LIMIT 50");
  $q2->execute([$now]);
  foreach ($q2->fetchAll(PDO::FETCH_ASSOC) as $L) {
    $uid = (int)$L['user_id'];
    $me = nt_bridge_me($uid);
    if (!$me) continue;
    /* uyan portfoyu de ekle */
    $ls = db()->prepare("SELECT title,rooms,location,price FROM re_listings WHERE user_id=? AND status='aktif' AND deal=? AND (price IS NULL OR ? IS NULL OR price <= ?) ORDER BY id DESC LIMIT 3");
    $ls->execute([$uid, $L['deal'], $L['budget_max'], $L['budget_max']]);
    $lines = '';
    foreach ($ls->fetchAll(PDO::FETCH_ASSOC) as $x) {
      $lines .= "\n• " . $x['title'] . ($x['price'] ? ' — ' . number_format((float)$x['price'], 0, ',', '.') . ' TL' : '');
    }
    $msg = "📞 Müşteri takibi: *" . $L['name'] . "*" . ($L['phone'] ? " (" . $L['phone'] . ")" : '')
         . "\nAradığı: " . ($L['rooms'] ?: '-') . ' ' . ($L['location'] ?: '') . ' / ' . $L['deal']
         . ($L['budget_max'] ? ' — bütçe ' . number_format((float)$L['budget_max'], 0, ',', '.') . ' TL' : '')
         . ($lines ? "\n\nGösterebileceğin portföy:" . $lines : '')
         . "\n\nAramak ister misin? \"" . $L['name'] . "'e mesaj at\" diye yazabilirsin.";
    if (nt_send($uid, $me, $msg)) {
      db()->prepare("UPDATE re_leads SET next_followup_at=NULL WHERE id=?")->execute([$L['id']]);
      $sent++;
    }
  }
} catch (Throwable $e) { /* emlak modulu tablosu yoksa sessiz gec */ }

echo date('c') . " atlas-notify: gonderilen=$sent\n";
