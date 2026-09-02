<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/* Lunify Atlas — EMLAK MODÜLÜ (sektör modülü #1)
   Modül kullanıcı bazlıdır: settings[k='mod_realestate']='1' ise açıktır.
   Portföy (re_listings) + müşteri talebi (re_leads) + otomatik eşleştirme + takip. */

function re_enabled(?int $uid = null): bool {
  $uid = $uid ?: atlas_uid();
  /* 2026-08-31: modul TUM uyelerde STANDART ACIK. Sadece acikca '0' yazilirsa kapanir. */
  try {
    $s = db()->prepare("SELECT v FROM settings WHERE user_id=? AND k='mod_realestate'");
    $s->execute([$uid]);
    $v = $s->fetchColumn();
    if ($v === false || $v === null || trim((string)$v) === '') return true;
    return trim((string)$v) !== '0';
  } catch (Throwable $e) { return true; }
}

function re_norm(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $tr = ['ı'=>'i','İ'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c','â'=>'a','î'=>'i'];
  return strtr($s, $tr);
}

/** Serbest metinden TL tutari: "6 milyon", "5 milyon 800 bin", "5.800.000", "25 bin", "6.5m" */
function re_money(?string $t): ?int {
  if ($t === null) return null;
  $t = re_norm((string)$t);
  if ($t === '') return null;
  $t = str_replace(['tl', 'try', '₺'], ' ', $t);
  $total = 0.0; $found = false;
  /* sirayla sayi + (opsiyonel) birim gruplarini topla: "5 milyon 800 bin" */
  if (preg_match_all('/([0-9]+(?:[.,][0-9]+)*)\s*(milyon|mio|bin|[mk])?/u', $t, $ms, PREG_SET_ORDER)) {
    foreach ($ms as $m) {
      $numRaw = $m[1]; $unit = $m[2] ?? '';
      /* "5.800.000" gibi binlik ayracli tam sayi: noktalari kaldir */
      if ($unit === '' && preg_match('/^[0-9]{1,3}(\.[0-9]{3})+$/', $numRaw)) {
        $val = (float)str_replace('.', '', $numRaw);
      } else {
        $val = (float)str_replace(',', '.', $numRaw);
      }
      if ($val <= 0) continue;
      if ($unit === 'milyon' || $unit === 'mio' || $unit === 'm') $val *= 1000000;
      elseif ($unit === 'bin' || $unit === 'k') $val *= 1000;
      $total += $val; $found = true;
    }
  }
  if (!$found || $total <= 0) return null;
  return (int)round($total);
}

/** Rehberden/kopruden gelen numarayi dogrula (WhatsApp LID gibi sahte numaralari ele) */
function re_phone_ok(string $p): bool {
  $p = preg_replace('/[^0-9]/', '', $p);
  if ($p === '') return false;
  if (strlen($p) > 15 || strlen($p) < 10) return false;
  /* TR ve yaygin ulke kodlari ya da 0'li yerel format */
  return (bool)preg_match('/^(90[0-9]{10}|0[0-9]{10}|4[0-9]{9,13}|[1-9][0-9]{9,14})$/', $p) && !preg_match('/^1[0-9]{10}$/', $p);
}

function re_rooms(?string $t): ?string {
  if (!$t) return null;
  $t = re_norm($t);
  if (preg_match('/(\d)\s*\+\s*(\d)/', $t, $m)) return $m[1] . '+' . $m[2];
  if (preg_match('/studyo|stüdyo|1\s*\+\s*0/', $t)) return '1+0';
  return trim($t) !== '' ? mb_substr(trim($t), 0, 12) : null;
}

/* ---------------- PORTFÖY ---------------- */

function re_tool_add_listing(array $a): string {
  $uid = atlas_uid();
  $price = re_money((string)($a['price'] ?? ''));
  $deal  = re_norm((string)($a['deal'] ?? 'satilik'));
  $deal  = (strpos($deal, 'kira') !== false || strpos($deal, 'rent') !== false) ? 'kiralik' : 'satilik';
  $title = trim((string)($a['title'] ?? ''));
  if ($title === '') {
    $title = trim(($a['rooms'] ?? '') . ' ' . ($a['ptype'] ?? 'daire') . ' — ' . ($a['location'] ?? ''));
  }
  $s = db()->prepare('INSERT INTO re_listings(user_id,title,deal,ptype,rooms,area_m2,price,location,floor_no,owner_name,owner_phone,features,notes,status)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,\'aktif\')');
  $s->execute([$uid, mb_substr($title,0,190), $deal,
    mb_substr(trim((string)($a['ptype'] ?? 'daire')),0,60),
    re_rooms((string)($a['rooms'] ?? '')),
    ($a['area_m2'] ?? null) !== null && $a['area_m2'] !== '' ? (int)$a['area_m2'] : null,
    $price, mb_substr(trim((string)($a['location'] ?? '')),0,190),
    mb_substr(trim((string)($a['floor_no'] ?? '')),0,30),
    mb_substr(trim((string)($a['owner_name'] ?? '')),0,120),
    preg_replace('/[^0-9+]/','', (string)($a['owner_phone'] ?? '')),
    mb_substr(trim((string)($a['features'] ?? '')),0,500),
    mb_substr(trim((string)($a['notes'] ?? '')),0,1000)]);
  $id = (int)db()->lastInsertId();
  $out = 'Portföye eklendi (#' . $id . '): ' . $title . ($price ? ' — ' . number_format($price, 0, ',', '.') . ' TL' : '') . ' [' . $deal . ']';
  $m = re_match_for_listing_rows($id);
  if ($m) {
    $out .= "\nBU EVE UYAN MÜŞTERİLER: " . json_encode($m, JSON_UNESCAPED_UNICODE);
  }
  return $out;
}

function re_tool_list_listings(array $a): string {
  $uid = atlas_uid();
  $w = ['user_id=?']; $p = [$uid];
  if (!empty($a['query'])) {
    $q = '%' . re_norm((string)$a['query']) . '%';
    $w[] = '(LOWER(title) LIKE ? OR LOWER(location) LIKE ? OR LOWER(features) LIKE ? OR LOWER(notes) LIKE ?)';
    array_push($p, $q, $q, $q, $q);
  }
  if (!empty($a['status'])) { $w[] = 'status=?'; $p[] = re_norm((string)$a['status']); }
  $sql = 'SELECT id,title,deal,ptype,rooms,area_m2,price,location,status,owner_name,owner_phone FROM re_listings WHERE '
       . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 25';
  $s = db()->prepare($sql); $s->execute($p);
  $rows = $s->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 'Portföyde kayıt bulunamadı.';
  return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

function re_tool_update_listing(array $a): string {
  $id = (int)($a['id'] ?? 0);
  if (!$id) return 'Hangi kayıt? Portföy no (id) gerekli.';
  $fields = []; $p = [];
  foreach (['title','ptype','rooms','location','features','notes','owner_name','owner_phone','floor_no'] as $k) {
    if (isset($a[$k]) && $a[$k] !== '') { $fields[] = "$k=?"; $p[] = (string)$a[$k]; }
  }
  if (isset($a['price']) && $a['price'] !== '') { $fields[] = 'price=?'; $p[] = re_money((string)$a['price']); }
  if (isset($a['area_m2']) && $a['area_m2'] !== '') { $fields[] = 'area_m2=?'; $p[] = (int)$a['area_m2']; }
  if (!empty($a['status'])) {
    $st = re_norm((string)$a['status']);
    if (strpos($st,'satil')!==false && strpos($st,'satilik')===false) $st='satildi';
    elseif (strpos($st,'kiralandi')!==false) $st='kiralandi';
    elseif (strpos($st,'pasif')!==false) $st='pasif';
    else $st='aktif';
    $fields[] = 'status=?'; $p[] = $st;
  }
  if (!$fields) return 'Güncellenecek bilgi yok.';
  $p[] = $id; $p[] = atlas_uid();
  $s = db()->prepare('UPDATE re_listings SET ' . implode(',', $fields) . ' WHERE id=? AND user_id=?');
  $s->execute($p);
  return $s->rowCount() ? ('Portföy #' . $id . ' güncellendi.') : ('#' . $id . ' bulunamadı.');
}

/* ---------------- MÜŞTERİ TALEBİ ---------------- */

function re_tool_add_lead(array $a): string {
  $uid = atlas_uid();
  $name = trim((string)($a['name'] ?? ''));
  if ($name === '') return 'Müşteri adı gerekli.';
  $phone = preg_replace('/[^0-9]/', '', (string)($a['phone'] ?? ''));
  if ($phone === '') { $hit = atlas_contact_search($name, 1); if (!empty($hit[0]['phone'])) $phone = preg_replace('/[^0-9]/','',$hit[0]['phone']); }
  if ($phone === '') { $ph = wa_lookup_contact($name); if ($ph) $phone = preg_replace('/[^0-9]/','',$ph); }
  if ($phone !== '' && !re_phone_ok($phone)) $phone = '';   // LID/gecersiz numarayi kaydetme
  $deal = re_norm((string)($a['deal'] ?? 'satilik'));
  $deal = (strpos($deal,'kira')!==false||strpos($deal,'rent')!==false) ? 'kiralik' : 'satilik';
  $s = db()->prepare('INSERT INTO re_leads(user_id,name,phone,deal,ptype,rooms,budget_max,budget_min,location,notes,status)
    VALUES(?,?,?,?,?,?,?,?,?,?,\'aktif\')');
  $s->execute([$uid, mb_substr($name,0,150), $phone ?: null, $deal,
    mb_substr(trim((string)($a['ptype'] ?? 'daire')),0,60),
    re_rooms((string)($a['rooms'] ?? '')),
    re_money((string)($a['budget_max'] ?? '')),
    re_money((string)($a['budget_min'] ?? '')),
    mb_substr(trim((string)($a['location'] ?? '')),0,190),
    mb_substr(trim((string)($a['notes'] ?? '')),0,1000)]);
  $id = (int)db()->lastInsertId();
  $out = 'Müşteri talebi kaydedildi (#' . $id . '): ' . $name . ($phone ? ' (' . $phone . ')' : ' — numara yok');
  $m = re_match_for_lead_rows($id);
  $out .= $m ? ("\nUYAN PORTFÖY: " . json_encode($m, JSON_UNESCAPED_UNICODE))
             : "\nŞu an portföyde uyan ev yok.";
  return $out;
}

function re_tool_list_leads(array $a): string {
  $w = ['user_id=?']; $p = [atlas_uid()];
  if (!empty($a['query'])) { $q='%'.re_norm((string)$a['query']).'%'; $w[]='(LOWER(name) LIKE ? OR LOWER(location) LIKE ? OR LOWER(notes) LIKE ?)'; array_push($p,$q,$q,$q); }
  if (!empty($a['status'])) { $w[]='status=?'; $p[]=re_norm((string)$a['status']); }
  $s = db()->prepare('SELECT id,name,phone,deal,ptype,rooms,budget_min,budget_max,location,status,next_followup_at FROM re_leads WHERE '
    . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 25');
  $s->execute($p);
  $rows = $s->fetchAll(PDO::FETCH_ASSOC);
  return $rows ? json_encode($rows, JSON_UNESCAPED_UNICODE) : 'Kayıtlı müşteri talebi bulunamadı.';
}

function re_tool_update_lead(array $a): string {
  $id = (int)($a['id'] ?? 0);
  if (!$id && !empty($a['name'])) {
    $s = db()->prepare('SELECT id FROM re_leads WHERE user_id=? AND LOWER(name) LIKE ? ORDER BY id DESC LIMIT 1');
    $s->execute([atlas_uid(), '%' . re_norm((string)$a['name']) . '%']);
    $id = (int)$s->fetchColumn();
  }
  if (!$id) return 'Müşteri bulunamadı (id ya da isim ver).';
  $f = []; $p = [];
  foreach (['ptype','rooms','location','notes','phone'] as $k) if (isset($a[$k]) && $a[$k]!=='') { $f[]="$k=?"; $p[]=(string)$a[$k]; }
  foreach (['budget_min','budget_max'] as $k) if (isset($a[$k]) && $a[$k]!=='') { $f[]="$k=?"; $p[]=re_money((string)$a[$k]); }
  if (!empty($a['status'])) { $st=re_norm((string)$a['status']); $f[]='status=?'; $p[]=(strpos($st,'kapa')!==false||strpos($st,'sat')!==false||strpos($st,'bitti')!==false)?'kapali':'aktif'; }
  if (!$f) return 'Güncellenecek bilgi yok.';
  $p[]=$id; $p[]=atlas_uid();
  $s = db()->prepare('UPDATE re_leads SET ' . implode(',', $f) . ' WHERE id=? AND user_id=?');
  $s->execute($p);
  return 'Müşteri #' . $id . ' güncellendi.';
}

/* ---------------- EŞLEŞTİRME ---------------- */

function re_loc_tokens(?string $loc): array {
  $loc = re_norm((string)$loc);
  $t = preg_split('/[^a-z0-9]+/', $loc);
  return array_values(array_filter($t, fn($x) => mb_strlen($x) >= 3));
}

function re_match_for_lead_rows(int $leadId, int $limit = 5): array {
  $s = db()->prepare('SELECT * FROM re_leads WHERE id=? AND user_id=?');
  $s->execute([$leadId, atlas_uid()]);
  $L = $s->fetch(PDO::FETCH_ASSOC);
  if (!$L) return [];
  $q = db()->prepare("SELECT * FROM re_listings WHERE user_id=? AND status='aktif' AND deal=?");
  $q->execute([atlas_uid(), $L['deal']]);
  $out = [];
  $lt = re_loc_tokens($L['location']);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $score = 0; $why = [];
    if ($L['budget_max']) {
      if ($r['price'] === null) { $score += 0; }
      elseif ($r['price'] <= $L['budget_max']) { $score += 40; $why[] = 'bütçeye uygun'; }
      elseif ($r['price'] <= $L['budget_max'] * 1.1) { $score += 18; $why[] = 'bütçenin %10 üstü'; }
      else continue;
    }
    if ($L['budget_min'] && $r['price'] !== null && $r['price'] < $L['budget_min']) $score -= 5;
    if ($L['rooms'] && $r['rooms']) {
      if (re_norm($L['rooms']) === re_norm($r['rooms'])) { $score += 30; $why[] = 'oda sayısı aynı'; }
      else $score -= 10;
    }
    if ($lt) {
      $rl = re_norm((string)$r['location'] . ' ' . $r['title']);
      $hit = 0; foreach ($lt as $tk) if (mb_strpos($rl, $tk) !== false) $hit++;
      if ($hit) { $score += 25 + 5 * ($hit - 1); $why[] = 'lokasyon uyuyor'; }
    }
    if ($L['ptype'] && $r['ptype'] && re_norm($L['ptype']) === re_norm($r['ptype'])) $score += 8;
    if ($score <= 0) continue;
    $out[] = ['id'=>(int)$r['id'], 'title'=>$r['title'], 'rooms'=>$r['rooms'], 'location'=>$r['location'],
              'price'=>$r['price'] !== null ? (int)$r['price'] : null, 'area_m2'=>$r['area_m2'],
              'skor'=>$score, 'neden'=>implode(', ', $why)];
  }
  usort($out, fn($x, $y) => $y['skor'] <=> $x['skor']);
  return array_slice($out, 0, $limit);
}

function re_match_for_listing_rows(int $listingId, int $limit = 5): array {
  $s = db()->prepare('SELECT * FROM re_listings WHERE id=? AND user_id=?');
  $s->execute([$listingId, atlas_uid()]);
  $R = $s->fetch(PDO::FETCH_ASSOC);
  if (!$R) return [];
  $q = db()->prepare("SELECT * FROM re_leads WHERE user_id=? AND status='aktif' AND deal=?");
  $q->execute([atlas_uid(), $R['deal']]);
  $out = [];
  $rl = re_norm((string)$R['location'] . ' ' . $R['title']);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $L) {
    $score = 0; $why = [];
    if ($L['budget_max'] && $R['price'] !== null) {
      if ($R['price'] <= $L['budget_max']) { $score += 40; $why[] = 'bütçesine uygun'; }
      elseif ($R['price'] <= $L['budget_max'] * 1.1) { $score += 18; $why[] = 'bütçesinin %10 üstü'; }
      else continue;
    }
    if ($L['rooms'] && $R['rooms']) { if (re_norm($L['rooms']) === re_norm($R['rooms'])) { $score += 30; $why[] = 'oda sayısı aynı'; } else $score -= 10; }
    $lt = re_loc_tokens($L['location']);
    if ($lt) { $hit=0; foreach ($lt as $tk) if (mb_strpos($rl, $tk) !== false) $hit++; if ($hit) { $score += 25; $why[] = 'aradığı bölge'; } }
    if ($score <= 0) continue;
    $out[] = ['lead_id'=>(int)$L['id'], 'name'=>$L['name'], 'phone'=>$L['phone'],
              'budget_max'=>$L['budget_max'] !== null ? (int)$L['budget_max'] : null,
              'skor'=>$score, 'neden'=>implode(', ', $why)];
  }
  usort($out, fn($x, $y) => $y['skor'] <=> $x['skor']);
  return array_slice($out, 0, $limit);
}

function re_tool_match(array $a): string {
  $uid = atlas_uid();
  if (!empty($a['listing_id'])) {
    $m = re_match_for_listing_rows((int)$a['listing_id']);
    return $m ? json_encode($m, JSON_UNESCAPED_UNICODE) : 'Bu portföye uyan aktif müşteri talebi yok.';
  }
  $id = (int)($a['lead_id'] ?? 0);
  if (!$id && !empty($a['name'])) {
    $s = db()->prepare('SELECT id FROM re_leads WHERE user_id=? AND LOWER(name) LIKE ? ORDER BY id DESC LIMIT 1');
    $s->execute([$uid, '%' . re_norm((string)$a['name']) . '%']);
    $id = (int)$s->fetchColumn();
  }
  if ($id) {
    $m = re_match_for_lead_rows($id);
    return $m ? json_encode($m, JSON_UNESCAPED_UNICODE) : 'Bu müşteriye uyan aktif portföy yok.';
  }
  /* Serbest kriterle arama: gecici lead gibi davran */
  $deal = re_norm((string)($a['deal'] ?? 'satilik'));
  $deal = (strpos($deal,'kira')!==false) ? 'kiralik' : 'satilik';
  $q = db()->prepare("SELECT * FROM re_listings WHERE user_id=? AND status='aktif' AND deal=?");
  $q->execute([$uid, $deal]);
  $budget = re_money((string)($a['budget_max'] ?? ''));
  $rooms = re_rooms((string)($a['rooms'] ?? ''));
  $lt = re_loc_tokens((string)($a['location'] ?? ''));
  $out = [];
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ($budget && $r['price'] !== null && $r['price'] > $budget * 1.1) continue;
    if ($rooms && $r['rooms'] && re_norm($rooms) !== re_norm($r['rooms'])) continue;
    if ($lt) { $rl = re_norm((string)$r['location'] . ' ' . $r['title']); $hit=0; foreach ($lt as $tk) if (mb_strpos($rl,$tk)!==false) $hit++; if (!$hit) continue; }
    $out[] = ['id'=>(int)$r['id'],'title'=>$r['title'],'rooms'=>$r['rooms'],'location'=>$r['location'],'price'=>$r['price']!==null?(int)$r['price']:null,'area_m2'=>$r['area_m2']];
  }
  return $out ? json_encode(array_slice($out,0,8), JSON_UNESCAPED_UNICODE) : 'Kriterlere uyan aktif portföy yok.';
}

/* ---------------- TAKİP ---------------- */

function re_tool_set_followup(array $a): string {
  $id = (int)($a['lead_id'] ?? 0);
  $name = trim((string)($a['name'] ?? ''));
  if (!$id && $name !== '') {
    $s = db()->prepare('SELECT id,name,phone FROM re_leads WHERE user_id=? AND LOWER(name) LIKE ? ORDER BY id DESC LIMIT 1');
    $s->execute([atlas_uid(), '%' . re_norm($name) . '%']);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) { $id = (int)$row['id']; $name = $row['name']; }
  }
  if (!$id) return 'Müşteri bulunamadı; önce add_lead ile kaydet.';
  $when = trim((string)($a['when_text'] ?? ''));
  $due = null;
  if ($when !== '') $due = function_exists('atlas_when_to_dt') ? atlas_when_to_dt($when) : (($ts = strtotime($when)) ? date('Y-m-d H:i:s', $ts) : null);
  $u = db()->prepare('UPDATE re_leads SET next_followup_at=? WHERE id=? AND user_id=?');
  $u->execute([$due, $id, atlas_uid()]);
  $body = trim((string)($a['note'] ?? '')) ?: ('Emlak takibi: ' . ($name ?: ('#' . $id)) . ' müşterisini ara / mesaj at.');
  $r = db()->prepare('INSERT INTO reminders(user_id,when_text,due_at,body) VALUES(?,?,?,?)');
  $r->execute([atlas_uid(), $when ?: null, $due, $body]);
  return 'Takip kuruldu: ' . ($name ?: ('#' . $id)) . ($due ? (' — ' . date('d.m.Y H:i', strtotime($due)) . ' (WhatsApp\'tan hatırlatılacak)') : ($when ? (' — ' . $when . ' (saat anlaşılamadı)') : '')) . '.';
}

function re_tool_daily_agenda(array $a): string {
  $uid = atlas_uid();
  /* DIKKAT: MySQL sunucusu UTC, PHP Europe/Istanbul — karsilastirmalar PHP saatiyle yapilir. */
  $now   = date('Y-m-d H:i:s');
  $plus1 = date('Y-m-d H:i:s', strtotime('+1 day'));
  $minus7= date('Y-m-d H:i:s', strtotime('-7 day'));
  $out = [];
  $s = db()->prepare("SELECT id,name,phone,next_followup_at FROM re_leads WHERE user_id=? AND status='aktif' AND next_followup_at IS NOT NULL AND next_followup_at <= ? ORDER BY next_followup_at");
  $s->execute([$uid, $plus1]);
  $out['yaklasan_takipler'] = $s->fetchAll(PDO::FETCH_ASSOC);
  $s2 = db()->prepare("SELECT COUNT(*) FROM re_listings WHERE user_id=? AND status='aktif'"); $s2->execute([$uid]);
  $out['aktif_portfoy'] = (int)$s2->fetchColumn();
  $s3 = db()->prepare("SELECT COUNT(*) FROM re_leads WHERE user_id=? AND status='aktif'"); $s3->execute([$uid]);
  $out['aktif_musteri'] = (int)$s3->fetchColumn();
  $s4 = db()->prepare("SELECT id,name FROM re_leads WHERE user_id=? AND status='aktif' AND next_followup_at IS NULL AND created_at < ? ORDER BY id DESC LIMIT 10");
  $s4->execute([$uid, $minus7]);
  $out['takibi_kurulmamis_eski_musteriler'] = $s4->fetchAll(PDO::FETCH_ASSOC);
  $s5 = db()->prepare("SELECT id,when_text,due_at,body FROM reminders WHERE user_id=? AND status='open' AND due_at IS NOT NULL AND due_at <= ? ORDER BY due_at LIMIT 10");
  $s5->execute([$uid, $plus1]);
  $out['yaklasan_hatirlatmalar'] = $s5->fetchAll(PDO::FETCH_ASSOC);
  return json_encode($out, JSON_UNESCAPED_UNICODE);
}


/** WhatsApp'tan silme: panel gerekmesin diye. */
function re_tool_delete(array $a): string {
  $uid = atlas_uid();
  $lid = (int)($a['listing_id'] ?? 0);
  $ldd = (int)($a['lead_id'] ?? 0);
  $name = trim((string)($a['name'] ?? ''));
  if ($lid) {
    $g = db()->prepare('SELECT title FROM re_listings WHERE id=? AND user_id=?'); $g->execute([$lid,$uid]);
    $t = $g->fetchColumn(); if (!$t) return 'Portfoyde #' . $lid . ' yok.';
    db()->prepare('DELETE FROM re_listings WHERE id=? AND user_id=?')->execute([$lid,$uid]);
    return 'Portföyden silindi: #' . $lid . ' ' . $t;
  }
  if (!$ldd && $name !== '') {
    $g = db()->prepare('SELECT id,name FROM re_leads WHERE user_id=? AND LOWER(name) LIKE ? ORDER BY id DESC LIMIT 1');
    $g->execute([$uid, '%' . re_norm($name) . '%']); $row = $g->fetch(PDO::FETCH_ASSOC);
    if ($row) { $ldd = (int)$row['id']; $name = $row['name']; }
    else {
      $g2 = db()->prepare('SELECT id,title FROM re_listings WHERE user_id=? AND (LOWER(title) LIKE ? OR LOWER(location) LIKE ?) ORDER BY id DESC LIMIT 3');
      $g2->execute([$uid, '%' . re_norm($name) . '%', '%' . re_norm($name) . '%']);
      $rows = $g2->fetchAll(PDO::FETCH_ASSOC);
      if (count($rows) === 1) {
        db()->prepare('DELETE FROM re_listings WHERE id=? AND user_id=?')->execute([$rows[0]['id'],$uid]);
        return 'Portföyden silindi: #' . $rows[0]['id'] . ' ' . $rows[0]['title'];
      }
      if (count($rows) > 1) return 'Birden fazla kayit eslesti, numarasiyla soyle: ' . json_encode($rows, JSON_UNESCAPED_UNICODE);
      return 'Silinecek kayit bulunamadi.';
    }
  }
  if ($ldd) {
    db()->prepare('DELETE FROM re_leads WHERE id=? AND user_id=?')->execute([$ldd,$uid]);
    return 'Musteri kaydi silindi: ' . ($name ?: ('#' . $ldd));
  }
  return 'Neyi sileyim? Portfoy numarasi ya da musteri adi ver.';
}

/* ---------------- ARAÇ TANIMLARI ---------------- */

function re_tools(): array {
  return [
    ['type'=>'function','function'=>['name'=>'re_add_listing','description'=>'EMLAK: portföye yeni ev/dükkan/arsa kaydı ekler. Kullanıcı bir ilan/portföy anlattığında (ses kaydı dahil) çağır. Eklendikten sonra uyan müşterileri de döner.','parameters'=>['type'=>'object','properties'=>[
      'title'=>['type'=>'string','description'=>'Kısa başlık, ör. "Kadıköy 3+1 bahçe katı"'],
      'deal'=>['type'=>'string','description'=>'satilik veya kiralik'],
      'ptype'=>['type'=>'string','description'=>'daire, villa, dükkan, arsa, ofis...'],
      'rooms'=>['type'=>'string','description'=>'ör. 3+1'],
      'area_m2'=>['type'=>'integer'],
      'price'=>['type'=>'string','description'=>'ör. "6 milyon", "25 bin", "6500000"'],
      'location'=>['type'=>'string','description'=>'il/ilçe/mahalle, ör. "İstanbul Kadıköy Fikirtepe"'],
      'floor_no'=>['type'=>'string'],'owner_name'=>['type'=>'string'],'owner_phone'=>['type'=>'string'],
      'features'=>['type'=>'string','description'=>'asansör, otopark, eşyalı, cephe vb.'],
      'notes'=>['type'=>'string']],'required'=>['deal']]]],
    ['type'=>'function','function'=>['name'=>'re_list_listings','description'=>'EMLAK: portföyü listeler/arar (lokasyon, başlık, özellik metniyle).','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string'],'status'=>['type'=>'string','description'=>'aktif, satildi, kiralandi, pasif']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_update_listing','description'=>'EMLAK: portföy kaydını güncelle veya durumunu değiştir (satıldı/kiralandı/pasif).','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'integer'],'status'=>['type'=>'string'],'price'=>['type'=>'string'],'title'=>['type'=>'string'],'rooms'=>['type'=>'string'],'location'=>['type'=>'string'],'area_m2'=>['type'=>'integer'],'features'=>['type'=>'string'],'notes'=>['type'=>'string'],'owner_name'=>['type'=>'string'],'owner_phone'=>['type'=>'string'],'floor_no'=>['type'=>'string'],'ptype'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'re_add_lead','description'=>'EMLAK: müşteri talebi kaydeder (kim, ne arıyor, bütçe, bölge). Kaydettikten sonra portföyden uyan evleri de döner.','parameters'=>['type'=>'object','properties'=>[
      'name'=>['type'=>'string'],'phone'=>['type'=>'string'],'deal'=>['type'=>'string','description'=>'satilik/kiralik'],
      'ptype'=>['type'=>'string'],'rooms'=>['type'=>'string'],'budget_max'=>['type'=>'string','description'=>'ör. "6 milyon"'],
      'budget_min'=>['type'=>'string'],'location'=>['type'=>'string'],'notes'=>['type'=>'string']],'required'=>['name']]]],
    ['type'=>'function','function'=>['name'=>'re_list_leads','description'=>'EMLAK: müşteri taleplerini listeler/arar.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string'],'status'=>['type'=>'string','description'=>'aktif veya kapali']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_update_lead','description'=>'EMLAK: müşteri talebini güncelle (bütçe değişti, kapandı vb.).','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'integer'],'name'=>['type'=>'string'],'phone'=>['type'=>'string'],'budget_max'=>['type'=>'string'],'budget_min'=>['type'=>'string'],'rooms'=>['type'=>'string'],'location'=>['type'=>'string'],'ptype'=>['type'=>'string'],'status'=>['type'=>'string'],'notes'=>['type'=>'string']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_match','description'=>'EMLAK: eşleştirme. lead_id/name verilirse müşteriye uyan portföyü, listing_id verilirse eve uyan müşterileri döner; hiçbiri yoksa verilen kriterlere (bütçe/oda/bölge) göre portföyde arar.','parameters'=>['type'=>'object','properties'=>['lead_id'=>['type'=>'integer'],'name'=>['type'=>'string'],'listing_id'=>['type'=>'integer'],'deal'=>['type'=>'string'],'budget_max'=>['type'=>'string'],'rooms'=>['type'=>'string'],'location'=>['type'=>'string']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_set_followup','description'=>'EMLAK: bir müşteri için takip zamanı kurar (hatırlatıcı da oluşturur).','parameters'=>['type'=>'object','properties'=>['lead_id'=>['type'=>'integer'],'name'=>['type'=>'string'],'when_text'=>['type'=>'string','description'=>'ör. "yarın 10:00", "3 gün sonra"'],'note'=>['type'=>'string']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_delete','description'=>'EMLAK: portföy kaydını ya da müşteri talebini tamamen siler. Kullanıcı sil/kaldır dediğinde çağır (panel gerekmez).','parameters'=>['type'=>'object','properties'=>['listing_id'=>['type'=>'integer'],'lead_id'=>['type'=>'integer'],'name'=>['type'=>'string','description'=>'Müşteri adı ya da portföy başlığı/bölgesi']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'re_agenda','description'=>'EMLAK: günün emlak ajandası — bugün takip edilecek müşteriler, aktif portföy/müşteri sayısı, uzun süre dokunulmamış müşteriler.','parameters'=>['type'=>'object','properties'=>new stdClass(),'required'=>[]]]],
  ];
}

function re_run_tool(string $name, array $args): ?string {
  switch ($name) {
    case 're_add_listing':    return re_tool_add_listing($args);
    case 're_list_listings':  return re_tool_list_listings($args);
    case 're_update_listing': return re_tool_update_listing($args);
    case 're_add_lead':       return re_tool_add_lead($args);
    case 're_list_leads':     return re_tool_list_leads($args);
    case 're_update_lead':    return re_tool_update_lead($args);
    case 're_match':          return re_tool_match($args);
    case 're_set_followup':   return re_tool_set_followup($args);
    case 're_delete':         return re_tool_delete($args);
    case 're_agenda':         return re_tool_daily_agenda($args);
  }
  return null;
}

function re_system_prompt(string $lang = 'tr'): string {
  if ($lang === 'en') {
    return " REAL ESTATE MODULE IS ON: the user is a real estate agent. Portfolio (listings) and buyer/tenant requests (leads) live in the database."
      . " When the user describes a property, call re_add_listing; when they describe a client's request, call re_add_lead (parse budget, rooms, location, deal type from free speech; ask only for what is truly missing)."
      . " For 'who fits this house / what do we have for this client' call re_match. For follow-ups use re_set_followup, for the daily plan re_agenda."
      . " After a match, offer to send the client a WhatsApp message with send_whatsapp (draft first, send after approval). Never invent listings — always answer from tool results.";
  }
  return " EMLAK MODÜLÜ AÇIK: kullanıcı emlak danışmanı. Portföy (satılık/kiralık kayıtlar) ve müşteri talepleri veritabanında tutulur."
    . " Kullanıcı bir ev/ilan anlatınca (yazı ya da ses kaydı) MUTLAKA re_add_listing çağır; bir müşterinin ne aradığını anlatınca re_add_lead çağır."
    . " Serbest konuşmadan bütçe (\"6 milyon\"), oda (\"3+1\"), bölge ve satılık/kiralık bilgisini kendin çıkar; sadece gerçekten eksik olanı sor, kayıt için tüm alanları isteme."
    . " \"Bu eve kim uyar / bu müşteriye ne var / X bütçeye ne var\" sorularında re_match kullan. Takip için re_set_followup, günün planı için re_agenda."
    . " Eşleşme bulunca kullanıcıya özet ver ve istersen müşteriye WhatsApp mesajı taslağı sun; onay verirse send_whatsapp ile gönder."
    . "HER İŞ WHATSAPP ÜZERİNDEN YAPILIR: kullanıcının panele/web sayfasına girmesi gerekmez, ona panelden yap DEME. Silme re_delete, durum değiştirme re_update_listing, güncelleme re_update_lead ile senin araçlarınla olur. Sadece WhatsApp hattını ilk bağlama (QR) bir kez web sayfasından yapılır. ASLA portföy/müşteri uydurma — her cevabı araç sonucuna dayandır. Fiyatları binlik ayraçla ve TL olarak yaz.";
}
