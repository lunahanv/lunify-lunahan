<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/**
 * Lunify Atlas — dil yönetimi (TR / EN)
 * Dil sırası: ?lang=xx  >  çerez  >  tarayıcı dili  >  varsayılan (tr)
 */

function atlas_lang(): string {
  static $lang = null;
  if ($lang !== null) return $lang;

  $allowed = ['tr','en'];

  if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed, true)) {
    $lang = $_GET['lang'];
    setcookie('atlas_lang', $lang, time()+31536000, '/');
    $_COOKIE['atlas_lang'] = $lang;
    return $lang;
  }
  if (isset($_COOKIE['atlas_lang']) && in_array($_COOKIE['atlas_lang'], $allowed, true)) {
    return $lang = $_COOKIE['atlas_lang'];
  }
  $al = strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
  if ($al !== '' && strpos($al, 'tr') === false) return $lang = 'en';
  return $lang = 'tr';
}

/** Aynı sayfanın belirli dildeki adresi */
function atlas_lang_url(string $l): string {
  $q = $_GET; $q['lang'] = $l;
  return strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($q);
}

function t(string $key): string {
  static $S = null;
  if ($S === null) $S = require __DIR__ . '/lang.php';
  $l = atlas_lang();
  return $S[$l][$key] ?? $S['tr'][$key] ?? $key;
}

/** Dil seçici HTML'i */
function atlas_lang_switch(string $cls = 'lang-sw'): string {
  $cur = atlas_lang();
  $o = '<div class="'.$cls.'">';
  foreach (['tr'=>'TR','en'=>'EN'] as $code=>$label) {
    $on = $code === $cur ? ' on' : '';
    $o .= '<a class="'.trim($on).'" href="'.htmlspecialchars(atlas_lang_url($code)).'">'.$label.'</a>';
  }
  return $o . '</div>';
}
