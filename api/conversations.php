<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — sohbet (konusma) listesi: listele / mesajlari getir / yeni / yeniden adlandir / sil */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$u = require_login_api();
$uid = (int)$u['id'];
$act = $_GET['action'] ?? $_POST['action'] ?? 'list';

function conv_owned(int $id, int $uid): bool {
  $s = db()->prepare('SELECT 1 FROM conversations WHERE id=? AND user_id=?');
  $s->execute([$id, $uid]);
  return (bool)$s->fetchColumn();
}

if ($act === 'list') {
  $s = db()->prepare('SELECT c.id, c.title, c.updated_at,
      (SELECT COUNT(*) FROM commands m WHERE m.conv_id=c.id) AS n
      FROM conversations c WHERE c.user_id=? ORDER BY c.updated_at DESC LIMIT 60');
  $s->execute([$uid]);
  echo json_encode(['ok' => true, 'items' => $s->fetchAll()], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($act === 'messages') {
  $id = (int)($_GET['id'] ?? 0);
  if (!$id || !conv_owned($id, $uid)) { echo json_encode(['ok' => false, 'error' => 'yok']); exit; }
  $s = db()->prepare('SELECT id,user_text,agent_reply,tools_json,created_at FROM commands
                      WHERE user_id=? AND conv_id=? ORDER BY id ASC LIMIT 200');
  $s->execute([$uid, $id]);
  echo json_encode(['ok' => true, 'items' => $s->fetchAll()], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($act === 'new') {
  $t = trim((string)($_POST['title'] ?? '')) ?: 'Yeni sohbet';
  $s = db()->prepare('INSERT INTO conversations(user_id,title) VALUES(?,?)');
  $s->execute([$uid, mb_substr($t, 0, 150)]);
  echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
  exit;
}

if ($act === 'rename') {
  $id = (int)($_POST['id'] ?? 0);
  $t = trim((string)($_POST['title'] ?? ''));
  if (!$id || $t === '' || !conv_owned($id, $uid)) { echo json_encode(['ok' => false]); exit; }
  db()->prepare('UPDATE conversations SET title=? WHERE id=? AND user_id=?')
     ->execute([mb_substr($t, 0, 150), $id, $uid]);
  echo json_encode(['ok' => true]);
  exit;
}

if ($act === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if (!$id || !conv_owned($id, $uid)) { echo json_encode(['ok' => false]); exit; }
  db()->prepare('DELETE FROM commands WHERE user_id=? AND conv_id=?')->execute([$uid, $id]);
  db()->prepare('DELETE FROM conversations WHERE id=? AND user_id=?')->execute([$id, $uid]);
  echo json_encode(['ok' => true]);
  exit;
}

echo json_encode(['ok' => false, 'error' => 'bilinmeyen islem']);
