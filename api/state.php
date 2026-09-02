<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$u = require_login_api(); $uid = (int)$u['id'];

$q = function(string $sql) use ($uid) { $s = db()->prepare($sql); $s->execute([$uid]); return $s->fetchAll(); };
$cmds = $q('SELECT id,user_text,agent_reply,status,created_at FROM commands WHERE user_id=? ORDER BY id DESC LIMIT 30');
$acts = $q('SELECT id,type,target,payload,meta_json,status,result,created_at FROM actions WHERE user_id=? ORDER BY id DESC LIMIT 30');
$rem  = $q('SELECT id,when_text,body,status,created_at FROM reminders WHERE user_id=? ORDER BY id DESC LIMIT 30');
$con  = $q('SELECT id,name,phone,email FROM contacts WHERE user_id=? ORDER BY name LIMIT 100');
$p = plan_row($u['plan_code']);
echo json_encode(['commands'=>$cmds,'actions'=>$acts,'reminders'=>$rem,'contacts'=>$con,
  'account'=>['email'=>$u['email'],'plan'=>$p['code'],'plan_name'=>$p['name_tr'],
              'status'=>$u['plan_status'],'until'=>$u['plan_until'],
              'used'=>usage_month($uid),'limit'=>(int)$p['monthly_commands']]], JSON_UNESCAPED_UNICODE);
