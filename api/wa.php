<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// LUNAHAN — WhatsApp köprüsü durum/çıkış vekili (panel için)
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$u = require_login_api();

function wa_call(string $path, string $method = 'GET'): array {
  if (!WA_BRIDGE_URL) return ['status' => 'disabled'];
  $url = rtrim(WA_BRIDGE_URL, '/') . $path . '?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . atlas_uid();
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_HTTPHEADER     => ['X-Bridge-Key: ' . WA_BRIDGE_KEY],
  ]);
  if ($method === 'POST') curl_setopt($ch, CURLOPT_POST, true);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['status' => 'offline', 'error' => $err];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : ['status' => 'offline'];
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
if (in_array($action, ['contacts','import_contacts','logout'], true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['error'=>'method not allowed']); exit; }

/** WhatsApp rehberini köprüden çeker. */
function wa_contacts(): array {
  $r = wa_call('/contacts');
  return is_array($r['contacts'] ?? null) ? $r['contacts'] : [];
}

if ($action === 'contacts') {
  wa_call('/sync');
  $list = wa_contacts();
  echo json_encode(['ok' => true, 'count' => count($list), 'contacts' => array_slice($list, 0, 500)], JSON_UNESCAPED_UNICODE);
  exit;
}

/** MySQL utf8mb4_general_ci ile ayni sekilde normalize eder (c=ç, i=ı/İ, s=ş ...).
    Aksi halde "Elektirikci" ve "Elektirikçi" PHP'de farkli, MySQL'de AYNI sayilip
    unique index (uq_user_name) hatasi veriyordu. Viktor 2026-08-30 */
function atlas_name_key(string $s): string {
  $s = trim($s);
  $map = ['ç'=>'c','Ç'=>'c','ğ'=>'g','Ğ'=>'g','ı'=>'i','I'=>'i','İ'=>'i','ö'=>'o','Ö'=>'o',
          'ş'=>'s','Ş'=>'s','ü'=>'u','Ü'=>'u','â'=>'a','Â'=>'a','î'=>'i','û'=>'u'];
  $s = strtr($s, $map);
  $s = preg_replace('/\s+/u', ' ', $s);
  return mb_strtolower($s, 'UTF-8');
}

if ($action === 'import_contacts') {
  $uid  = atlas_uid();
  wa_call('/sync');           // once hattan rehberi tazele
  $list = wa_contacts();
  $have = [];      // mevcut telefonlar
  $names = [];     // mevcut isimler (uq_user_name cakismasini onlemek icin)
  $st = db()->prepare('SELECT name, phone FROM contacts WHERE user_id=?');
  $st->execute([$uid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $n = preg_replace('/[^0-9]/', '', (string)$row['phone']);
    if ($n !== '') $have[$n] = true;
    $nm = atlas_name_key((string)$row['name']);
    if ($nm !== '') $names[$nm] = true;
  }
  $ins = db()->prepare('INSERT INTO contacts(user_id,name,phone,note) VALUES(?,?,?,?)');
  $added = 0; $skipped = 0; $noname = 0; $renamed = 0; $failed = 0;
  foreach ($list as $c) {
    $phone = preg_replace('/[^0-9]/', '', (string)($c['phone'] ?? ''));
    $name  = trim((string)($c['name'] ?? ''));
    if ($phone === '' || isset($have[$phone])) { $skipped++; continue; }
    if ($name === '') { $name = '+' . $phone; $noname++; }
    $name = mb_substr($name, 0, 160);
    // ayni isim baska bir numarayla zaten varsa: son 4 haneyi ekleyerek benzersizlestir
    if (isset($names[atlas_name_key($name)])) {
      $base = $name . ' (' . substr($phone, -4) . ')';
      $cand = $base; $i = 2;
      while (isset($names[atlas_name_key($cand)]) && $i < 50) { $cand = $base . ' ' . $i; $i++; }
      $name = mb_substr($cand, 0, 180);
      $renamed++;
    }
    try {
      $ins->execute([$uid, $name, $phone, 'WhatsApp rehberinden']);
      $names[atlas_name_key($name)] = true;
      $have[$phone] = true;
      $added++;
    } catch (PDOException $e) {
      // isim cakismasi: numarayi ekleyerek TEKRAR dene, kisi kaybolmasin
      $alt = mb_substr($name . ' (' . substr($phone, -4) . ')', 0, 180);
      try {
        $ins->execute([$uid, $alt, $phone, 'WhatsApp rehberinden']);
        $names[atlas_name_key($alt)] = true;
        $have[$phone] = true;
        $added++; $renamed++;
      } catch (PDOException $e2) {
        $failed++;
        error_log('atlas import_contacts skip: ' . $e2->getMessage());
      }
    }
  }
  echo json_encode(['ok' => true, 'found' => count($list), 'added' => $added,
                    'skipped' => $skipped, 'unnamed' => $noname,
                    'renamed' => $renamed, 'failed' => $failed], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($action === 'logout') {
  echo json_encode(wa_call('/logout'), JSON_UNESCAPED_UNICODE);
  exit;
}
echo json_encode(wa_call('/status?init=1'), JSON_UNESCAPED_UNICODE);
