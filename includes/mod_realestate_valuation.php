<?php
/**
 * Copyright (c) 2025-2026 Lunify A.S. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.S.'nin ticari sirridir.
 */

/* LUNAHAN — EMLAK MODULU / ARAZI DEGERLEME (ada-parsel)
   TKGM parsel sorgu API'si (megsiswebapi v3.1, anahtar gerekmez) + kullanici emsal havuzu.
   Ciktisi resmi (SPK) degerleme raporu DEGILDIR, bilgilendirme amacli tahmindir. */

define('RV_TKGM_HOST', 'https://cbsapi.tkgm.gov.tr/megsiswebapi.v3.1/api');

/* ---------------- yardimcilar ---------------- */

function rv_http_get(string $url, int $timeout = 15): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT      => 'LunifyLUNAHAN/1.0',
  ]);
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($body === false || $code !== 200) return null;
  $j = json_decode((string)$body, true);
  return is_array($j) ? $j : null;
}

/** TKGM "37.002,94" -> 37002.94 */
function rv_area_to_float(?string $s): ?float {
  if ($s === null || trim($s) === '') return null;
  $s = str_replace('.', '', trim($s));
  $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : null;
}

function rv_tl(?float $v): string {
  if ($v === null) return '-';
  return number_format($v, 0, ',', '.') . ' TL';
}

/** GeoJSON polygon -> kaba merkez (centroid yerine ortalama kose) */
function rv_centroid(array $geometry): ?array {
  $c = $geometry['coordinates'] ?? null;
  if (!is_array($c)) return null;
  $pts = $c[0] ?? null;
  if (!is_array($pts) || !$pts) return null;
  $sx = 0.0; $sy = 0.0; $n = 0;
  foreach ($pts as $p) {
    if (!is_array($p) || count($p) < 2) continue;
    $sx += (float)$p[0]; $sy += (float)$p[1]; $n++;
  }
  if (!$n) return null;
  return ['lon' => round($sx / $n, 6), 'lat' => round($sy / $n, 6)];
}

/* ---------------- TKGM idari yapi ---------------- */

/** Il listesi TKGM API'sinde yok; parsel sorgu uygulamasinin statik listesi kullanilir. */
function rv_province_id(string $name): ?int {
  static $cache = null;
  if ($cache === null) {
    $cache = [];
    $j = rv_http_get('https://parselsorgu.tkgm.gov.tr/app/modules/administrativeQuery/data/ilListe.json', 25);
    foreach (($j['features'] ?? []) as $f) {
      $p = $f['properties'] ?? [];
      if (isset($p['text'], $p['id'])) $cache[re_norm((string)$p['text'])] = (int)$p['id'];
    }
  }
  $k = re_norm($name);
  return $cache[$k] ?? null;
}

function rv_pick_id(?array $geojson, string $name): ?int {
  if (!$geojson) return null;
  $want = re_norm($name);
  $rows = [];
  foreach (($geojson['features'] ?? []) as $f) {
    $p = $f['properties'] ?? [];
    if (!isset($p['text'], $p['id'])) continue;
    $rows[] = ['t' => re_norm((string)$p['text']), 'id' => (int)$p['id']];
  }
  foreach ($rows as $r) if ($r['t'] === $want) return $r['id'];
  foreach ($rows as $r) if (strpos($r['t'], $want) === 0) return $r['id'];
  foreach ($rows as $r) if ($want !== '' && strpos($r['t'], $want) !== false) return $r['id'];
  return null;
}

function rv_district_id(int $ilId, string $name): ?int {
  return rv_pick_id(rv_http_get(RV_TKGM_HOST . '/idariYapi/ilceListe/' . $ilId, 20), $name);
}

function rv_neighborhood_id(int $ilceId, string $name): ?int {
  return rv_pick_id(rv_http_get(RV_TKGM_HOST . '/idariYapi/mahalleListe/' . $ilceId, 20), $name);
}

/**
 * Ada/parsel -> TKGM kaydi.
 * @return array{ok:bool,msg?:string,data?:array}
 */
function rv_parcel(string $il, string $ilce, string $mahalle, string $ada, string $parsel): array {
  $ilId = rv_province_id($il);
  if (!$ilId) return ['ok' => false, 'msg' => 'Il bulunamadi: ' . $il];
  $ilceId = rv_district_id($ilId, $ilce);
  if (!$ilceId) return ['ok' => false, 'msg' => 'Ilce bulunamadi: ' . $ilce . ' (' . $il . ')'];
  $mahId = rv_neighborhood_id($ilceId, $mahalle);
  if (!$mahId) return ['ok' => false, 'msg' => 'Mahalle/koy bulunamadi: ' . $mahalle . ' (' . $ilce . ')'];

  $ada = preg_replace('/[^0-9]/', '', $ada);
  $parsel = preg_replace('/[^0-9]/', '', $parsel);
  if ($ada === '' || $parsel === '') return ['ok' => false, 'msg' => 'Ada ve parsel numarasi gerekli.'];

  $j = rv_http_get(RV_TKGM_HOST . '/parsel/' . $mahId . '/' . $ada . '/' . $parsel, 20);
  if (!$j || empty($j['properties'])) {
    return ['ok' => false, 'msg' => 'TKGM kaydi bulunamadi: ' . $ada . ' ada ' . $parsel . ' parsel (' . $mahalle . '/' . $ilce . ')'];
  }
  $p = $j['properties'];
  $ctr = isset($j['geometry']) && is_array($j['geometry']) ? rv_centroid($j['geometry']) : null;
  $area = rv_area_to_float($p['alan'] ?? null);

  return ['ok' => true, 'data' => [
    'il'          => (string)($p['ilAd'] ?? $il),
    'ilce'        => (string)($p['ilceAd'] ?? $ilce),
    'mahalle'     => (string)($p['mahalleAd'] ?? $mahalle),
    'mahalle_id'  => $mahId,
    'ada'         => (string)($p['adaNo'] ?? $ada),
    'parsel'      => (string)($p['parselNo'] ?? $parsel),
    'nitelik'     => (string)($p['nitelik'] ?? ''),
    'mevkii'      => (string)($p['mevkii'] ?? ''),
    'pafta'       => (string)($p['pafta'] ?? ''),
    'zemin_durum' => (string)($p['zeminKmdurum'] ?? ''),
    'area_m2'     => $area,
    'lat'         => $ctr['lat'] ?? null,
    'lon'         => $ctr['lon'] ?? null,
    'map_url'     => $ctr ? ('https://maps.google.com/?q=' . $ctr['lat'] . ',' . $ctr['lon']) : null,
  ]];
}

/* ---------------- emsal havuzu ---------------- */

function rv_nitelik_factor(string $nitelik): float {
  $n = re_norm($nitelik);
  if (strpos($n, 'arsa') !== false) return 1.60;
  if (strpos($n, 'zeytin') !== false || strpos($n, 'bag') !== false || strpos($n, 'bahce') !== false) return 1.15;
  if (strpos($n, 'ham toprak') !== false || strpos($n, 'mera') !== false || strpos($n, 'orman') !== false) return 0.65;
  if (strpos($n, 'sulu') !== false) return 1.20;
  return 1.00; /* tarla / kuru tarim */
}

/** Emsal ile hedefin kiyaslanabilir olmasi icin kaba nitelik sinifi */
function rv_nitelik_class(string $nitelik): string {
  $n = re_norm($nitelik);
  if ($n === '') return 'tarla';
  if (strpos($n, 'arsa') !== false || strpos($n, 'imar') !== false || strpos($n, 'sanayi') !== false
      || strpos($n, 'ticari') !== false || strpos($n, 'konut') !== false || strpos($n, 'osb') !== false) return 'arsa';
  if (strpos($n, 'zeytin') !== false || strpos($n, 'bag') !== false || strpos($n, 'bahce') !== false) return 'bahce';
  if (strpos($n, 'ham toprak') !== false || strpos($n, 'mera') !== false || strpos($n, 'orman') !== false) return 'ham';
  return 'tarla';
}

/** Buyuk parselde donum fiyati duser */
function rv_size_factor(?float $area): float {
  if ($area === null || $area <= 0) return 1.00;
  if ($area >= 100000) return 0.80;
  if ($area >= 50000)  return 0.85;
  if ($area >= 20000)  return 0.92;
  if ($area <= 2000)   return 1.10;
  return 1.00;
}

function rv_median(array $vals): ?float {
  $vals = array_values(array_filter($vals, static fn($v) => $v > 0));
  if (!$vals) return null;
  sort($vals);
  $n = count($vals);
  return $n % 2 ? (float)$vals[intdiv($n, 2)] : (float)(($vals[$n / 2 - 1] + $vals[$n / 2]) / 2);
}

/** Emsalleri kademeli topla: mahalle -> ilce -> il, ayni nitelik sinifindan en az $min tane olana kadar */
function rv_find_comps(int $uid, string $il, string $ilce, string $mahalle, string $nitelik = '', int $min = 3, int $limit = 40): array {
  $class = rv_nitelik_class($nitelik);
  $levels = [
    ['scope' => 'mahalle', 'sql' => 'LOWER(il)=? AND LOWER(ilce)=? AND LOWER(mahalle)=?', 'p' => [re_norm($il), re_norm($ilce), re_norm($mahalle)]],
    ['scope' => 'ilce',    'sql' => 'LOWER(il)=? AND LOWER(ilce)=?',                       'p' => [re_norm($il), re_norm($ilce)]],
    ['scope' => 'il',      'sql' => 'LOWER(il)=?',                                         'p' => [re_norm($il)]],
  ];
  if (trim($mahalle) === '') array_shift($levels);

  $rows = []; $seen = []; $scopes = [];
  foreach ($levels as $lv) {
    $st = db()->prepare('SELECT id,il,ilce,mahalle,nitelik,area_m2,price,unit_price,source,source_url,comp_date,notes
                         FROM re_comps WHERE user_id=? AND ' . $lv['sql'] . ' ORDER BY comp_date DESC, id DESC LIMIT ' . (int)$limit);
    $st->execute(array_merge([$uid], $lv['p']));
    $added = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (isset($seen[$r['id']])) continue;
      if (rv_nitelik_class((string)($r['nitelik'] ?? '')) !== $class) continue; /* elma-armut karsilastirmasi yok */
      $seen[$r['id']] = true; $rows[] = $r; $added++;
    }
    if ($added > 0) $scopes[] = $lv['scope'];
    if (count($rows) >= $min) break;
  }
  return ['scope' => $scopes ? implode('+', $scopes) : 'yok', 'class' => $class, 'rows' => $rows];
}

function rv_tool_add_comp(array $a): string {
  $uid = atlas_uid();
  $area  = ($a['area_m2'] ?? '') !== '' ? (float)$a['area_m2'] : null;
  $price = re_money((string)($a['price'] ?? ''));
  $unit  = ($a['unit_price'] ?? '') !== '' ? (float)re_money((string)$a['unit_price']) : null;
  if (!$unit && $price && $area && $area > 0) $unit = $price / $area;
  if (!$unit) return 'Emsal icin ya m2 birim fiyati ya da (toplam fiyat + m2) gerekli.';

  $il = trim((string)($a['il'] ?? ''));
  $ilce = trim((string)($a['ilce'] ?? ''));
  if ($il === '' || $ilce === '') return 'Emsalin ili ve ilcesi gerekli.';
  $date = trim((string)($a['comp_date'] ?? ''));
  $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');

  $s = db()->prepare('INSERT INTO re_comps(user_id,il,ilce,mahalle,nitelik,area_m2,price,unit_price,source,source_url,comp_date,notes)
                      VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
  $s->execute([$uid, mb_substr($il, 0, 60), mb_substr($ilce, 0, 60),
    mb_substr(trim((string)($a['mahalle'] ?? '')), 0, 80),
    mb_substr(trim((string)($a['nitelik'] ?? '')), 0, 60),
    $area, $price, round($unit, 2),
    mb_substr(trim((string)($a['source'] ?? 'kullanici')), 0, 60),
    mb_substr(trim((string)($a['source_url'] ?? '')), 0, 300),
    $date, mb_substr(trim((string)($a['notes'] ?? '')), 0, 500)]);

  return 'Emsal eklendi (#' . (int)db()->lastInsertId() . '): ' . $il . '/' . $ilce
    . ($a['mahalle'] ?? '' ? '/' . $a['mahalle'] : '') . ' — ' . number_format($unit, 0, ',', '.') . ' TL/m2 [' . $date . ']';
}

function rv_tool_list_comps(array $a): string {
  $uid = atlas_uid();
  $w = ['user_id=?']; $p = [$uid];
  foreach (['il', 'ilce', 'mahalle'] as $k) {
    if (!empty($a[$k])) { $w[] = "LOWER($k)=?"; $p[] = re_norm((string)$a[$k]); }
  }
  $s = db()->prepare('SELECT id,il,ilce,mahalle,nitelik,area_m2,price,unit_price,source,comp_date
                      FROM re_comps WHERE ' . implode(' AND ', $w) . ' ORDER BY comp_date DESC, id DESC LIMIT 30');
  $s->execute($p);
  $rows = $s->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 'Emsal havuzunda kayit yok. re_add_comp ile emsal ekleyebilirsin.';
  return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

/* ---------------- parsel sorgu araci ---------------- */

function rv_tool_parcel(array $a): string {
  $r = rv_parcel((string)($a['il'] ?? ''), (string)($a['ilce'] ?? ''), (string)($a['mahalle'] ?? ''),
                 (string)($a['ada'] ?? ''), (string)($a['parsel'] ?? ''));
  if (!$r['ok']) return 'TKGM sorgusu basarisiz: ' . $r['msg'];
  $d = $r['data'];
  $out = 'TKGM KAYDI: ' . $d['il'] . '/' . $d['ilce'] . '/' . $d['mahalle'] . ' — ' . $d['ada'] . ' ada ' . $d['parsel'] . ' parsel'
    . "\nYuzolcumu: " . number_format((float)$d['area_m2'], 2, ',', '.') . ' m2 (' . round(((float)$d['area_m2']) / 1000, 2) . ' donum)'
    . "\nNitelik: " . $d['nitelik'] . ' | Mevkii: ' . $d['mevkii'] . ' | Pafta: ' . $d['pafta'] . ' | Durum: ' . $d['zemin_durum'];
  if ($d['map_url']) $out .= "\nHarita: " . $d['map_url'];
  $out .= "\n(Kaynak: TKGM Parsel Sorgu. Malik/hisse bilgisi bu serviste yer almaz.)";
  return $out;
}

/* ---------------- degerleme ---------------- */

function rv_tool_valuate(array $a): string {
  $uid = atlas_uid();
  $il = trim((string)($a['il'] ?? ''));
  $ilce = trim((string)($a['ilce'] ?? ''));
  $mahalle = trim((string)($a['mahalle'] ?? ''));
  $nitelik = trim((string)($a['nitelik'] ?? ''));
  $area = ($a['area_m2'] ?? '') !== '' ? (float)$a['area_m2'] : null;
  $head = '';

  /* ada/parsel verilmisse TKGM'den gercek veriyi al */
  if (!empty($a['ada']) && !empty($a['parsel']) && $il !== '' && $ilce !== '' && $mahalle !== '') {
    $r = rv_parcel($il, $ilce, $mahalle, (string)$a['ada'], (string)$a['parsel']);
    if ($r['ok']) {
      $d = $r['data'];
      $il = $d['il']; $ilce = $d['ilce']; $mahalle = $d['mahalle'];
      $area = $d['area_m2'] ?: $area;
      $nitelik = $nitelik !== '' ? $nitelik : $d['nitelik'];
      $head = 'PARSEL: ' . $il . '/' . $ilce . '/' . $mahalle . ' — ' . $d['ada'] . ' ada ' . $d['parsel'] . ' parsel'
        . ' | ' . number_format((float)$area, 2, ',', '.') . ' m2 | Nitelik: ' . $nitelik
        . ($d['mevkii'] ? ' | Mevkii: ' . $d['mevkii'] : '')
        . ($d['map_url'] ? "\nHarita: " . $d['map_url'] : '') . "\n";
    } else {
      $head = 'NOT: TKGM kaydi cekilemedi (' . $r['msg'] . "), verdigin bilgilerle hesapladim.\n";
    }
  }

  if (!$area || $area <= 0) return 'Degerleme icin arazinin m2 buyuklugu gerekli (ya da il/ilce/mahalle + ada/parsel ver, TKGM\'den cekerim).';
  if ($il === '' || $ilce === '') return 'Degerleme icin en az il ve ilce gerekli.';

  $found = rv_find_comps($uid, $il, $ilce, $mahalle, $nitelik);
  $rows = $found['rows'];

  /* kullanici dogrudan birim fiyat verdiyse onu da emsal say */
  $manual = ($a['unit_price'] ?? '') !== '' ? (float)re_money((string)$a['unit_price']) : null;

  /* Duzeltme tablosu: her emsali kendi nitelik ve buyukluk katsayisina bolerek
     "tarla esdegeri / normal buyukluk" birim fiyatina indirger. Boylece arsa,
     sanayi arsasi ve buyuk tarla emsalleri ayni olcege gelir. */
  $units = [];
  foreach ($rows as $r) {
    $u = (float)$r['unit_price'];
    if ($u <= 0) continue;
    $d = rv_nitelik_factor((string)($r['nitelik'] ?? '')) * rv_size_factor($r['area_m2'] !== null ? (float)$r['area_m2'] : null);
    if ($d > 0) $units[] = $u / $d;
  }
  if ($manual) $units[] = $manual; /* kullanicinin verdigi birim fiyat hedefe ozel kabul edilir */
  $base = rv_median($units);

  if (!$base) {
    return $head . 'Bu bolge icin emsal yok, bu yuzden rakam uydurmuyorum.'
      . "\nYapilacak: emsal ekle (re_add_comp) — ornek: \"Honaz Gurlek tarla 700 TL/m2, kaynak icra ilani 2025\"."
      . "\nVeya bana bir m2 birim fiyati soyle, ona gore hesaplayayim."
      . "\nParsel: " . number_format($area, 2, ',', '.') . ' m2 (' . round($area / 1000, 2) . ' donum), ' . $il . '/' . $ilce . ($mahalle ? '/' . $mahalle : '');
  }

  $fN = rv_nitelik_factor($nitelik);
  $fS = rv_size_factor($area);
  $unit = $base * $fN * $fS;
  $mid  = $unit * $area;

  $out  = $head;
  $out .= 'DEGER TAHMINI (' . number_format($area, 2, ',', '.') . ' m2 = ' . round($area / 1000, 2) . ' donum)' . "\n";
  $out .= 'Duzeltilmis emsal medyani (sinif esdegeri): ' . number_format($base, 0, ',', '.') . ' TL/m2 (' . count($rows) . ' emsal, kapsam: ' . $found['scope'] . ', sinif: ' . $found['class'] . ')' . "\n";
  $out .= 'Duzeltmeler: nitelik x' . number_format($fN, 2, ',', '.') . ' (' . ($nitelik ?: 'belirsiz') . '), buyukluk x' . number_format($fS, 2, ',', '.') . "\n";
  $out .= 'Duzeltilmis birim: ' . number_format($unit, 0, ',', '.') . ' TL/m2' . "\n";
  $out .= '— Hizli satis (0,85x): ' . rv_tl($mid * 0.85) . "\n";
  $out .= '— Gercekci piyasa: ' . rv_tl($mid) . "\n";
  $out .= '— Ilana cikis (1,20x): ' . rv_tl($mid * 1.20) . "\n";
  if ($rows) {
    $list = [];
    foreach (array_slice($rows, 0, 6) as $r) {
      $d = rv_nitelik_factor((string)($r['nitelik'] ?? '')) * rv_size_factor($r['area_m2'] !== null ? (float)$r['area_m2'] : null);
      $list[] = ['yer' => trim($r['il'] . '/' . $r['ilce'] . '/' . (string)$r['mahalle'], '/'),
                 'nitelik' => $r['nitelik'], 'm2' => $r['area_m2'],
                 'tl_m2' => (float)$r['unit_price'],
                 'duzeltilmis_tl_m2' => $d > 0 ? round(((float)$r['unit_price']) / $d) : null,
                 'kaynak' => $r['source'], 'tarih' => $r['comp_date']];
    }
    $out .= 'KULLANILAN EMSALLER: ' . json_encode($list, JSON_UNESCAPED_UNICODE) . "\n";
  }
  $out .= 'UYARI: Bu bir otomatik deger tahminidir, SPK lisansli degerleme raporu degildir. Imar/OSB/kamulastirma durumu degeri ciddi degistirir.';
  return $out;
}

/* ---------------- arac tanimlari ---------------- */

function rv_tools(): array {
  return [
    ['type' => 'function', 'function' => ['name' => 're_parcel', 'description' => 'EMLAK/ARAZI: TKGM parsel sorgusu. Ada-parsel verilince resmi yuzolcumu, nitelik (tarla/arsa/bag), mevkii, pafta ve harita konumunu getirir. Kullanici "X ada Y parsel" dediginde once bunu cagir.', 'parameters' => ['type' => 'object', 'properties' => [
      'il'      => ['type' => 'string', 'description' => 'or. Denizli'],
      'ilce'    => ['type' => 'string', 'description' => 'or. Honaz'],
      'mahalle' => ['type' => 'string', 'description' => 'mahalle/koy adi, or. Gurlek'],
      'ada'     => ['type' => 'string'],
      'parsel'  => ['type' => 'string'],
    ], 'required' => ['il', 'ilce', 'mahalle', 'ada', 'parsel']]]],

    ['type' => 'function', 'function' => ['name' => 're_valuate', 'description' => 'EMLAK/ARAZI: arazi-arsa deger tahmini. "Bu parsel kac para eder / kac eder / degeri ne" sorularinda cagir. Ada-parsel verilirse TKGM\'den yuzolcumu ve nitelik otomatik alinir, emsal havuzundan (re_comps) medyan m2 fiyati ile hizli satis / piyasa / ilan araligi hesaplanir. Emsal yoksa rakam uretmez, emsal ister.', 'parameters' => ['type' => 'object', 'properties' => [
      'il'         => ['type' => 'string'],
      'ilce'       => ['type' => 'string'],
      'mahalle'    => ['type' => 'string'],
      'ada'        => ['type' => 'string'],
      'parsel'     => ['type' => 'string'],
      'area_m2'    => ['type' => 'number', 'description' => 'ada/parsel yoksa zorunlu'],
      'nitelik'    => ['type' => 'string', 'description' => 'tarla, arsa, bag, zeytinlik, sulu tarim...'],
      'unit_price' => ['type' => 'string', 'description' => 'kullanici bir m2 fiyati soylerse, or. "700 TL"'],
    ], 'required' => []]]],

    ['type' => 'function', 'function' => ['name' => 're_add_comp', 'description' => 'EMLAK/ARAZI: emsal (karsilastirilabilir satis/ilan/icra muhammen bedeli) kaydeder. Kullanici bir bolge icin m2 fiyati ya da "su kadar m2 su kadar TL" bilgisi verirse cagir. Degerlemenin dogrulugu bu havuza baglidir.', 'parameters' => ['type' => 'object', 'properties' => [
      'il'         => ['type' => 'string'], 'ilce' => ['type' => 'string'], 'mahalle' => ['type' => 'string'],
      'nitelik'    => ['type' => 'string'],
      'area_m2'    => ['type' => 'number'],
      'price'      => ['type' => 'string', 'description' => 'toplam fiyat, or. "6 milyon 942 bin"'],
      'unit_price' => ['type' => 'string', 'description' => 'm2 birim fiyati, or. "685 TL"'],
      'source'     => ['type' => 'string', 'description' => 'icra ilani, ilan, satis, rayic, komsu beyani...'],
      'source_url' => ['type' => 'string'],
      'comp_date'  => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
      'notes'      => ['type' => 'string'],
    ], 'required' => ['il', 'ilce']]]],

    ['type' => 'function', 'function' => ['name' => 're_list_comps', 'description' => 'EMLAK/ARAZI: emsal havuzunu listeler (il/ilce/mahalle filtresi ile).', 'parameters' => ['type' => 'object', 'properties' => [
      'il' => ['type' => 'string'], 'ilce' => ['type' => 'string'], 'mahalle' => ['type' => 'string'],
    ], 'required' => []]]],
  ];
}

function rv_run_tool(string $name, array $args): ?string {
  switch ($name) {
    case 're_parcel':     return rv_tool_parcel($args);
    case 're_valuate':    return rv_tool_valuate($args);
    case 're_add_comp':   return rv_tool_add_comp($args);
    case 're_list_comps': return rv_tool_list_comps($args);
  }
  return null;
}

function rv_system_prompt(string $lang = 'tr'): string {
  if ($lang === 'en') {
    return ' LAND VALUATION: for "how much is parcel X worth" call re_valuate (it pulls official area/quality from TKGM when block/parcel is given and uses the user comparable pool).'
      . ' For a plain land-registry lookup call re_parcel. Store comparables with re_add_comp. Never invent a price when there are no comparables — ask for one.'
      . ' Always state that the result is an automated estimate, not an SPK licensed appraisal.';
  }
  return ' ARAZI DEGERLEME: "bu parsel kac para eder / degeri ne kadar" sorularinda re_valuate cagir; ada-parsel verildiyse yuzolcumu ve nitelik TKGM\'den otomatik gelir.'
    . ' Sadece tapu bilgisi isteniyorsa re_parcel yeterlidir. Kullanici bir bolge icin m2 fiyati/satis bilgisi verirse re_add_comp ile emsal olarak kaydet (emsal havuzu buyudukce tahmin keskinlesir).'
    . ' EMSAL YOKSA RAKAM UYDURMA — aracin dedigi gibi emsal iste. Cevapta hizli satis / gercekci piyasa / ilana cikis araligini ver, kullandigin emsal sayisini ve kapsamini soyle.'
    . ' Her degerleme cevabinin sonunda "otomatik tahmin, SPK degerleme raporu degildir" uyarisini yaz ve imar/OSB/kamulastirma durumunun degeri degistirebilecegini hatirlat.';
}
