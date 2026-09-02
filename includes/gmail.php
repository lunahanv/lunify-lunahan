<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* Atlas — Gmail (OAuth2) entegrasyonu. Kimlik bilgileri settings tablosunda tutulur. */
require_once __DIR__ . '/db.php';

function gmail_redirect_uri(): string { return rtrim(APP_URL, '/') . '/gmail-callback.php'; }
/** Gmail ayari: once kullaniciya ozel, yoksa sistem geneli Google istemcisi (admin panelinden). */
function gmail_cfg(string $k): string {
  $v = (string)setting('gmail_' . $k, '');
  if ($v === '' && in_array($k, ['client_id', 'client_secret'], true)) $v = (string)gsetting('google_' . $k, '');
  return $v;
}
/** Baglantida Drive izni var mi? (izin bilinmiyorsa true kabul edilir, API hatasi yol gosterir) */
function gmail_has_drive(): bool {
  $sc = (string)gmail_cfg('scopes');
  if ($sc === '') return false;
  return strpos($sc, 'auth/drive') !== false;
}

/** Baglantida Google Kisiler (telefon rehberi) izni var mi? (Viktor 2026-08-30) */
function gmail_has_contacts(): bool {
  $sc = (string)gmail_cfg('scopes');
  if ($sc === '') return false;
  return strpos($sc, 'auth/contacts') !== false;
}

function gmail_ready(): bool { return gmail_cfg('client_id') !== '' && gmail_cfg('refresh_token') !== ''; }

function gmail_auth_url(): string {
  return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => gmail_cfg('client_id'),
    'redirect_uri' => gmail_redirect_uri(),
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/gmail.modify https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/tasks https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/contacts https://www.googleapis.com/auth/userinfo.email',
    'access_type' => 'offline',
    'prompt' => 'consent',
  ]);
}

function gmail_post_token(array $params) {
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>30,
    CURLOPT_POSTFIELDS=>http_build_query($params)]);
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true) ?: [];
}

function gmail_exchange_code(string $code): array {
  $j = gmail_post_token([
    'code'=>$code, 'client_id'=>gmail_cfg('client_id'), 'client_secret'=>gmail_cfg('client_secret'),
    'redirect_uri'=>gmail_redirect_uri(), 'grant_type'=>'authorization_code']);
  if (!empty($j['refresh_token'])) set_setting('gmail_refresh_token', $j['refresh_token']);
  if (!empty($j['scope'])) set_setting('gmail_scopes', $j['scope']);
  if (!empty($j['access_token'])) {
    set_setting('gmail_access_token', $j['access_token']);
    set_setting('gmail_expires_at', (string)(time() + (int)($j['expires_in'] ?? 3000) - 60));
  }
  return $j;
}

function gmail_token(): string {
  $tok = gmail_cfg('access_token');
  $exp = (int)gmail_cfg('expires_at');
  if ($tok && $exp > time()) return $tok;
  $j = gmail_post_token([
    'client_id'=>gmail_cfg('client_id'), 'client_secret'=>gmail_cfg('client_secret'),
    'refresh_token'=>gmail_cfg('refresh_token'), 'grant_type'=>'refresh_token']);
  if (empty($j['access_token'])) return '';
  set_setting('gmail_access_token', $j['access_token']);
  set_setting('gmail_expires_at', (string)(time() + (int)($j['expires_in'] ?? 3000) - 60));
  if (!empty($j['scope'])) set_setting('gmail_scopes', $j['scope']);
  return $j['access_token'];
}

function gmail_api(string $path, array $post = null, string $method = '') {
  $tok = gmail_token();
  if (!$tok) return ['error'=>['message'=>'Gmail yetkisi yok / token alınamadı']];
  $ch = curl_init('https://gmail.googleapis.com/gmail/v1/users/me' . $path);
  $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok, 'Content-Type: application/json']];
  if ($post !== null) { $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($post); }
  if ($method) $opt[CURLOPT_CUSTOMREQUEST] = $method;
  curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true) ?: ['error'=>['message'=>'Gmail yanıt vermedi']];
}

function gmail_address(): string {
  $cached = gmail_cfg('address');
  if ($cached) return $cached;
  $p = gmail_api('/profile');
  if (!empty($p['emailAddress'])) { set_setting('gmail_address', $p['emailAddress']); return $p['emailAddress']; }
  return '';
}

/** E-posta gönder. Dönüş: [ok(bool), mesaj] */
function gmail_send(string $to, string $subject, string $body, array $attachments = []): array {
  if (!gmail_ready()) return [false, 'Gmail bağlı değil'];
  $from = gmail_address();
  $sub = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  if ($attachments) {
    $b = 'atlas' . bin2hex(random_bytes(8));
    $mime = "From: $from\r\nTo: $to\r\nSubject: $sub\r\nMIME-Version: 1.0\r\n"
          . "Content-Type: multipart/mixed; boundary=\"$b\"\r\n\r\n"
          . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($body));
    foreach ($attachments as $att) {
      $an = (string)($att['name'] ?? 'dosya');
      $am = (string)($att['mime'] ?? 'application/octet-stream');
      $mime .= "--$b\r\nContent-Type: $am; name=\"$an\"\r\n"
             . "Content-Disposition: attachment; filename=\"$an\"\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode((string)($att['data'] ?? '')));
    }
    $mime .= "--$b--";
  } else {
    $mime = "From: $from\r\nTo: $to\r\nSubject: $sub\r\nMIME-Version: 1.0\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
          . chunk_split(base64_encode($body));
  }
  $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
  $r = gmail_api('/messages/send', ['raw'=>$raw]);
  if (!empty($r['id'])) return [true, 'Gönderildi (' . $r['id'] . ')'];
  return [false, $r['error']['message'] ?? 'Gönderilemedi'];
}

function gmail_header(array $msg, string $name): string {
  foreach ($msg['payload']['headers'] ?? [] as $h) {
    if (strcasecmp($h['name'], $name) === 0) return $h['value'];
  }
  return '';
}

function gmail_body_text(array $msg): string {
  $walk = function ($part) use (&$walk) {
    if (!empty($part['body']['data']) && ($part['mimeType'] ?? '') === 'text/plain') {
      return base64_decode(strtr($part['body']['data'], '-_', '+/'));
    }
    foreach ($part['parts'] ?? [] as $p) { $t = $walk($p); if ($t !== '') return $t; }
    return '';
  };
  $t = $walk($msg['payload'] ?? []);
  if ($t === '') $t = $msg['snippet'] ?? '';
  return $t;
}

/** Gelen kutusunda arama / son mailler. */
function gmail_list(string $query = '', int $max = 8): string {
  if (!gmail_ready()) return 'Gmail bağlı değil. Panelden Gmail sayfasından bağlan.';
  $q = $query !== '' ? $query : 'in:inbox newer_than:7d';
  $r = gmail_api('/messages?' . http_build_query(['q'=>$q, 'maxResults'=>$max]));
  if (!empty($r['error'])) return 'Gmail hatası: ' . $r['error']['message'];
  if (empty($r['messages'])) return 'Eşleşen e-posta yok.';
  $out = [];
  foreach ($r['messages'] as $m) {
    $msg = gmail_api('/messages/' . $m['id'] . '?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=Date');
    if (!empty($msg['error'])) continue;
    $out[] = '• ' . gmail_header($msg, 'Subject') . "\n  Kimden: " . gmail_header($msg, 'From')
           . ' | ' . gmail_header($msg, 'Date') . "\n  Özet: " . trim($msg['snippet'] ?? '')
           . "\n  id: " . $m['id'];
  }
  return $out ? implode("\n", $out) : 'Eşleşen e-posta yok.';
}

function gmail_read(string $id): string {
  if (!gmail_ready()) return 'Gmail bağlı değil.';
  $msg = gmail_api('/messages/' . rawurlencode($id) . '?format=full');
  if (!empty($msg['error'])) return 'Gmail hatası: ' . $msg['error']['message'];
  return 'Konu: ' . gmail_header($msg, 'Subject') . "\nKimden: " . gmail_header($msg, 'From')
       . "\nTarih: " . gmail_header($msg, 'Date') . "\n\n" . mb_substr(gmail_body_text($msg), 0, 4000);
}
