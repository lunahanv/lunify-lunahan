<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Türkçe serbest zaman ifadesini datetime'a çevirir. "yarın 10:00", "3 gün sonra",
 *  "pazartesi", "2 saat sonra", "15.09 14:00", "akşam". Bulamazsa null. */
function atlas_when_to_dt(?string $t, ?int $now = null): ?string {
  if (date_default_timezone_get() !== 'Europe/Istanbul') date_default_timezone_set('Europe/Istanbul');
  if ($t === null) return null;
  $now = $now ?: time();
  $s = mb_strtolower(trim($t), 'UTF-8');
  if ($s === '') return null;
  $s = strtr($s, ['ı'=>'i','İ'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);
  $hour = null; $min = 0;
  if (preg_match('/(\d{1,2}):(\d{2})/', $s, $m) && (int)$m[1] < 24) { $hour = (int)$m[1]; $min = (int)$m[2]; }
  elseif (preg_match('/saat\s*(\d{1,2})\b/', $s, $m)) { $hour = (int)$m[1]; }
  if ($hour === null) {
    if (preg_match('/sabah/', $s)) $hour = 9;
    elseif (preg_match('/ogle|oglen/', $s)) $hour = 12;
    elseif (preg_match('/aksam/', $s)) $hour = 19;
    elseif (preg_match('/gece/', $s)) $hour = 22;
  }
  $base = null;
  /* Viktor 2026-08-31: 'simdi/hemen' ve 'yarim saat' de anlasilsin (due_at NULL kalirsa hatirlatma hic gitmiyordu). */
  if (preg_match('/\\b(simdi|hemen|derhal|acil)\\b/', $s))            return date('Y-m-d H:i:s', $now + 60);
  if (preg_match('/yarim\\s*saat\\s*sonra/', $s))                     return date('Y-m-d H:i:s', $now + 1800);
  if (preg_match('/ceyrek\\s*saat\\s*sonra/', $s))                    return date('Y-m-d H:i:s', $now + 900);
  if (preg_match('/(\d+)\s*(dakika|dk)\s*sonra/', $s, $m)) return date('Y-m-d H:i:s', $now + 60 * (int)$m[1]);
  if (preg_match('/(\d+)\s*saat\s*sonra/', $s, $m))        return date('Y-m-d H:i:s', $now + 3600 * (int)$m[1]);
  if (preg_match('/(\d+)\s*gun\s*sonra/', $s, $m))         $base = strtotime('+' . (int)$m[1] . ' day', $now);
  elseif (preg_match('/(\d+)\s*hafta\s*sonra/', $s, $m))   $base = strtotime('+' . (7 * (int)$m[1]) . ' day', $now);
  elseif (preg_match('/(\d+)\s*ay\s*sonra/', $s, $m))      $base = strtotime('+' . (int)$m[1] . ' month', $now);
  elseif (preg_match('/obur gun/', $s))                    $base = strtotime('+2 day', $now);
  elseif (preg_match('/yarin/', $s))                       $base = strtotime('+1 day', $now);
  elseif (preg_match('/bugun|birazdan|az sonra/', $s))     $base = $now;
  elseif (preg_match('/hafta sonu/', $s))                  $base = strtotime('next saturday', $now);
  elseif (preg_match('/gelecek hafta|haftaya/', $s))       $base = strtotime('+7 day', $now);
  else {
    $days = ['pazartesi'=>'monday','sali'=>'tuesday','carsamba'=>'wednesday','persembe'=>'thursday',
             'cuma'=>'friday','cumartesi'=>'saturday','pazar'=>'sunday'];
    foreach ($days as $tr => $en) {
      if (mb_strpos($s, $tr) !== false) { $base = strtotime('next ' . $en, $now); break; }
    }
    if ($base === null && preg_match('/(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{2,4}))?/', $s, $m)) {
      $y = isset($m[3]) ? (int)$m[3] : (int)date('Y', $now);
      if ($y < 100) $y += 2000;
      $base = mktime(12, 0, 0, (int)$m[2], (int)$m[1], $y);
    }
    if ($base === null) { $ts = strtotime($s, $now); if ($ts) $base = $ts; }
  }
  if ($base === null && $hour !== null) $base = $now;   // sadece saat verildi: bugun
  if ($base === null) return null;
  if ($hour !== null) $base = mktime($hour, $min, 0, (int)date('n', $base), (int)date('j', $base), (int)date('Y', $base));
  elseif (date('Y-m-d', $base) !== date('Y-m-d', $now)) $base = mktime(10, 0, 0, (int)date('n', $base), (int)date('j', $base), (int)date('Y', $base));
  if ($base < $now - 60) $base = strtotime('+1 day', $base);
  return date('Y-m-d H:i:s', $base);
}
