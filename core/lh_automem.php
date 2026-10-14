<?php
/* ===== Otomatik hafiza (Viktor 2026-09-10) =====
   Her turdan sonra, kullaniciya hicbir onay/ayar sorulmadan:
   1) konusmadan KALICI bilgileri cikarip memories tablosuna yazar,
   2) sohbetin kisa ozetini conversations.summary alanina gunceller.
   Yanit kullaniciya gonderildikten SONRA cagrilir -> ek gecikme yok. */

function lh_mem_schema(): void {
  static $done = false; if ($done) return; $done = true;
  try { db()->exec("ALTER TABLE conversations ADD COLUMN summary VARCHAR(1000) NULL"); } catch (Throwable $e) {}
}

/** Hafizadaki mevcut bilgiler */
function lh_mem_list(int $uid, int $limit = 60): array {
  try {
    $st = db()->prepare('SELECT fact FROM memories WHERE user_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([$uid]);
    return array_column($st->fetchAll(), 'fact');
  } catch (Throwable $e) { return []; }
}

function lh_mem_norm(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = strtr($s, ['ı'=>'i','İ'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c']);
  return preg_replace('/[^a-z0-9 ]+/', '', $s);
}

/** Yasakli / gecici bilgi filtresi */
function lh_mem_reject(string $f): bool {
  if (mb_strlen($f) < 8 || mb_strlen($f) > 200) return true;
  if (preg_match('/(sk-[a-z0-9\-]{10,}|api[_ ]?key|şifre|sifre|password|token|kod\s*:\s*\d{4,}|\b\d{4,6}\s*(otp|doğrulama)|iban|kart numaras)/iu', $f)) return true;
  if (preg_match('/(bugün|şu an|az önce|dün saat|birazdan|şimdi gönder|teşekkür)/iu', $f)) return true;
  return false;
}

/** Basit JSON isteyen LLM cagrisi (araçsız, kisa, ucuz) */
function lh_mem_llm(array $messages, int $uid): string {
  foreach (atlas_llm_chain($uid, 'text') as $c) {
    $key = trim((string)($c['key'] ?? '')); if ($key === '') continue;
    $ch = curl_init(rtrim((string)$c['base'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
      CURLOPT_POSTFIELDS => json_encode(array_merge(
        ['model' => (string)$c['model']],
        function_exists('aiThinkFix') ? (array)aiThinkFix((string)$c['model']) : [],
        ['messages' => $messages, 'temperature' => 0.1, 'max_tokens' => 500]
      ), JSON_UNESCAPED_UNICODE),
      CURLOPT_TIMEOUT => 45,
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    if ($resp === false) continue;
    $j = json_decode($resp, true);
    if (!is_array($j) || isset($j['error'])) continue;
    $t = (string)($j['choices'][0]['message']['content'] ?? '');
    if (trim($t) !== '') return $t;
  }
  return '';
}

function lh_mem_json(string $raw): array {
  $raw = trim($raw);
  $raw = preg_replace('/^```(json)?|```$/mi', '', $raw);
  if (preg_match('/\{.*\}/s', $raw, $m)) $raw = $m[0];
  $j = json_decode(trim($raw), true);
  return is_array($j) ? $j : [];
}

/** Ana giris: turdan sonra cagrilir. Hata durumunda sessizce cikar. */
function lh_auto_memory(string $userText, string $reply, int $uid, int $conv = 0, string $lang = 'tr'): void {
  try {
    if ($uid <= 0) return;
    $userText = trim($userText); $reply = trim($reply);
    if (mb_strlen($userText) < 6) return;
    lh_mem_schema();

    $known = lh_mem_list($uid, 60);
    $hist  = [];
    if ($conv > 0) {
      $st = db()->prepare('SELECT user_text, agent_reply FROM commands WHERE user_id=? AND conv_id=? ORDER BY id DESC LIMIT 6');
      $st->execute([$uid, $conv]);
      foreach (array_reverse($st->fetchAll()) as $r) {
        $hist[] = 'Kullanıcı: ' . mb_substr((string)$r['user_text'], 0, 600);
        $hist[] = 'Asistan: '  . mb_substr((string)$r['agent_reply'], 0, 400);
      }
    }
    if (!$hist) { $hist[] = 'Kullanıcı: ' . mb_substr($userText, 0, 600); $hist[] = 'Asistan: ' . mb_substr($reply, 0, 400); }

    $sys = "Sen bir asistanın hafıza motorusun. Aşağıdaki konuşmadan SADECE kullanıcı hakkında KALICI, "
      . "gelecekte işe yarayacak bilgileri çıkar: adı/hitap şekli, işi/şirketi, şehri, ailesi, dil ve üslup tercihi "
      . "(resmi/samimi, kısa/uzun cevap), çalışma saatleri, sık iletişim kurduğu kişiler ve onlarla ilişkisi, "
      . "araç/ürün/marka tercihleri, tekrar eden rutinleri, kalıcı hedefleri, sevmediği davranışlar. "
      . "GEÇİCİ şeyleri (tek seferlik istek, bugünün işi, gönderilen mesaj, hava durumu) ve gizli verileri "
      . "(şifre, API anahtarı, doğrulama kodu) ASLA yazma. Zaten bilinen bilgileri tekrar etmeyeceksin. "
      . "Her bilgi tek, kısa, üçüncü tekil şahıs bir cümle olacak (en fazla 140 karakter). "
      . "Ayrıca sohbetin 1-2 cümlelik özetini yaz. "
      . "SADECE şu JSON'u döndür: {\"facts\":[\"...\"],\"summary\":\"...\"} — yeni kalıcı bilgi yoksa facts boş dizi olsun.";
    $usr = "ZATEN BİLİNENLER:\n" . ($known ? "- " . implode("\n- ", array_slice($known, 0, 40)) : "(yok)")
      . "\n\nKONUŞMA:\n" . implode("\n", $hist);

    $out = lh_mem_json(lh_mem_llm([
      ['role'=>'system','content'=>$sys], ['role'=>'user','content'=>$usr],
    ], $uid));
    if (!$out) return;

    /* Ozet */
    $sum = trim((string)($out['summary'] ?? ''));
    if ($sum !== '' && $conv > 0) {
      try { db()->prepare('UPDATE conversations SET summary=? WHERE id=? AND user_id=?')
              ->execute([mb_substr($sum, 0, 1000), $conv, $uid]); } catch (Throwable $e) {}
    }

    /* Kalici bilgiler */
    $facts = $out['facts'] ?? [];
    if (!is_array($facts)) return;
    $knownN = array_map('lh_mem_norm', $known);
    $ins = db()->prepare('INSERT INTO memories(user_id,fact) VALUES(?,?) ON DUPLICATE KEY UPDATE updated_at=NOW()');
    $added = 0;
    foreach ($facts as $f) {
      if (!is_string($f)) continue;
      $f = trim(preg_replace('/\s+/u', ' ', $f));
      if ($f === '' || lh_mem_reject($f)) continue;
      $n = lh_mem_norm($f);
      if ($n === '' || in_array($n, $knownN, true)) continue;
      foreach ($knownN as $kn) {              // cok benzer bilgiyi de atla
        if ($kn !== '' && (strpos($kn, $n) !== false || strpos($n, $kn) !== false)) { $n = ''; break; }
      }
      if ($n === '') continue;
      $ins->execute([$uid, mb_substr($f, 0, 500)]);
      $knownN[] = $n; $added++;
      if ($added >= 6) break;                 // tur basina en fazla 6 yeni bilgi
    }

    /* Ust sinir: kullanici basina 250 bilgi; en eskileri sil */
    if ($added) {
      try {
        $c = db()->prepare('SELECT COUNT(*) FROM memories WHERE user_id=?'); $c->execute([$uid]);
        $tot = (int)$c->fetchColumn();
        if ($tot > 250) {
          $d = db()->prepare('DELETE FROM memories WHERE user_id=? ORDER BY updated_at ASC, id ASC LIMIT ' . (int)($tot - 250));
          $d->execute([$uid]);
        }
      } catch (Throwable $e) {}
    }
  } catch (Throwable $e) { /* hafiza asla akisi bozmaz */ }
}
