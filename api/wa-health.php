<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* Atlas — WhatsApp koprusu saglik ucu (izleme icin). */
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if (!WA_BRIDGE_KEY || !hash_equals(WA_BRIDGE_KEY, $key)) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
$uid = (int)($_GET['uid'] ?? 0);
$url = 'http://127.0.0.1:3009/' . ($uid ? 'status?uid=' . $uid . '&key=' . urlencode(WA_BRIDGE_KEY) : 'sessions?key=' . urlencode(WA_BRIDGE_KEY));
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20]);
$res = curl_exec($ch);
if ($res === false) { echo json_encode(['status'=>'unreachable','error'=>curl_error($ch)]); exit; }
$d = json_decode($res, true);
if (!is_array($d)) { echo json_encode(['status'=>'unreachable']); exit; }
unset($d['qr']);
echo json_encode($d, JSON_UNESCAPED_UNICODE);
