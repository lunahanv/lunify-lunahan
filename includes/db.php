<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/secrets.php';
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  return $pdo;
}
/** Kullanıcıya özel ayar (izole). $uid verilmezse aktif kullanıcı. */
function setting(string $k, $default = null, ?int $uid = null) {
  if ($uid === null) $uid = function_exists('atlas_uid') ? atlas_uid() : 0;
  $s = db()->prepare('SELECT v FROM settings WHERE user_id=? AND k=?'); $s->execute([$uid, $k]);
  $r = $s->fetch();
  if (!$r) return $default;
  $secret = ['search_api_key' => true];
  return isset($secret[$k]) && function_exists('hsecOpen') ? hsecOpen($r['v']) : $r['v'];
}
function set_setting(string $k, $v, ?int $uid = null) {
  if ($uid === null) $uid = function_exists('atlas_uid') ? atlas_uid() : 0;
  $secret = ['search_api_key' => true];
  if (isset($secret[$k]) && function_exists('hsecSeal')) $v = hsecSeal($v);
  $s = db()->prepare('INSERT INTO settings(user_id,k,v) VALUES(?,?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
  $s->execute([$uid, $k, $v]);
}
/** Sistem geneli ayar (user_id = 0): ör. Google OAuth istemci bilgileri. */
function gsetting(string $k, $default = null) { return setting($k, $default, 0); }
function set_gsetting(string $k, $v) { set_setting($k, $v, 0); }
