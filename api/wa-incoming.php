<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* LUNAHAN — WhatsApp köprüsünden gelen mesaj: agent'ı çalıştır, cevabı döndür. */
require_once __DIR__ . '/../includes/agent.php';
header('Content-Type: application/json; charset=utf-8');

$key = $_POST['key'] ?? '';
if (!WA_BRIDGE_KEY || !hash_equals(WA_BRIDGE_KEY, $key)) {
  http_response_code(401); echo json_encode(['error' => 'unauthorized']); exit;
}
$from = trim($_POST['from'] ?? '');
$from_jid = trim($_POST['from_jid'] ?? '');
$name = trim($_POST['from_name'] ?? '');
$text = trim($_POST['text'] ?? '');
if ($text === '') { echo json_encode(['reply' => '']); exit; }

$label = 'WhatsApp' . ($name ? ' · ' . $name : '') . ' (' . $from . ')';
/* WhatsApp yazismalari da panelde ayri bir sohbet olarak gorunsun */
/* Cok oturumlu kopru: mesaji hangi kullanicinin hatti aldiysa o kullaniciya yazilir. */
$wauid = (int)($_POST['uid'] ?? 0);
if ($wauid < 1) { echo json_encode(['reply' => '']); exit; }
atlas_set_ctx_user($wauid);

/* SAHIP KONTROLU: bu hatta sadece sahibi komut verebilir.
   - Hattin kendi numarasi (kendine mesaj) her zaman serbest.
   - Ek izinli numara sadece settings.wa_owner ile ELLE tanimlanir (ogrenme yok).
   - Diger herkes sessizce yok sayilir (musteri/tanidik bot cevabi almasin). */
$me     = preg_replace('/[^0-9]/', '', (string)($_POST['me'] ?? ''));
$sender = preg_replace('/[^0-9]/', '', (string)$from);
if ($sender === '' ) { echo json_encode(['reply' => '']); exit; }
$isSelf = (($_POST['self'] ?? '') === '1');
if (!$isSelf && ($me === '' || $sender !== $me)) {
  $ownersRaw = (string)setting('wa_owner', '');
  $owners = array_values(array_filter(array_map(function($x){ return preg_replace('/[^0-9]/','',$x); }, preg_split('/[,;\s]+/', $ownersRaw))));
  /* Varsayilan kural: SADECE hattin kendi numarasi (QR'i okutan telefon) yazabilir.
     Istisna: wa_owner ayarinda numara(lar) tanimliysa o numaralar da yazabilir (admin icin). */
  if (!$owners || !in_array($sender, $owners, true)) { echo json_encode(['reply' => '']); exit; }
}
/* --- Ortak bot yonlendirme isareti (LUNAHAN <-> Luna ayni WhatsApp hattinda) ---
   Bilgehan 2026-08-28: onek sadece BOTU DEGISTIRMEK icin gerekli. Son 10 dakika
   icinde hangi bot konustuysa oneksiz mesaj ona gider (ornek: "Yolla"). */

if (!function_exists('lunify_wa_active')) {
  function lunify_wa_active_file(): string { return '/var/tmp/lunify-wa-active-lunahan.json'; }
  function lunify_wa_active(int $ttl = 600): string {
    $f = lunify_wa_active_file();
    if (!is_file($f)) return '';
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || empty($j['bot'])) return '';
    if ((time() - (int)($j['ts'] ?? 0)) > $ttl) return '';
    return (string)$j['bot'];
  }
  function lunify_wa_claim(string $bot): void {
    @file_put_contents(lunify_wa_active_file(), json_encode(['bot' => $bot, 'ts' => time()]));
    @chmod(lunify_wa_active_file(), 0666);
  }
}

if (preg_match('/^\s*(atlas|@atlas)\b[\s,:!\-]*(.*)$/isu', $text, $mAtl)) {
  $text = trim($mAtl[2]);
  if ($text === '') $text = 'Merhaba';
  lunify_wa_claim('atlas');
} else {
  /* 2026-08-29 kullanici karari: Luna WhatsApp'tan kaldirildi, artik onek SART DEGIL.
     Hattin sahibi kendine ne yazarsa LUNAHAN cevaplar. */
  lunify_wa_claim('atlas');
}

$wtitle = 'WhatsApp' . ($name ? ' · ' . $name : ' · ' . $from);
$fc = db()->prepare('SELECT id FROM conversations WHERE user_id=? AND title=? LIMIT 1');
$fc->execute([$wauid, $wtitle]);
$conv = (int)$fc->fetchColumn();
if (!$conv) {
  db()->prepare('INSERT INTO conversations(user_id,title) VALUES(?,?)')->execute([$wauid, $wtitle]);
  $conv = (int)db()->lastInsertId();
}
atlas_conv($conv);

$ins = db()->prepare('INSERT INTO commands(user_id,conv_id,user_text,status) VALUES(?,?,?,\'running\')');
$ins->execute([$wauid, $conv, '[' . $label . '] ' . $text]);
$cid = db()->lastInsertId();

/* Viktor 2026-08-30: WhatsApp'tan paylasilan kisi kartlari (vCard) DOGRUDAN rehbere yazilir.
   Kopru bunlari "[KISI_KARTI] Ad|Telefon" satirlari olarak gonderir. */
if (strpos($text, '[KISI_KARTI]') !== false) {
  $added = []; $updated = []; $rest = [];
  $sel = db()->prepare('SELECT phone FROM contacts WHERE user_id=? AND name=? LIMIT 1');
  $ins = db()->prepare('INSERT INTO contacts(user_id,name,phone,note) VALUES(?,?,?,?)
                        ON DUPLICATE KEY UPDATE phone=VALUES(phone)');
  foreach (preg_split('/\r?\n/', $text) as $line) {
    if (strpos($line, '[KISI_KARTI]') !== 0) { if (trim($line) !== '') $rest[] = $line; continue; }
    $p = explode('|', trim(substr($line, strlen('[KISI_KARTI]'))));
    $nm = trim($p[0] ?? ''); $ph = preg_replace('/\D/', '', $p[1] ?? '');
    if ($nm === '' || strlen($ph) < 10) continue;
    $nm = mb_substr($nm, 0, 160);
    $sel->execute([$wauid, $nm]);
    $exists = $sel->fetchColumn();
    try {
      $ins->execute([$wauid, $nm, $ph, 'WhatsApp kişi kartı']);
      if ($exists === false) $added[] = $nm . ' — ' . $ph;
      else $updated[] = $nm . ' — ' . $ph;
    } catch (PDOException $e) { /* yoksay */ }
  }
  $note = '[Sistem: kullanıcı WhatsApp\'tan kişi kartı paylaştı ve kayıtlar rehbere GERÇEKTEN yazıldı.]';
  if ($added)   $note .= "\n[Yeni eklenenler: " . implode('; ', $added) . ']';
  if ($updated) $note .= "\n[Numarası güncellenenler: " . implode('; ', $updated) . ']';
  if (!$added && !$updated) $note = '[Sistem: kişi kartı okunamadı, rehbere hiçbir kayıt yazılmadı.]';
  $note .= "\n[Kullanıcıya bunu kısaca onayla; add_contact aracını TEKRAR çağırma.]";
  $text = trim($note . ($rest ? "\n[Kullanıcının notu: " . implode(' ', $rest) . ']' : ''));
}

$out = atlas_process($text, 'tr', (int)$wauid);

$upd = db()->prepare('UPDATE commands SET agent_reply=?, tools_json=?, status=\'done\' WHERE id=?');
$upd->execute([$out['reply'], json_encode($out['tools'], JSON_UNESCAPED_UNICODE), $cid]);
db()->prepare('UPDATE conversations SET updated_at=NOW() WHERE id=?')->execute([$conv]);

lunify_wa_claim('atlas');
echo json_encode(['reply' => $out['reply']], JSON_UNESCAPED_UNICODE);
