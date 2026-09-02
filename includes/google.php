<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* Atlas — Google Takvim + Görevler (Gmail ile aynı OAuth bağlantısını kullanır). */
require_once __DIR__ . '/gmail.php';

function google_api(string $url, array $body = null, string $method = 'GET') {
  $tok = gmail_token();
  if (!$tok) return ['error'=>['message'=>'Google yetkisi yok / token alınamadı']];
  $ch = curl_init($url);
  $opt = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>40, CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . $tok, 'Content-Type: application/json']];
  if ($body !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $opt);
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true) ?: ['error'=>['message'=>'Google yanıt vermedi']];
}

function gcal_tz(): string { return (string)(setting('timezone', 'Europe/Istanbul') ?: 'Europe/Istanbul'); }

/** Yaklaşan etkinlikler. */
function gcal_list(int $days = 7, int $max = 15): string {
  if (!gmail_ready()) return 'Google bağlı değil. Panelden Gmail/Google sayfasından bağlan.';
  $tz = gcal_tz();
  $now = new DateTime('now', new DateTimeZone($tz));
  $end = (clone $now)->modify('+' . max(1, $days) . ' days');
  $q = http_build_query([
    'timeMin'=>$now->format(DateTime::RFC3339), 'timeMax'=>$end->format(DateTime::RFC3339),
    'singleEvents'=>'true', 'orderBy'=>'startTime', 'maxResults'=>$max, 'timeZone'=>$tz]);
  $r = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events?' . $q);
  if (!empty($r['error'])) return 'Takvim hatası: ' . $r['error']['message'];
  if (empty($r['items'])) return 'Bu aralıkta etkinlik yok.';
  $out = [];
  foreach ($r['items'] as $e) {
    $s = $e['start']['dateTime'] ?? ($e['start']['date'] ?? '');
    $t = $s ? date('d.m.Y H:i', strtotime($s)) : '';
    if (!empty($e['start']['date'])) $t = date('d.m.Y', strtotime($e['start']['date'])) . ' (tüm gün)';
    $out[] = '• ' . $t . ' — ' . ($e['summary'] ?? '(başlıksız)')
      . (!empty($e['location']) ? ' @ ' . $e['location'] : '') . ' | id: ' . ($e['id'] ?? '');
  }
  return implode("\n", $out);
}

/** Etkinlik oluşturur. $start/$end: "2026-08-30 15:00" ya da ISO. */
function gcal_add(string $title, string $start, string $end = '', string $desc = '', string $loc = ''): string {
  if (!gmail_ready()) return 'Google bağlı değil.';
  $tz = gcal_tz();
  $ts = strtotime($start);
  if (!$ts) return 'Tarih anlaşılmadı: ' . $start;
  $te = $end ? strtotime($end) : $ts + 3600;
  if (!$te || $te <= $ts) $te = $ts + 3600;
  $body = [
    'summary'=>$title,
    'description'=>$desc ?: null,
    'location'=>$loc ?: null,
    'start'=>['dateTime'=>date('c', $ts), 'timeZone'=>$tz],
    'end'=>['dateTime'=>date('c', $te), 'timeZone'=>$tz],
  ];
  $r = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events', array_filter($body, fn($v)=>$v!==null), 'POST');
  if (!empty($r['id'])) return 'Takvime eklendi: ' . $title . ' — ' . date('d.m.Y H:i', $ts);
  return 'Eklenemedi: ' . ($r['error']['message'] ?? 'bilinmeyen hata');
}

function gcal_delete(string $id): string {
  $r = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($id), null, 'DELETE');
  return empty($r['error']) ? 'Etkinlik silindi.' : 'Silinemedi: ' . $r['error']['message'];
}

/* ---- Google Görevler ---- */
function gtasks_default_list(): string {
  $c = (string)setting('gtasks_list_id', '');
  if ($c) return $c;
  $r = google_api('https://tasks.googleapis.com/tasks/v1/users/@me/lists?maxResults=10');
  $id = $r['items'][0]['id'] ?? '';
  if ($id) set_setting('gtasks_list_id', $id);
  return $id;
}

function gtasks_list(bool $show_done = false, int $max = 20): string {
  if (!gmail_ready()) return 'Google bağlı değil.';
  $lid = gtasks_default_list();
  if (!$lid) return 'Görev listesi bulunamadı.';
  $q = http_build_query(['maxResults'=>$max, 'showCompleted'=>$show_done ? 'true':'false', 'showHidden'=>'false']);
  $r = google_api('https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($lid) . '/tasks?' . $q);
  if (!empty($r['error'])) return 'Görev hatası: ' . $r['error']['message'];
  if (empty($r['items'])) return 'Açık görev yok.';
  $out = [];
  foreach ($r['items'] as $t) {
    $d = !empty($t['due']) ? ' (son: ' . date('d.m.Y', strtotime($t['due'])) . ')' : '';
    $st = ($t['status'] ?? '') === 'completed' ? '✓ ' : '• ';
    $out[] = $st . ($t['title'] ?? '') . $d . ' | id: ' . ($t['id'] ?? '');
  }
  return implode("\n", $out);
}

function gtasks_norm_title(string $t): string {
  $t = mb_strtolower(trim($t), 'UTF-8');
  $t = strtr($t, ['ı'=>'i','İ'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);
  return preg_replace('/[^a-z0-9]+/u', ' ', $t);
}

function gtasks_add(string $title, string $due = '', string $notes = ''): string {
  if (!gmail_ready()) return 'Google bağlı değil.';
  $lid = gtasks_default_list();
  if (!$lid) return 'Görev listesi bulunamadı.';
  /* Viktor 2026-08-30: AYNI gorev iki kez eklenmesin (model tekrar cagirabiliyor). */
  try {
    $ex = google_api('https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($lid)
      . '/tasks?' . http_build_query(['maxResults'=>100,'showCompleted'=>'false','showHidden'=>'false']));
    $nt = gtasks_norm_title($title);
    $dd = ($due && ($t2 = strtotime($due))) ? date('Y-m-d', $t2) : '';
    foreach (($ex['items'] ?? []) as $t) {
      if (gtasks_norm_title((string)($t['title'] ?? '')) !== $nt) continue;
      $ed = !empty($t['due']) ? substr((string)$t['due'], 0, 10) : '';
      if ($dd === '' || $ed === '' || $ed === $dd) {
        return 'ZATEN VAR (tekrar eklenmedi): ' . $title . ($ed ? ' (son: ' . date('d.m.Y', strtotime($ed)) . ')' : '');
      }
    }
  } catch (\Throwable $e) {}
  $body = ['title'=>$title];
  if ($notes) $body['notes'] = $notes;
  if ($due && ($ts = strtotime($due))) $body['due'] = date('Y-m-d', $ts) . 'T00:00:00.000Z';
  $r = google_api('https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($lid) . '/tasks', $body, 'POST');
  if (!empty($r['id'])) return 'Göreve eklendi: ' . $title;
  return 'Eklenemedi: ' . ($r['error']['message'] ?? 'bilinmeyen hata');
}

function gtasks_done(string $id): string {
  $lid = gtasks_default_list();
  if (!$lid) return 'Görev listesi bulunamadı.';
  $r = google_api('https://tasks.googleapis.com/tasks/v1/lists/' . rawurlencode($lid) . '/tasks/' . rawurlencode($id),
    ['status'=>'completed'], 'PATCH');
  return empty($r['error']) ? 'Görev tamamlandı olarak işaretlendi.' : 'Olmadı: ' . $r['error']['message'];
}

/* Viktor 2026-08-31: hatirlatici icin POPUP bildirimli kisa takvim etkinligi.
   WhatsApp'ta kendi kendine mesaj bildirim uretmedigi icin asil bildirim buradan gidiyor
   (Android: Google Takvim, iOS: Google hesabi takvimi). $dueLocal = "Y-m-d H:i:s" (yerel). */
function gcal_reminder_event(string $title, string $dueLocal, string $existingId = ''): array {
  if (!gmail_ready()) return ['ok'=>false, 'msg'=>'google_yok'];
  $ts = strtotime($dueLocal);
  if (!$ts) return ['ok'=>false, 'msg'=>'tarih_yok'];
  $tz = gcal_tz();
  $body = [
    'summary'     => '⏰ ' . $title,
    'description' => 'Atlas hatırlatması',
    'start'       => ['dateTime'=>date('c', $ts), 'timeZone'=>$tz],
    'end'         => ['dateTime'=>date('c', $ts + 600), 'timeZone'=>$tz],
    'reminders'   => ['useDefault'=>false, 'overrides'=>[['method'=>'popup','minutes'=>0]]],
  ];
  if ($existingId !== '') {
    $r = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($existingId), $body, 'PATCH');
    if (!empty($r['id'])) return ['ok'=>true, 'id'=>(string)$r['id'], 'updated'=>true];
  }
  $r = google_api('https://www.googleapis.com/calendar/v3/calendars/primary/events', $body, 'POST');
  if (!empty($r['id'])) return ['ok'=>true, 'id'=>(string)$r['id']];
  return ['ok'=>false, 'msg'=>(string)($r['error']['message'] ?? 'bilinmeyen hata')];
}

/* Alarmli .ics (VALARM) uretir — iPhone kullanicisi dosyaya dokunup takvime ekler,
   bildirim tam saatinde iOS tarafindan calar. */
function atlas_build_ics(string $title, string $dueLocal): string {
  $ts = strtotime($dueLocal);
  $uid = bin2hex(random_bytes(8)) . '@lunify';
  $fmt = fn(int $t) => gmdate('Ymd\\THis\\Z', $t);
  $esc = fn(string $s) => str_replace([chr(92), ';', ',', "\n"], [chr(92).chr(92), chr(92).';', chr(92).',', chr(92).'n'], $s);
  return implode("\r\n", [
    'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Lunify Atlas//TR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:' . $fmt(time()),
    'DTSTART:' . $fmt($ts), 'DTEND:' . $fmt($ts + 600),
    'SUMMARY:' . $esc('⏰ ' . $title), 'DESCRIPTION:' . $esc('Atlas hatırlatması'),
    'BEGIN:VALARM', 'TRIGGER:-PT1M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc($title), 'END:VALARM',
    'END:VEVENT', 'END:VCALENDAR', '']);
}
