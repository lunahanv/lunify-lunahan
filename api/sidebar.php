<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** LUNAHAN — kullaniciya ozel sol menu sirasi */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); header('Content-Type: application/json'); echo json_encode(['error'=>'method not allowed']); exit; }
$u = require_login_api();
$act = $_GET['action'] ?? 'save';
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if ($act === 'reset') { set_setting('sidebar_order', ''); echo json_encode(['ok'=>true]); exit; }
$order = array_values(array_filter(array_map(
  fn($k) => preg_replace('/[^a-z_]/', '', (string)$k), (array)($in['order'] ?? []))));
set_setting('sidebar_order', json_encode($order));
echo json_encode(['ok'=>true]);
