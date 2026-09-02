<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* Atlas — Google Kişiler (People API) entegrasyonu.
   Amaç: Atlas rehberine eklenen kişiyi kullanıcının GOOGLE hesabına da yazmak;
   böylece kişi Android/iPhone'daki gerçek telefon rehberinde otomatik görünür.
   Viktor 2026-08-30. */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google.php';

/** Bağlantıda Google Kişiler izni var mı? */
function gcontacts_ready(): bool {
  if (!function_exists('gmail_ready') || !gmail_ready()) return false;
  $sc = (string)gmail_cfg('scopes');
  if ($sc === '') return false;
  return strpos($sc, 'auth/contacts') !== false;
}

function gcontacts_api(string $path, array $body = null, string $method = 'GET') {
  $tok = gmail_token();
  if (!$tok) return ['error'=>['message'=>'Google yetkisi yok']];
  $ch = curl_init('https://people.googleapis.com/v1' . $path);
  $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40, CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok, 'Content-Type: application/json']];
  if ($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true) ?: ['error'=>['message'=>'Google Kişiler yanıt vermedi']];
}

/** Telefonu TR formatına normalize eder (05xx -> 905xx). */
function gcontacts_phone(string $p): string {
  $d = preg_replace('/[^0-9]/', '', $p);
  if ($d === '') return '';
  if (strlen($d) === 10 && $d[0] === '5') $d = '90' . $d;
  elseif (strlen($d) === 11 && substr($d, 0, 2) === '05') $d = '90' . substr($d, 1);
  return $d;
}

/** Aynı numara Google hesabında zaten var mı? (resourceName döner) */
function gcontacts_find(string $phone): string {
  $phone = gcontacts_phone($phone);
  if ($phone === '') return '';
  $tail = substr($phone, -9);
  gcontacts_api('/people:searchContacts?query=&readMask=names');   // warmup (Google şartı)
  $r = gcontacts_api('/people:searchContacts?' . http_build_query([
    'query'=>$tail, 'readMask'=>'names,phoneNumbers', 'pageSize'=>10]));
  foreach (($r['results'] ?? []) as $it) {
    foreach (($it['person']['phoneNumbers'] ?? []) as $pn) {
      $d = preg_replace('/[^0-9]/', '', (string)($pn['value'] ?? ''));
      if ($d !== '' && substr($d, -9) === $tail) return (string)($it['person']['resourceName'] ?? '');
    }
  }
  return '';
}

/** Google hesabına kişi yazar. Dönüş: ['ok'=>bool,'msg'=>string] */
function gcontacts_add(string $name, string $phone = '', string $email = ''): array {
  if (!gcontacts_ready()) return ['ok'=>false, 'msg'=>'no_scope'];
  $name = trim($name);
  if ($name === '' && $phone === '') return ['ok'=>false, 'msg'=>'isim veya numara gerekli'];
  $phone = gcontacts_phone($phone);
  $existing = $phone !== '' ? gcontacts_find($phone) : '';
  if ($existing !== '') return ['ok'=>true, 'msg'=>'zaten Google Kişiler\'de kayıtlı', 'resource'=>$existing, 'dup'=>true];
  $parts = preg_split('/\s+/u', $name, 2);
  $body = ['names'=>[['givenName'=>$parts[0] ?? $name, 'familyName'=>$parts[1] ?? '']]];
  if ($phone !== '') $body['phoneNumbers'] = [['value'=>'+' . $phone]];
  if ($email !== '') $body['emailAddresses'] = [['value'=>$email]];
  $r = gcontacts_api('/people:createContact?personFields=names,phoneNumbers', $body, 'POST');
  if (!empty($r['error'])) {
    $m = (string)($r['error']['message'] ?? 'hata');
    if (stripos($m, 'insufficient') !== false || stripos($m, 'scope') !== false) return ['ok'=>false, 'msg'=>'no_scope'];
    if (stripos($m, 'has not been used') !== false || stripos($m, 'disabled') !== false)
      return ['ok'=>false, 'msg'=>'People API kapalı (Google Cloud projesinde açılmalı)'];
    return ['ok'=>false, 'msg'=>$m];
  }
  return ['ok'=>true, 'msg'=>'Google Kişiler\'e eklendi', 'resource'=>(string)($r['resourceName'] ?? '')];
}

/** Toplu ekleme (en fazla 200/istek). Dönüş: ['ok'=>int,'fail'=>int,'msg'=>string] */
function gcontacts_add_many(array $rows): array {
  if (!gcontacts_ready()) return ['ok'=>0, 'fail'=>count($rows), 'msg'=>'no_scope'];
  $ok = 0; $fail = 0; $err = '';
  foreach (array_chunk($rows, 100) as $chunk) {
    $items = [];
    foreach ($chunk as $c) {
      $name = trim((string)($c['name'] ?? ''));
      $phone = gcontacts_phone((string)($c['phone'] ?? ''));
      if ($name === '' && $phone === '') { $fail++; continue; }
      $parts = preg_split('/\s+/u', $name !== '' ? $name : $phone, 2);
      $p = ['names'=>[['givenName'=>$parts[0], 'familyName'=>$parts[1] ?? '']]];
      if ($phone !== '') $p['phoneNumbers'] = [['value'=>'+' . $phone]];
      if (!empty($c['email'])) $p['emailAddresses'] = [['value'=>(string)$c['email']]];
      $items[] = ['contactPerson'=>$p];
    }
    if (!$items) continue;
    $r = gcontacts_api('/people:batchCreateContacts', ['contacts'=>$items, 'readMask'=>'names'], 'POST');
    if (!empty($r['error'])) { $fail += count($items); $err = (string)($r['error']['message'] ?? ''); continue; }
    $ok += count($r['createdPeople'] ?? $items);
  }
  return ['ok'=>$ok, 'fail'=>$fail, 'msg'=>$err];
}

/** Kişi listesinden .vcf (vCard 3.0) metni üretir. */
function gcontacts_vcf(array $rows): string {
  $out = '';
  foreach ($rows as $c) {
    $name = trim((string)($c['name'] ?? ''));
    $phone = gcontacts_phone((string)($c['phone'] ?? ''));
    if ($name === '' && $phone === '') continue;
    if ($name === '') $name = '+' . $phone;
    $parts = preg_split('/\s+/u', $name, 2);
    $out .= "BEGIN:VCARD\r\nVERSION:3.0\r\n";
    $out .= 'N:' . ($parts[1] ?? '') . ';' . $parts[0] . ";;;\r\n";
    $out .= 'FN:' . $name . "\r\n";
    if ($phone !== '') $out .= 'TEL;type=CELL;waid=' . $phone . ':+' . $phone . "\r\n";
    if (!empty($c['email'])) $out .= 'EMAIL;type=INTERNET:' . $c['email'] . "\r\n";
    $out .= "END:VCARD\r\n";
  }
  return $out;
}
