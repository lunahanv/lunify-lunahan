<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* LUNAHAN — Google Drive (Gmail ile ayni OAuth baglantisini kullanir). */
require_once __DIR__ . '/gmail.php';
require_once __DIR__ . '/google.php';

define('GDRIVE_STT_KEY', '20c992df9d8da51c27db80f93bc794d7');

function gdrive_scope_hint(string $err): string {
  if (stripos($err, 'insufficient') !== false || stripos($err, 'scope') !== false
      || stripos($err, 'permission') !== false || stripos($err, 'ACCESS_TOKEN_SCOPE') !== false) {
    return 'Drive izni yok. Kullaniciya soyle: panelden Google sayfasina girip "Google ile baglan" ile baglantiyi bir kez yenilemesi gerekiyor (Drive izni yeni eklendi).';
  }
  return '';
}

/** Drive API cagrisi (JSON). */
function gdrive_api(string $path, array $body = null, string $method = 'GET') {
  $url = 'https://www.googleapis.com/drive/v3/' . ltrim($path, '/');
  return google_api($url, $body, $method);
}

/** Ham indirme (binary). Donus: [icerik, mime] ya da [null, hata]. */
function gdrive_download(string $url): array {
  $tok = gmail_token();
  if (!$tok) return [null, 'Google yetkisi yok'];
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>90,
    CURLOPT_FOLLOWLOCATION=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok]]);
  $r = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);
  if ($code !== 200) {
    $j = json_decode((string)$r, true);
    $msg = $j['error']['message'] ?? ('HTTP ' . $code);
    return [null, $msg];
  }
  return [(string)$r, $mime];
}

/** Yerel yardimci servise dosya gonderip metin alir (pdf/ocr/vision). */
function gdrive_extract(string $endpoint, string $bytes, string $filename): string {
  $tmp = tempnam(sys_get_temp_dir(), 'gd');
  file_put_contents($tmp, $bytes);
  $ch = curl_init('http://127.0.0.1:3010/' . $endpoint);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>120, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['X-Stt-Key: ' . GDRIVE_STT_KEY],
    CURLOPT_POSTFIELDS=>['file'=>new CURLFile($tmp, 'application/octet-stream', $filename)]]);
  $r = curl_exec($ch); curl_close($ch); @unlink($tmp);
  $j = json_decode((string)$r, true);
  if (!is_array($j)) return '';
  foreach (['text', 'result', 'content', 'description'] as $k) {
    if (!empty($j[$k]) && is_string($j[$k])) return $j[$k];
  }
  return '';
}

function gdrive_size(?int $b): string {
  if (!$b) return '';
  $u = ['B','KB','MB','GB']; $i = 0; $v = (float)$b;
  while ($v >= 1024 && $i < 3) { $v /= 1024; $i++; }
  return round($v, $v < 10 ? 1 : 0) . $u[$i];
}

function gdrive_kind(string $mime): string {
  $map = [
    'application/vnd.google-apps.document'=>'Google Dokuman',
    'application/vnd.google-apps.spreadsheet'=>'Google E-tablo',
    'application/vnd.google-apps.presentation'=>'Google Sunum',
    'application/vnd.google-apps.form'=>'Google Form',
    'application/vnd.google-apps.folder'=>'Klasor',
    'application/pdf'=>'PDF',
  ];
  if (isset($map[$mime])) return $map[$mime];
  if (strpos($mime, 'image/') === 0) return 'Gorsel';
  if (strpos($mime, 'video/') === 0) return 'Video';
  if (strpos($mime, 'audio/') === 0) return 'Ses';
  return $mime;
}

/** Dosya/klasor arama. */
function gdrive_search(string $query = '', string $type = '', int $limit = 15, string $folder = ''): string {
  if (!gmail_ready()) return 'Google bagli degil. Panelden Google sayfasindan baglan.';
  $conds = ['trashed = false'];
  $q = trim($query);
  if ($q !== '') {
    $esc = str_replace("'", "\\'", $q);
    $conds[] = "(name contains '$esc' or fullText contains '$esc')";
  }
  $type = strtolower(trim($type));
  $tmap = [
    'folder'=>'application/vnd.google-apps.folder',
    'doc'=>'application/vnd.google-apps.document',
    'sheet'=>'application/vnd.google-apps.spreadsheet',
    'slide'=>'application/vnd.google-apps.presentation',
    'pdf'=>'application/pdf',
  ];
  if (isset($tmap[$type])) $conds[] = "mimeType = '" . $tmap[$type] . "'";
  elseif ($type === 'image') $conds[] = "mimeType contains 'image/'";
  elseif ($type === 'video') $conds[] = "mimeType contains 'video/'";
  if ($folder !== '') {
    $fid = gdrive_folder_id($folder);
    if ($fid === '') return 'Klasor bulunamadi: ' . $folder;
    $conds[] = "'" . $fid . "' in parents";
  }
  $r = gdrive_api('files?' . http_build_query([
    'q' => implode(' and ', $conds),
    'pageSize' => max(1, min(50, $limit)),
    'orderBy' => 'modifiedTime desc',
    'fields' => 'files(id,name,mimeType,size,modifiedTime,webViewLink,owners(emailAddress))',
    'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
  ]));
  if (!empty($r['error'])) {
    $m = $r['error']['message'];
    return 'Drive hatasi: ' . $m . ' ' . gdrive_scope_hint($m);
  }
  if (empty($r['files'])) return 'Drive\'da eslesen dosya yok.';
  $out = [];
  foreach ($r['files'] as $f) {
    $out[] = '- ' . $f['name'] . ' | ' . gdrive_kind($f['mimeType'])
      . (!empty($f['size']) ? ' | ' . gdrive_size((int)$f['size']) : '')
      . ' | ' . date('d.m.Y H:i', strtotime($f['modifiedTime'] ?? 'now'))
      . ' | id: ' . $f['id']
      . (!empty($f['webViewLink']) ? ' | ' . $f['webViewLink'] : '');
  }
  return "Drive dosyalari (en yeni once):\n" . implode("\n", $out);
}

function gdrive_meta(string $id): array {
  return gdrive_api('files/' . rawurlencode($id) . '?' . http_build_query([
    'fields' => 'id,name,mimeType,size,modifiedTime,webViewLink,parents',
    'supportsAllDrives' => 'true']));
}

/** Isimle klasor id bulur (yoksa bos). */
function gdrive_folder_id(string $name): string {
  $name = trim($name);
  if ($name === '') return '';
  if (preg_match('/^[A-Za-z0-9_\-]{20,}$/', $name)) return $name;
  $esc = str_replace("'", "\\'", $name);
  $r = gdrive_api('files?' . http_build_query([
    'q' => "mimeType = 'application/vnd.google-apps.folder' and trashed = false and name contains '$esc'",
    'pageSize' => 5, 'fields' => 'files(id,name)']));
  return $r['files'][0]['id'] ?? '';
}

/** Dosya icerigini okur (Docs/Sheets/Slides export, PDF metin, gorsel aciklama). */
function gdrive_read(string $id, int $max = 6000): string {
  if (!gmail_ready()) return 'Google bagli degil.';
  $id = trim($id);
  if ($id === '') return 'Dosya id gerekli (once drive_search ile bul).';
  $m = gdrive_meta($id);
  if (!empty($m['error'])) return 'Drive hatasi: ' . $m['error']['message'] . ' ' . gdrive_scope_hint($m['error']['message']);
  $mime = (string)($m['mimeType'] ?? '');
  $name = (string)($m['name'] ?? 'dosya');
  $head = 'Dosya: ' . $name . ' (' . gdrive_kind($mime) . ")\nBaglanti: " . ($m['webViewLink'] ?? '') . "\n\n";
  $exp = [
    'application/vnd.google-apps.document' => 'text/plain',
    'application/vnd.google-apps.presentation' => 'text/plain',
    'application/vnd.google-apps.spreadsheet' => 'text/csv',
  ];
  if (isset($exp[$mime])) {
    [$data, $err] = gdrive_download('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id)
      . '/export?mimeType=' . rawurlencode($exp[$mime]));
    if ($data === null) return 'Okunamadi: ' . $err . ' ' . gdrive_scope_hint($err);
    return $head . mb_substr($data, 0, $max);
  }
  if ($mime === 'application/vnd.google-apps.folder') {
    return $head . gdrive_search('', '', 20, $id);
  }
  [$data, $err] = gdrive_download('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id)
    . '?alt=media&supportsAllDrives=true');
  if ($data === null) return 'Okunamadi: ' . $err . ' ' . gdrive_scope_hint($err);
  if ($mime === 'application/pdf') {
    $t = gdrive_extract('pdf', $data, $name);
    return $t !== '' ? $head . mb_substr($t, 0, $max) : $head . '[PDF metni okunamadi.]';
  }
  if (strpos($mime, 'image/') === 0) {
    $v = gdrive_extract('vision', $data, $name);
    $o = gdrive_extract('ocr', $data, $name);
    $t = trim($v . ($o !== '' ? "\n[Gorseldeki yazi:]\n" . $o : ''));
    return $t !== '' ? $head . mb_substr($t, 0, $max) : $head . '[Gorsel icerigi cozumlenemedi.]';
  }
  if (strpos($mime, 'text/') === 0 || in_array($mime, ['application/json','application/xml','text/csv'], true)) {
    return $head . mb_substr($data, 0, $max);
  }
  return $head . '[Bu dosya turunun icerigi okunamiyor ama dosya erisilebilir; baglantiyi paylasabilir ya da gonderebilirsin.]';
}

/** Yeni dosya olusturur (Google Dokuman / e-tablo / duz metin). */
function gdrive_create(string $name, string $content = '', string $type = 'doc', string $folder = ''): string {
  if (!gmail_ready()) return 'Google bagli degil.';
  $name = trim($name);
  if ($name === '') return 'Dosya adi gerekli.';
  $type = strtolower($type ?: 'doc');
  if ($type === 'folder') {
    $meta = ['name'=>$name, 'mimeType'=>'application/vnd.google-apps.folder'];
    if ($folder !== '' && ($p = gdrive_folder_id($folder))) $meta['parents'] = [$p];
    $r = gdrive_api('files?supportsAllDrives=true&fields=id,webViewLink', $meta, 'POST');
    if (!empty($r['id'])) return 'Klasor olusturuldu: ' . $name . ' — ' . ($r['webViewLink'] ?? '');
    return 'Olusturulamadi: ' . ($r['error']['message'] ?? 'bilinmeyen hata');
  }
  $target = [
    'doc' => 'application/vnd.google-apps.document',
    'sheet' => 'application/vnd.google-apps.spreadsheet',
    'text' => 'text/plain',
  ][$type] ?? 'application/vnd.google-apps.document';
  $srcMime = ($type === 'sheet') ? 'text/csv' : 'text/plain';
  $meta = ['name'=>$name];
  if ($target !== 'text/plain') $meta['mimeType'] = $target;
  if ($folder !== '' && ($p = gdrive_folder_id($folder))) $meta['parents'] = [$p];
  $boundary = 'atlas' . bin2hex(random_bytes(8));
  $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($meta, JSON_UNESCAPED_UNICODE) . "\r\n"
        . "--$boundary\r\nContent-Type: $srcMime; charset=UTF-8\r\n\r\n" . $content . "\r\n--$boundary--";
  $tok = gmail_token();
  if (!$tok) return 'Google yetkisi yok.';
  $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,webViewLink');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok, 'Content-Type: multipart/related; boundary=' . $boundary],
    CURLOPT_POSTFIELDS=>$body]);
  $r = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
  if (!empty($r['id'])) return 'OLUSTURULDU: ' . $r['name'] . ' — ' . ($r['webViewLink'] ?? '') . ' | id: ' . $r['id'];
  $m = $r['error']['message'] ?? 'bilinmeyen hata';
  return 'Olusturulamadi: ' . $m . ' ' . gdrive_scope_hint($m);
}

/** Var olan dosyanin icerigini gunceller (uzerine yazar veya sonuna ekler). */
function gdrive_update(string $id, string $content, bool $append = false): string {
  if (!gmail_ready()) return 'Google bagli degil.';
  $m = gdrive_meta($id);
  if (!empty($m['error'])) return 'Drive hatasi: ' . $m['error']['message'];
  $mime = (string)($m['mimeType'] ?? '');
  $isSheet = $mime === 'application/vnd.google-apps.spreadsheet';
  if ($append) {
    $old = gdrive_read($id, 200000);
    $old = preg_replace('/^Dosya:.*\n Baglanti:.*\n\n/u', '', $old);
    $pos = strpos($old, "\n\n");
    if ($pos !== false) $old = substr($old, $pos + 2);
    $content = rtrim($old) . "\n" . $content;
  }
  $srcMime = $isSheet ? 'text/csv' : 'text/plain';
  $tok = gmail_token();
  if (!$tok) return 'Google yetkisi yok.';
  $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files/' . rawurlencode($id)
    . '?uploadType=media&supportsAllDrives=true&fields=id,name,webViewLink');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>60, CURLOPT_CUSTOMREQUEST=>'PATCH',
    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok, 'Content-Type: ' . $srcMime . '; charset=UTF-8'],
    CURLOPT_POSTFIELDS=>$content]);
  $r = json_decode((string)curl_exec($ch), true) ?: []; curl_close($ch);
  if (!empty($r['id'])) return 'GUNCELLENDI: ' . ($r['name'] ?? '') . ' — ' . ($r['webViewLink'] ?? '');
  $msg = $r['error']['message'] ?? 'bilinmeyen hata';
  return 'Guncellenemedi: ' . $msg . ' ' . gdrive_scope_hint($msg);
}

/** Dosyayi yeniden adlandirir ve/veya baska klasore tasir. */
function gdrive_move(string $id, string $folder = '', string $newName = ''): string {
  if (!gmail_ready()) return 'Google bagli degil.';
  $qs = ['supportsAllDrives'=>'true', 'fields'=>'id,name,webViewLink'];
  if ($folder !== '') {
    $fid = gdrive_folder_id($folder);
    if ($fid === '') return 'Hedef klasor bulunamadi: ' . $folder;
    $m = gdrive_meta($id);
    $qs['addParents'] = $fid;
    if (!empty($m['parents'])) $qs['removeParents'] = implode(',', $m['parents']);
  }
  $body = $newName !== '' ? ['name'=>$newName] : [];
  $r = gdrive_api('files/' . rawurlencode($id) . '?' . http_build_query($qs), $body, 'PATCH');
  if (!empty($r['id'])) return 'TASINDI/GUNCELLENDI: ' . ($r['name'] ?? '') . ' — ' . ($r['webViewLink'] ?? '');
  return 'Olmadi: ' . ($r['error']['message'] ?? 'bilinmeyen hata');
}

/** Dosyayi cop kutusuna atar. */
function gdrive_trash(string $id): string {
  $r = gdrive_api('files/' . rawurlencode($id) . '?supportsAllDrives=true&fields=id,name', ['trashed'=>true], 'PATCH');
  if (!empty($r['id'])) return 'Cop kutusuna tasindi: ' . ($r['name'] ?? $id);
  return 'Silinemedi: ' . ($r['error']['message'] ?? 'bilinmeyen hata');
}

/** Paylasim: belirli bir e-postaya ya da linki olana erisim verir. */
function gdrive_share(string $id, string $email = '', string $role = 'reader', bool $anyone = false): string {
  if (!gmail_ready()) return 'Google bagli degil.';
  $role = in_array($role, ['reader','commenter','writer'], true) ? $role : 'reader';
  $body = $anyone || $email === ''
    ? ['type'=>'anyone', 'role'=>$role]
    : ['type'=>'user', 'role'=>$role, 'emailAddress'=>$email];
  $r = gdrive_api('files/' . rawurlencode($id) . '/permissions?supportsAllDrives=true&sendNotificationEmail='
    . (($body['type'] === 'user') ? 'true' : 'false'), $body, 'POST');
  if (!empty($r['id'])) {
    $m = gdrive_meta($id);
    $who = $body['type'] === 'user' ? $email : 'linki olan herkes';
    return 'PAYLASILDI: ' . ($m['name'] ?? $id) . ' → ' . $who . ' (' . $role . ")\nBaglanti: " . ($m['webViewLink'] ?? '');
  }
  $msg = $r['error']['message'] ?? 'bilinmeyen hata';
  return 'Paylasilamadi: ' . $msg . ' ' . gdrive_scope_hint($msg);
}

/** Dosyayi indirilebilir bayt olarak getirir: [bytes, filename, mime] veya [null, hata, ''] */
function gdrive_bytes(string $id): array {
  $m = gdrive_meta($id);
  if (!empty($m['error'])) return [null, $m['error']['message'], ''];
  $mime = (string)($m['mimeType'] ?? '');
  $name = (string)($m['name'] ?? 'dosya');
  $exp = [
    'application/vnd.google-apps.document' => ['application/pdf', '.pdf'],
    'application/vnd.google-apps.presentation' => ['application/pdf', '.pdf'],
    'application/vnd.google-apps.spreadsheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx'],
  ];
  if (isset($exp[$mime])) {
    [$data, $err] = gdrive_download('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id)
      . '/export?mimeType=' . rawurlencode($exp[$mime][0]));
    if ($data === null) return [null, $err, ''];
    if (!preg_match('/\.[A-Za-z0-9]{2,4}$/', $name)) $name .= $exp[$mime][1];
    return [$data, $name, $exp[$mime][0]];
  }
  [$data, $err] = gdrive_download('https://www.googleapis.com/drive/v3/files/' . rawurlencode($id)
    . '?alt=media&supportsAllDrives=true');
  if ($data === null) return [null, $err, ''];
  return [$data, $name, $mime];
}
