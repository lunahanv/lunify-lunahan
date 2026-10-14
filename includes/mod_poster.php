<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Izinsiz kopyalama, dagitim veya tersine muhendislik yasaktir.
 */

/* LUNAHAN — ilan afisi araci (Viktor 2026-09-10, sahibin istegi).
   Kullanici sohbette "bu ilanin afisini yap" deyip metni verince model alanlari
   cikarir, arac hazir afis linkini doner (poster.php alanlari dolu acilir). */

if (!function_exists('lh_poster_link')) {
  function lh_poster_link(array $a, string $base = ''): string {
    $feat = $a['features'] ?? ($a['feat'] ?? []);
    if (is_string($feat)) $feat = preg_split('~[\n;,]+~u', $feat);
    $feat = array_slice(array_values(array_filter(array_map('trim', (array)$feat))), 0, 7);
    $d = [
      'kick'  => trim((string)($a['kicker'] ?? $a['location'] ?? '')),
      'title' => trim((string)($a['title'] ?? '')),
      'sub'   => trim((string)($a['subtitle'] ?? '')),
      'badge' => trim((string)($a['badge'] ?? '')),
      'feat'  => implode("\n", $feat),
      'price' => trim((string)($a['price'] ?? '')),
      'note'  => trim((string)($a['price_note'] ?? '')),
      'phone' => trim((string)($a['phone'] ?? '')),
    ];
    $q = rtrim(strtr(base64_encode(json_encode($d, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    if ($base === '') {
      $host = $_SERVER['HTTP_HOST'] ?? 'lunahan.com.tr';
      $base = 'https://' . $host . '/poster.php';
    }
    return $base . '?d=' . $q;
  }
}

lh_register_tools(
  [
    ['type' => 'function', 'function' => [
      'name' => 'make_poster',
      'description' => 'Emlak/ilan afisi (poster) hazirlar. Kullanici bir ilan metni verip "afis yap / poster olustur / gorsel ilan hazirla" derse cagir.'
        . ' Metinden basligi, bolgeyi, ozellikleri, fiyati ve telefonu KENDIN cikar. Arac hazir bir link doner:'
        . ' kullaniciya linki ver, "fotograflari yukleyip PNG indirebilirsin, renk temasini ve boyutu (dikey/kare/story) secebilirsin" de.',
      'parameters' => ['type' => 'object', 'properties' => [
        'title'      => ['type' => 'string', 'description' => 'Ana baslik, or. "Kiralik 2+1"'],
        'kicker'     => ['type' => 'string', 'description' => 'Ust satir / bolge, or. "Siteler Mahallesi\'nde"'],
        'subtitle'   => ['type' => 'string', 'description' => 'Alt serit, or. "Full Esyali Apart"'],
        'badge'      => ['type' => 'string', 'description' => 'Kose etiketi: Satilik veya Kiralik'],
        'features'   => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'En fazla 7 madde (oda, kat, m2, konum avantajlari)'],
        'price'      => ['type' => 'string', 'description' => 'Fiyat metni, or. "17.000 TL"'],
        'price_note' => ['type' => 'string', 'description' => 'Fiyat notu, or. "Kira + Depozito + Komisyon"'],
        'phone'      => ['type' => 'string', 'description' => 'Ilan telefonu'],
      ], 'required' => ['title']],
    ]],
  ],
  function (string $name, array $args) {
    if ($name !== 'make_poster') return null;
    $link = lh_poster_link($args);
    return "AFIS HAZIR. Kullaniciya bu linki ver: $link\n"
      . "Linkte alanlar dolu gelir; kullanici fotograflari yukler, renk temasini (5 hazir tema) ve boyutu secer, PNG indirir.";
  },
  function (string $lang) {
    return $lang === 'en'
      ? " POSTER: if the user wants a listing flyer/poster, call make_poster and give them the returned link."
      : " AFIS: kullanici ilan afisi/poster/gorsel ilan isterse make_poster cagir ve donen linki paylas; alanlari ilan metninden kendin doldur.";
  }
);
