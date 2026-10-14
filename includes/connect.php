<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 */

/* LUNAHAN baglanti istemcisi (musteri tarafi) — Viktor 2026-09-09.
   Kullanici bir Lunify uygulamasindan token alir, buraya girer; o uygulamanin
   paylastigi araclar LUNAHAN'in arac listesine eklenir ve uzaktan cagirilir.
   Veri karsi uygulamada kalir; LUNAHAN sadece arac cagirir. */

require_once __DIR__ . '/db.php';

function lh_links_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  db()->exec("CREATE TABLE IF NOT EXISTS lh_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    app_key VARCHAR(40) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    token VARCHAR(64) NOT NULL,
    tools_json MEDIUMTEXT NULL,
    tools_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'new',
    last_error VARCHAR(255) DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_app (user_id, app_key),
    KEY (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Uzak uca istek. Donus: [dizi|null, hata metni]. */
function lh_link_call(array $link, array $payload, int $timeout = 90): array {
  $url = rtrim((string)$link['base_url'], '/') . '/lh_api.php';
  $ch  = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-LH-Token: ' . $link['token']],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
  ]);
  $r    = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($r === false) return [null, 'baglanti hatasi: ' . $err];
  $j = json_decode((string)$r, true);
  if (!is_array($j)) return [null, 'gecersiz yanit (HTTP ' . $code . ')'];
  if (!empty($j['error'])) return [null, (string)$j['error']];
  return [$j, ''];
}

function lh_links(int $uid, bool $onlyOk = true): array {
  lh_links_schema();
  $sql = 'SELECT * FROM lh_links WHERE user_id=?' . ($onlyOk ? " AND status='ok'" : '') . ' ORDER BY app_key';
  $st  = db()->prepare($sql);
  $st->execute([$uid]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Baglantiyi dogrula ve arac listesini tazele. Donus: [aracSayisi, hata]. */
function lh_link_refresh(int $linkId): array {
  lh_links_schema();
  $st = db()->prepare('SELECT * FROM lh_links WHERE id=?');
  $st->execute([$linkId]);
  $link = $st->fetch(PDO::FETCH_ASSOC);
  if (!$link) return [0, 'baglanti yok'];
  [$j, $err] = lh_link_call($link, ['action' => 'describe'], 30);
  if ($j === null) {
    db()->prepare("UPDATE lh_links SET status='error', last_error=? WHERE id=?")->execute([mb_substr($err, 0, 240), $linkId]);
    return [0, $err];
  }
  $tools = is_array($j['tools'] ?? null) ? $j['tools'] : [];
  db()->prepare("UPDATE lh_links SET status='ok', last_error='', tools_json=?, tools_at=NOW() WHERE id=?")
    ->execute([json_encode($tools, JSON_UNESCAPED_UNICODE), $linkId]);
  return [count($tools), ''];
}

function lh_link_add(int $uid, string $appKey, string $baseUrl, string $token): array {
  lh_links_schema();
  $appKey  = preg_replace('/[^a-z0-9_]/', '', strtolower($appKey));
  $baseUrl = rtrim(trim($baseUrl), '/');
  if ($appKey === '' || $baseUrl === '' || strlen($token) < 24) return [0, 'eksik bilgi'];
  db()->prepare("INSERT INTO lh_links(user_id,app_key,base_url,token,status) VALUES(?,?,?,?,'new')
                 ON DUPLICATE KEY UPDATE base_url=VALUES(base_url), token=VALUES(token), status='new', last_error=''")
    ->execute([$uid, $appKey, $baseUrl, $token]);
  $id = (int)db()->query('SELECT id FROM lh_links WHERE user_id=' . $uid . " AND app_key=" . db()->quote($appKey))->fetchColumn();
  return lh_link_refresh($id);
}

/* ---- Varsayilan (otomatik) baglantilar — Viktor 2026-09-10, sahip istegi:
       "lunahan default olarak anahtarda calisacak, ayri modul gostermeye gerek yok".
       Kullanici/yonetici hicbir sey yapmaz: sohbet acildiginda ayni e-postali hesap
       karsi uygulamada varsa anahtar otomatik uretilir ve araclar yuklenir. ---- */

function lh_auto_apps(): array {
  $urls = ['anahtar' => 'https://anahtar.lunify.world',
           'aura'    => 'https://aura.lunify.world',
           'pulse'   => 'https://lunify-pulse.com.tr'];
  $list = defined('LH_AUTO_APPS') ? (string)LH_AUTO_APPS : 'anahtar';
  $out  = [];
  foreach (array_filter(array_map('trim', explode(',', strtolower($list)))) as $k) {
    if (isset($urls[$k])) $out[$k] = $urls[$k];
  }
  return $out;
}

/** Karsi uygulamanin yonetici ucundan bu kullanici icin anahtar al. [token, hata] */
function lh_auto_issue(string $base, string $email): array {
  $ch = curl_init(rtrim($base, '/') . '/lh_admin.php');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                               'X-LH-Admin: ' . (defined('LH_ADMIN_KEY') ? LH_ADMIN_KEY : '')],
    CURLOPT_POSTFIELDS     => json_encode(['act' => 'issue', 'email' => $email], JSON_UNESCAPED_UNICODE),
  ]);
  $raw = (string)curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
  $j = json_decode($raw, true);
  if (!is_array($j))      return ['', 'yanit yok: ' . ($cerr ?: mb_substr($raw, 0, 100))];
  if (!empty($j['error'])) return ['', (string)$j['error']];
  $t = (string)($j['token'] ?? '');
  return $t === '' ? ['', 'anahtar bos'] : [$t, ''];
}

/** Varsayilan baglantilari sessizce kur/tazele. Istek basina bir kez, hatada 30 dk bekler. */
function lh_autolink(int $uid): void {
  static $done = [];
  if ($uid <= 0 || isset($done[$uid])) return;
  $done[$uid] = true;
  $apps = lh_auto_apps();
  if (!$apps) return;
  lh_links_schema();
  $q = db()->prepare('SELECT email FROM users WHERE id=?');
  $q->execute([$uid]);
  $email = strtolower(trim((string)($q->fetchColumn() ?: '')));
  if ($email === '') return;

  $st = db()->prepare('SELECT * FROM lh_links WHERE user_id=?');
  $st->execute([$uid]);
  $have = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) $have[$l['app_key']] = $l;

  foreach ($apps as $key => $base) {
    $l = $have[$key] ?? null;
    if ($l && $l['status'] === 'ok') {
      /* araclar 7 gunden eskiyse sessizce tazele */
      if (empty($l['tools_at']) || strtotime((string)$l['tools_at']) < time() - 7 * 86400) {
        lh_link_refresh((int)$l['id']);
      }
      continue;
    }
    /* hatali denemeden sonra 30 dk bekle (her mesajda yeniden denemesin) */
    if ($l && $l['status'] !== 'ok' && !empty($l['tools_at'])
        && strtotime((string)$l['tools_at']) > time() - 1800) continue;

    [$tok, $err] = lh_auto_issue($base, $email);
    if ($tok === '') {
      db()->prepare("INSERT INTO lh_links(user_id,app_key,base_url,token,status,last_error,tools_at)
                     VALUES(?,?,?,'','error',?,NOW())
                     ON DUPLICATE KEY UPDATE status='error', last_error=VALUES(last_error), tools_at=NOW()")
        ->execute([$uid, $key, $base, mb_substr('otomatik: ' . $err, 0, 240)]);
      continue;
    }
    [$n, $e] = lh_link_add($uid, $key, $base, $tok);
    if ($e !== '') {
      db()->prepare("UPDATE lh_links SET tools_at=NOW() WHERE user_id=? AND app_key=?")->execute([$uid, $key]);
    }
  }
}

/* ---- Ajana baglanma: uzak araclari kayit defterine ekle ---- */

function lh_remote_map(int $uid): array {
  static $cache = [];
  if (isset($cache[$uid])) return $cache[$uid];
  lh_autolink($uid);
  $map = [];
  foreach (lh_links($uid) as $l) {
    foreach ((array)json_decode((string)$l['tools_json'], true) as $t) {
      $n = $t['function']['name'] ?? '';
      if ($n === '') continue;
      /* JSON'dan gelen bos properties dizisi [] provider'i hata veriyor: nesne olmali. */
      if (($t['function']['parameters']['properties'] ?? null) === []) {
        $t['function']['parameters']['properties'] = new stdClass();
      }
      $d = (string)($t['function']['description'] ?? '');
      $t['function']['description'] = mb_substr(trim($d . ' [' . strtoupper($l['app_key']) . ' bağlantısı üzerinden]'), 0, 900);
      $map[$n] = ['tool' => $t, 'link' => $l];
    }
  }
  return $cache[$uid] = $map;
}

function lh_remote_tools(): array {
  $uid = function_exists('atlas_uid') ? (int)atlas_uid() : 0;
  if ($uid <= 0) return [];
  $out = [];
  foreach (lh_remote_map($uid) as $m) $out[] = $m['tool'];
  return $out;
}

function lh_remote_run(string $name, array $args) {
  $uid = function_exists('atlas_uid') ? (int)atlas_uid() : 0;
  if ($uid <= 0) return null;
  $map = lh_remote_map($uid);
  if (!isset($map[$name])) return null;
  $link = $map[$name]['link'];
  [$j, $err] = lh_link_call($link, ['action' => 'run', 'tool' => $name, 'args' => $args]);
  if ($j === null) {
    /* Otomatik baglanti: anahtar iptal/gecersizse bir sonraki denemede yeniden uretilsin. */
    if (isset(lh_auto_apps()[$link['app_key']])) {
      db()->prepare("UPDATE lh_links SET status='error', last_error=?, tools_at=NULL WHERE id=?")
        ->execute([mb_substr($err, 0, 240), (int)$link['id']]);
    }
    return strtoupper($link['app_key']) . ' bağlantısı şu an yanıt vermedi: ' . $err
      . ' (kullanıcıya söyle: birazdan tekrar denesin, bağlantı otomatik yenileniyor)';
  }
  return (string)($j['result'] ?? '');
}

function lh_remote_prompt(string $lang = 'tr'): string {
  $uid = function_exists('atlas_uid') ? (int)atlas_uid() : 0;
  if ($uid <= 0) return '';
  lh_autolink($uid);
  $apps = [];
  foreach (lh_links($uid) as $l) $apps[] = strtoupper($l['app_key']);
  if (!$apps) return '';
  $list = implode(', ', $apps);
  return $lang === 'en'
    ? "\nCONNECTED APPS: {$list}. Their tools run inside those apps on the user's own data; the data stays there. If a connection fails, say so plainly.\n"
    : "\nBAĞLI UYGULAMALAR: {$list}. Bu uygulamaların araçları kullanıcının o uygulamadaki gerçek verisi üzerinde çalışır, veri orada kalır. Bağlantı hata verirse durumu açıkça söyle, uydurma.\n";
}

if (function_exists('lh_register_tools')) {
  lh_register_tools(
    [],                                    // liste dinamik: asagidaki filtre ile eklenir
    fn(string $n, array $a) => lh_remote_run($n, $a),
    fn(string $lang) => lh_remote_prompt($lang)
  );
  /* Dinamik arac listesi: kayit defteri sabit dizi tuttugu icin araclari
     atlas_tools() cagrildiginda ekleyen bir filtre kullaniyoruz. */
  $GLOBALS['LH_TOOLREG'][count($GLOBALS['LH_TOOLREG']) - 1]['tools_fn'] = 'lh_remote_tools';
}
