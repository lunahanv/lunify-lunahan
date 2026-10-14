<?php

/* ---- Modul kayit defteri: uygulamaya ozel araclar (Viktor 2026-09-09) ----
   Modul dosyasi lh_register_tools($tools, $runner, $promptFn) ile kendini tanitir. */
if (!function_exists('lh_register_tools')) {
  function lh_register_tools(array $tools, callable $run, ?callable $prompt = null): void {
    $GLOBALS['LH_TOOLREG'][] = ['tools' => $tools, 'run' => $run, 'prompt' => $prompt];
  }
  function lh_reg_tools(): array {
    $out = [];
    foreach (($GLOBALS['LH_TOOLREG'] ?? []) as $r) {
      $out = array_merge($out, $r['tools']);
      /* tools_fn: calisma aninda uretilen arac listesi (ör. uzak baglanti araclari) */
      if (!empty($r['tools_fn']) && is_callable($r['tools_fn'])) $out = array_merge($out, (array)($r['tools_fn'])());
    }
    return $out;
  }
  function lh_reg_run(string $name, array $args) {
    foreach (($GLOBALS['LH_TOOLREG'] ?? []) as $r) {
      $res = ($r['run'])($name, $args);
      if ($res !== null) return $res;
    }
    return null;
  }
  function lh_reg_prompt(string $lang): string {
    $s = '';
    foreach (($GLOBALS['LH_TOOLREG'] ?? []) as $r) if ($r['prompt']) $s .= ($r['prompt'])($lang);
    return $s;
  }
}

/* ---- LUNAHAN cekirdegi: uygulama kimligi (Viktor 2026-09-09) ----
   Ayni beyin Atlas / Anahtar / LUNAHAN icin kullanilir. Metinlerdeki {APP}
   token'i calisma aninda uygulamanin markasiyla degistirilir. */
if (!defined('LH_INC')) define('LH_INC', __DIR__);
/* Otomatik hafiza modulu (Viktor 2026-09-10) */
if (is_file(__DIR__ . '/lh_automem.php')) require_once __DIR__ . '/lh_automem.php';
if (!function_exists('lh_brand')) {
  function lh_brand(): string {
    if (defined('APP_BRAND') && APP_BRAND !== '') return APP_BRAND;
    if (defined('APP_NAME')  && APP_NAME  !== '') return trim(preg_replace('/^Lunify\\s+/u', '', APP_NAME));
    return 'LUNAHAN';
  }
  function lh_full(): string { return defined('APP_NAME') && APP_NAME !== '' ? APP_NAME : lh_brand(); }
  function lh_b($v) {
    if (is_string($v)) return str_replace(['{APPFULL}','{APP}'], [lh_full(), lh_brand()], $v);
    if (is_array($v))  { foreach ($v as $k => $x) $v[$k] = lh_b($x); return $v; }
    return $v;
  }
}

require_once LH_INC . '/ai_think.php';
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

require_once LH_INC . '/db.php';
require_once LH_INC . '/auth.php';
require_once LH_INC . '/google.php';
require_once LH_INC . '/gdrive.php';
require_once LH_INC . '/gcontacts.php';   // Viktor 2026-08-30: telefon rehberi (Google Kisiler + vCard)
require_once LH_INC . '/when.php';
foreach (($GLOBALS['LH_MODULES'] ?? []) as $lhMod) { require_once $lhMod; }

/**
 * {APPFULL} agent beyni — DeepSeek (OpenAI uyumlu) tool-calling döngüsü.
 * Kullanıcı doğal dilde komut verir; model uygun aracı seçer; araçlar burada çalışır.
 */

/* AI baglanti ayarlari once sistem ayarindan (Yonetim paneli), yoksa config.php'den.
   Sahibin istegi 2026-09-04: admin API anahtarini panelden gorebilsin/degistirebilsin. */
function atlas_llm_base(): string  { $v = trim((string)gsetting('llm_base',  '')); return $v !== '' ? $v : LLM_BASE; }
function atlas_llm_key(): string   { $v = trim((string)gsetting('llm_key',   '')); return $v !== '' ? $v : LLM_KEY; }
function atlas_llm_model(): string { $v = trim((string)gsetting('llm_model', '')); return $v !== '' ? $v : LLM_MODEL; }

/* Kisisel API anahtarlari (Viktor 2026-09-04, sahibin istegi):
   Her kullanici icin ASIL (slot 0) ve YEDEK (slot 1) anahtar tanimlanabilir.
   Zincir: kullanicinin asili -> kullanicinin yedegi -> panel/genel ayar. */
function atlas_user_ai_schema(): void {
  static $done = false; if ($done) return; $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS user_ai_keys (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      slot TINYINT NOT NULL DEFAULT 0,
      api_base VARCHAR(255) NOT NULL DEFAULT '',
      api_key TEXT NULL,
      model VARCHAR(120) NOT NULL DEFAULT '',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY u_user_slot (user_id, slot)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    /* Viktor 2026-09-10: coklu anahtar + amac alanlari */
    foreach ([
      "ALTER TABLE user_ai_keys ADD COLUMN purpose VARCHAR(16) NOT NULL DEFAULT 'genel'",
      "ALTER TABLE user_ai_keys ADD COLUMN label VARCHAR(60) NOT NULL DEFAULT ''",
      "ALTER TABLE user_ai_keys DROP INDEX u_user_slot",
    ] as $sql) { try { db()->exec($sql); } catch (Throwable $e) {} }
  } catch (Throwable $e) {}
}

function atlas_user_ai_rows(int $uid): array {
  if ($uid <= 0) return [];
  atlas_user_ai_schema();
  try {
    $st = db()->prepare("SELECT * FROM user_ai_keys WHERE user_id=? AND is_active=1 ORDER BY slot");
    $st->execute([$uid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return function_exists('hsecOpenRow') ? array_map('hsecOpenRow', $rows) : $rows;
  } catch (Throwable $e) { return []; }
}


/* Amaca gore anahtar secimi (Viktor 2026-09-10, sahibin istegi):
   Bir kullaniciya SINIRSIZ sayida anahtar tanimlanabilir; her anahtarin bir amaci vardir:
     genel  = metin/sohbet (ucuz model, or. DeepSeek)
     gorsel = gorsel & PDF okuma (vision; or. OpenRouter'in en ucuz vision modeli)
     hepsi  = her isi yapabilir
   Model alanina "auto" yazilirsa OpenRouter model listesinden o is icin EN UCUZ
   model gunde bir kez otomatik secilir. */
function atlas_ai_purposes(): array {
  return ['genel' => 'Genel (metin/sohbet)', 'gorsel' => 'Gorsel & PDF okuma', 'hepsi' => 'Hepsi'];
}

function atlas_or_cheapest_model(string $need = 'vision'): string {
  $need = ($need === 'vision') ? 'vision' : 'text';
  $ck   = 'or_cheap_' . $need;
  $j    = json_decode((string)gsetting($ck, ''), true);
  if (is_array($j) && !empty($j['model']) && (time() - (int)($j['ts'] ?? 0)) < 86400) return (string)$j['model'];
  $best = ''; $bestScore = null;
  $ch = curl_init('https://openrouter.ai/api/v1/models');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
  $resp = curl_exec($ch); curl_close($ch);
  $d = json_decode((string)$resp, true);
  foreach ((array)($d['data'] ?? []) as $m) {
    $id = (string)($m['id'] ?? ''); if ($id === '' || strpos($id, ':batch') !== false) continue;
    $mods = (array)($m['architecture']['input_modalities'] ?? ['text']);
    if ($need === 'vision' && !in_array('image', $mods, true)) continue;
    $p = (float)($m['pricing']['prompt'] ?? 0); $c = (float)($m['pricing']['completion'] ?? 0);
    if ($p <= 0 || $c <= 0) continue;                       // 0 = ucretsiz/limitli, -1 = auto
    if ((int)($m['context_length'] ?? 0) < 32000) continue;  // cok kucuk baglami alma
    $score = $p * 3 + $c;                                    // giris agirlikli maliyet
    if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $best = $id; }
  }
  if ($best === '') $best = ($need === 'vision') ? 'google/gemini-2.5-flash-lite' : 'deepseek/deepseek-chat';
  try { set_gsetting($ck, json_encode(['model' => $best, 'ts' => time()])); } catch (Throwable $e) {}
  return $best;
}

/** Model alanini cozer: bos = genel ayar, "auto" = OpenRouter'da en ucuz uygun model. */
function atlas_ai_resolve_model(string $model, string $base, string $need): string {
  $m = trim($model);
  if (in_array(mb_strtolower($m), ['auto', 'oto', 'auto-ucuz', 'en-ucuz', 'ucuz'], true)) return atlas_or_cheapest_model($need);
  if ($m !== '') return $m;
  if ($need === 'vision' && stripos($base, 'openrouter') !== false) return atlas_or_cheapest_model('vision');
  return atlas_llm_model();
}

/** Mesajlarda gorsel/PDF varsa 'vision' isi demektir. */
function atlas_msgs_need(array $messages): string {
  foreach ($messages as $m) {
    $c = $m['content'] ?? null;
    if (is_array($c)) foreach ($c as $part) {
      $t = (string)($part['type'] ?? '');
      if ($t === 'image_url' || $t === 'input_image' || $t === 'file' || $t === 'input_file') return 'vision';
    }
  }
  return 'text';
}

/** Denenecek [base, key, model] zinciri. $need: 'text' | 'vision' */
function atlas_llm_chain(?int $uid = null, string $need = 'text'): array {
  $uid  = $uid !== null ? $uid : (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
  $need = ($need === 'vision') ? 'vision' : 'text';
  $chain = []; $prim = []; $sec = [];
  foreach (atlas_user_ai_rows($uid) as $r) {
    $k = trim((string)($r['api_key'] ?? ''));
    if ($k === '') continue;
    $pu = mb_strtolower(trim((string)($r['purpose'] ?? 'genel'))); if ($pu === '') $pu = 'genel';
    $base = trim((string)$r['api_base']) !== '' ? rtrim(trim((string)$r['api_base']), '/') : atlas_llm_base();
    $ent = ['base' => $base, 'key' => $k, 'model' => atlas_ai_resolve_model((string)($r['model'] ?? ''), $base, $need), 'purpose' => $pu];
    $ok  = ($need === 'vision') ? in_array($pu, ['gorsel', 'hepsi'], true) : in_array($pu, ['genel', 'hepsi'], true);
    if ($ok) $prim[] = $ent; else $sec[] = $ent;
  }
  $chain = array_merge($prim, $sec);
  if ($need === 'vision') {   // uygulama geneli gorsel saglayici (panel: llm_vision_*)
    $vk = trim((string)gsetting('llm_vision_key', ''));
    if ($vk !== '') {
      $vb = trim((string)gsetting('llm_vision_base', '')); if ($vb === '') $vb = atlas_llm_base();
      $chain[] = ['base' => rtrim($vb, '/'), 'key' => $vk,
                  'model' => atlas_ai_resolve_model((string)gsetting('llm_vision_model', ''), $vb, 'vision'), 'purpose' => 'gorsel'];
    }
  }
  $chain[] = ['base' => atlas_llm_base(), 'key' => atlas_llm_key(), 'model' => atlas_llm_model()];
  // Uygulama geneli YEDEK saglayici (panel: llm_*2, yoksa ayar dosyasi LLM_*2)
  $b2 = trim((string)gsetting('llm_base2', ''));  if ($b2 === '' && defined('LLM_BASE2'))  $b2 = LLM_BASE2;
  $k2 = trim((string)gsetting('llm_key2', ''));   if ($k2 === '' && defined('LLM_KEY2'))   $k2 = LLM_KEY2;
  $m2 = trim((string)gsetting('llm_model2', '')); if ($m2 === '' && defined('LLM_MODEL2')) $m2 = LLM_MODEL2;
  if ($b2 !== '' && $k2 !== '' && $m2 !== '') $chain[] = ['base' => rtrim($b2, '/'), 'key' => $k2, 'model' => $m2];
  return $chain;
}

function atlas_log_ai_usage(int $userId, string $provider, string $model, array $usage, int $httpCode): void {
  if ($userId < 1) return;
  $prompt = max(0, (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0));
  $completion = max(0, (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0));
  $reportedTotal = max(0, (int)($usage['total_tokens'] ?? 0));
  $total = max($reportedTotal, $prompt + $completion);
  $rawCost = $usage['cost'] ?? $usage['cost_usd'] ?? $usage['total_cost'] ?? null;
  $cost = 0.0; $source = 'unknown';
  if (is_numeric($rawCost) && is_finite((float)$rawCost) && (float)$rawCost >= 0) {
    $cost = round((float)$rawCost, 6); $source = 'provider';
  } elseif ($total > 0) { $source = 'tokens_only'; }
  try {
    db()->prepare('INSERT INTO ai_usage_log (app,user_id,provider,model,action,prompt_tokens,completion_tokens,total_tokens,cost_usd,cost_source,http_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute(['lunahan',$userId,mb_substr($provider,0,40),mb_substr($model,0,120),'chat_completion',$prompt,$completion,$total,$cost,$source,$httpCode]);
  } catch (Throwable $e) { error_log('[lunahan-ai-usage] log write failed: ' . get_class($e)); }
}
function llm_chat_one(string $base, string $key, string $model, array $messages, array $tools, int $userId = 0, string $provider = ''): array {
  $ch = curl_init(rtrim($base, '/') . '/chat/completions');
  $body = [
    'model' => $model,
    ...aiThinkFix($model),
    'messages' => $messages,
    'tools' => $tools,
    'tool_choice' => 'auto',
    'temperature' => 0.3,
  ];
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_TIMEOUT => 90,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($resp === false) { atlas_log_ai_usage($userId,$provider,$model,[],$httpCode); return ['error' => 'LLM bağlantı hatası: ' . $err]; }
  $j = json_decode($resp, true);
  if (!is_array($j)) { atlas_log_ai_usage($userId,$provider,$model,[],$httpCode); return ['error' => 'LLM yanıtı okunamadı']; }
  $usage = is_array($j['usage'] ?? null) ? $j['usage'] : [];
  if (isset($j['error'])) { atlas_log_ai_usage($userId,$provider,$model,$usage,$httpCode); return ['error' => $j['error']['message'] ?? 'LLM hatası']; }
  atlas_log_ai_usage($userId,$provider,$model,$usage,$httpCode);
  return $j;
}

if (!defined('LH_AI_MONTHLY_BUDGET_USD')) define('LH_AI_MONTHLY_BUDGET_USD', 5.0);
function atlas_ai_budget_check(int $userId): array {
  if ($userId < 1) return ['ok'=>false,'err'=>'user_missing'];
  $since = date('Y-m-01 00:00:00');
  try {
    $q = db()->prepare("SELECT COALESCE(SUM(cost_usd),0) spent, COALESCE(SUM(CASE WHEN cost_source<>'provider' THEN 1 ELSE 0 END),0) unpriced FROM ai_usage_log WHERE app='lunahan' AND user_id=? AND created_at>=?");
    $q->execute([$userId,$since]); $r=$q->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($r['unpriced'] ?? 0) > 0) return ['ok'=>false,'err'=>'usage_unpriced'];
    if ((float)($r['spent'] ?? 0) >= (float)LH_AI_MONTHLY_BUDGET_USD) return ['ok'=>false,'err'=>'limit_reached'];
    return ['ok'=>true,'spent'=>(float)($r['spent'] ?? 0),'limit'=>(float)LH_AI_MONTHLY_BUDGET_USD];
  } catch (Throwable $e) { return ['ok'=>false,'err'=>'meter_unavailable']; }
}
function atlas_ai_budget_message(string $err): string {
  $en = (($_SESSION['lang'] ?? 'tr') === 'en');
  $tr = ['limit_reached'=>'Kullanıcı başına aylık 5 USD AI bütçesi doldu.','usage_unpriced'=>'Sağlayıcı maliyeti doğrulanamadı; bu ay yeni AI çağrıları güvenlik için durduruldu.','meter_unavailable'=>'AI maliyet takibi çalışmıyor; istek güvenlik için durduruldu.','user_missing'=>'Hesap doğrulanamadı; AI isteği durduruldu.','busy'=>'Başka bir AI isteği sürüyor; birazdan tekrar dene.'];
  $enText = ['limit_reached'=>'The $5 monthly AI budget for this account has been reached.','usage_unpriced'=>'Provider cost could not be verified; AI calls are paused for this month.','meter_unavailable'=>'AI cost tracking is unavailable; the request was blocked for safety.','user_missing'=>'The account could not be verified; the AI request was blocked.','busy'=>'Another AI request is in progress; try again shortly.'];
  return ($en ? $enText : $tr)[$err] ?? 'AI bütçe kontrolü başarısız oldu; istek durduruldu.';
}
function llm_chat(array $messages, array $tools, int $userId = 0): array {
  if ($userId <= 0) $userId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
  if ($userId <= 0) return ['error'=>atlas_ai_budget_message('user_missing')];
  $lockName = 'lunahan-ai-budget-' . $userId; $locked = false;
  try { $l=db()->prepare('SELECT GET_LOCK(?,0)'); $l->execute([$lockName]); $locked=((int)$l->fetchColumn()===1); } catch(Throwable $e) { $locked=false; }
  if (!$locked) return ['error'=>atlas_ai_budget_message('busy')];
  try {
    $last = ['error' => 'LLM yapılandırılmamış'];
    foreach (atlas_llm_chain($userId, atlas_msgs_need($messages)) as $c) {
      if (trim((string)$c['key']) === '') continue;
      $budget=atlas_ai_budget_check($userId); if (!$budget['ok']) return ['error'=>atlas_ai_budget_message($budget['err'])];
      $provider = (string)(parse_url((string)$c['base'], PHP_URL_HOST) ?: 'unknown');
      $r = llm_chat_one((string)$c['base'], (string)$c['key'], (string)$c['model'], $messages, $tools, $userId, $provider);
      if (!isset($r['error'])) return $r;
      $last = $r;
    }
    return $last;
  } finally {
    try { $r=db()->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$lockName]); } catch(Throwable $e) {}
  }
}

function atlas_tools(): array {
  $t = [
    ['type'=>'function','function'=>['name'=>'web_search','description'=>'Web\'de arama yapar ve özet sonuç döner. Güncel bilgi/araştırma için kullan.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Arama sorgusu']],'required'=>['query']]]],
    ['type'=>'function','function'=>['name'=>'fetch_page','description'=>'Bir web sayfasını açar ve metin içeriğini getirir.','parameters'=>['type'=>'object','properties'=>['url'=>['type'=>'string']],'required'=>['url']]]],
    ['type'=>'function','function'=>['name'=>'find_contact','description'=>'Rehberde bir kişiyi isimle arar (telefon/e-posta döner).','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string']],'required'=>['name']]]],
    ['type'=>'function','function'=>['name'=>'list_contacts','description'=>'Rehberdeki kişileri isme göre sıralı listeler (gerçek kayıtlar). Kullanıcı "rehberimi sırala/listele", "ilk 10 kişi", "A ile başlayanlar" gibi bir şey isterse MUTLAKA bunu çağır.','parameters'=>['type'=>'object','properties'=>['limit'=>['type'=>'integer','description'=>'kaç kişi (varsayılan 10, en fazla 50)'],'offset'=>['type'=>'integer','description'=>'kaçıncı kayıttan itibaren (varsayılan 0)'],'starts_with'=>['type'=>'string','description'=>'sadece bu harf/ön ek ile başlayanlar (opsiyonel)']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'import_contacts','description'=>'Kullanıcının bağlı WhatsApp hattındaki tüm kişileri {APP} rehberine aktarır. Kullanıcı "rehberimi aktar/içe aktar/rehberimi yükle" dediğinde ya da rehber boşken kişi araması yapılacaksa çağır.','parameters'=>['type'=>'object','properties'=>new stdClass(),'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'add_contact','description'=>'Rehbere kişi ekler veya günceller.','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string'],'phone'=>['type'=>'string'],'email'=>['type'=>'string'],'note'=>['type'=>'string']],'required'=>['name']]]],
    ['type'=>'function','function'=>['name'=>'save_to_phone_contacts','description'=>'Kişiyi kullanıcının GERÇEK telefon rehberine kaydeder: bağlı Google hesabındaki Kişiler\'e yazar (Android/iPhone rehberine otomatik düşer); Google izni yoksa WhatsApp\'tan kişi kartı gönderir, kullanıcı tek dokunuşla ekler. Kullanıcı "telefon rehberime ekle / telefonuma kaydet / rehberime düşsün" dediğinde bunu çağır (add_contact sadece {APP} veritabanına yazar).','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string'],'phone'=>['type'=>'string','description'=>'Ülke kodlu numara ör. 905321112233'],'email'=>['type'=>'string']],'required'=>['name']]]],
    ['type'=>'function','function'=>['name'=>'send_contact_card','description'=>'WhatsApp\'tan kişi kartı (vCard) gönderir — alıcı karta dokunup telefon rehberine ekler. Rehberdeki isimleri names ile ver (numaralar veritabanından alınır) ya da name+phone yaz. to boş bırakılırsa kart kullanıcının kendi WhatsApp\'ına gelir.','parameters'=>['type'=>'object','properties'=>['names'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Rehberdeki kişi adları'],'name'=>['type'=>'string'],'phone'=>['type'=>'string'],'to_name'=>['type'=>'string','description'=>'Kartın gönderileceği kişi (rehberden)'],'to'=>['type'=>'string','description'=>'Kartın gönderileceği numara; boşsa kullanıcının kendisi']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'export_contacts_vcf','description'=>'Rehberi (veya bir bölümünü) tek .vcf dosyası olarak WhatsApp\'tan gönderir; kullanıcı dosyayı açınca telefon TOPLU içe aktarır. "Rehberimi telefonuma aktar/indir/yedekle" isteklerinde kullan. sync_google=true verilirse ayrıca bağlı Google hesabındaki Kişiler\'e de yazar.','parameters'=>['type'=>'object','properties'=>['starts_with'=>['type'=>'string','description'=>'sadece bu harf/ön ek ile başlayanlar'],'limit'=>['type'=>'integer','description'=>'en fazla kaç kişi (varsayılan hepsi, üst sınır 5000)'],'to'=>['type'=>'string','description'=>'gönderilecek numara; boşsa kullanıcının kendisi'],'sync_google'=>['type'=>'boolean','description'=>'Google Kişiler\'e de yaz']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'remember_fact','description'=>'Kullanıcı hakkında kalıcı bilgiyi hafızaya kaydeder (tercih, isim, iş, alışkanlık, önemli detay). Kullanıcı yeni kalıcı bir bilgi paylaşınca çağır.','parameters'=>['type'=>'object','properties'=>['fact'=>['type'=>'string','description'=>'Kısa, net cümle']],'required'=>['fact']]]],
    ['type'=>'function','function'=>['name'=>'forget_fact','description'=>'Hafızadaki bir bilgiyi siler (metne göre eşleşen).','parameters'=>['type'=>'object','properties'=>['fact'=>['type'=>'string']],'required'=>['fact']]]],
    ['type'=>'function','function'=>['name'=>'recall_history','description'=>'Eski konuşmalarda anahtar kelimeye göre arama yapar (çok önceki bir konuyu hatırlaman gerekirse).','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string']],'required'=>['query']]]],
    ['type'=>'function','function'=>['name'=>'create_reminder','description'=>'Hatırlatıcı oluşturur.','parameters'=>['type'=>'object','properties'=>['when_text'=>['type'=>'string','description'=>'ör. yarın 15:00'],'body'=>['type'=>'string']],'required'=>['body']]]],
    ['type'=>'function','function'=>['name'=>'get_call_number','description'=>'Aranacak kişinin numarasını rehberden bulup kullanıcıya verir (bot arama yapamaz, onay kuyruğu yok).','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string'],'phone'=>['type'=>'string']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'send_whatsapp','description'=>'WhatsApp mesajını kullanıcının bağlı hattından DOĞRUDAN gönderir. Kullanıcı "gönder" dediğinde bunu çağır, onay bekleme.','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string','description'=>'Rehberdeki kişi adı'],'phone'=>['type'=>'string','description'=>'Ülke kodlu numara, ör. 905321112233'],'message'=>['type'=>'string']],'required'=>['message']]]],
    ['type'=>'function','function'=>['name'=>'send_whatsapp_media','description'=>'WhatsApp\'tan fotoğraf, video, ses, PDF veya herhangi bir dosyayı kullanıcının bağlı hattından DOĞRUDAN gönderir. Birden fazla dosya verilirse albüm gibi arka arkaya gider. Kaynak olarak ya list_whatsapp_media ile bulunan media_id ya da bir dosya URL\'si kullan. ASLA "fotoğraf gönderemiyorum" deme.','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string','description'=>'Rehberdeki kişi adı'],'phone'=>['type'=>'string','description'=>'Ülke kodlu numara, ör. 905321112233'],'media_ids'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'list_whatsapp_media\'dan gelen id listesi'],'urls'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Gönderilecek dosya URL listesi'],'album'=>['type'=>'string','description'=>'Kayıtlı bir albümün adı; verilirse o albümdeki tüm dosyalar gönderilir'],'caption'=>['type'=>'string','description'=>'İlk dosyanın alt yazısı / mesaj metni']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'list_whatsapp_media','description'=>'Kullanıcının {APP}\'a WhatsApp\'tan gönderdiği kayıtlı fotoğraf/video/ses/dosyaları listeler (en yeniler önce). send_whatsapp_media için media_id bulmak amacıyla kullan.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Alt yazı/içerik/dosya adında geçen kelime'],'kind'=>['type'=>'string','description'=>'image, video, audio veya document'],'album'=>['type'=>'string','description'=>'Sadece bu albümdeki dosyalar'],'limit'=>['type'=>'integer','description'=>'Varsayılan 15']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'save_media_album','description'=>'Kullanıcının gönderdiği fotoğraf/dosyaları isimli bir albüm olarak kaydeder (ör. "Bodrum"). Kullanıcı "bunları X albümü olarak kaydet/kayıtlara ekle" dediğinde çağır. Mesajdaki medya_id\'leri media_ids olarak ver; yoksa last ile son N dosyayı etiketle.','parameters'=>['type'=>'object','properties'=>['album'=>['type'=>'string','description'=>'Albüm adı'],'media_ids'=>['type'=>'array','items'=>['type'=>'string']],'last'=>['type'=>'integer','description'=>'Son kaç dosya etiketlensin']],'required'=>['album']]]],
    ['type'=>'function','function'=>['name'=>'list_media_albums','description'=>'Kayıtlı medya albümlerini (ad ve dosya sayısı) listeler.','parameters'=>['type'=>'object','properties'=>new stdClass(),'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'gmail_search','description'=>'Kullanıcının Gmail gelen kutusunda arama yapar / son mailleri listeler. Gmail arama sözdizimi kullanılabilir (from:, is:unread, newer_than:2d).','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Gmail sorgusu, boş bırakılırsa son 7 gün']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'gmail_read','description'=>'Bir e-postanın tam içeriğini okur. Önce gmail_search ile id bul.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'calendar_list','description'=>'Google Takvimdeki yaklaşan etkinlikleri listeler.','parameters'=>['type'=>'object','properties'=>['days'=>['type'=>'integer','description'=>'Kaç günlük, varsayılan 7']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'calendar_add','description'=>'Google Takvime etkinlik ekler (randevu, toplantı).','parameters'=>['type'=>'object','properties'=>['title'=>['type'=>'string'],'start'=>['type'=>'string','description'=>'Başlangıç, ör. 2026-08-30 15:00'],'end'=>['type'=>'string'],'description'=>['type'=>'string'],'location'=>['type'=>'string']],'required'=>['title','start']]]],
    ['type'=>'function','function'=>['name'=>'calendar_delete','description'=>'Takvimden etkinlik siler (id calendar_list ile bulunur).','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'tasks_list','description'=>'Google Görevler listesindeki açık görevleri getirir.','parameters'=>['type'=>'object','properties'=>['show_done'=>['type'=>'boolean']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'tasks_add','description'=>'Google Görevler listesine yeni görev ekler.','parameters'=>['type'=>'object','properties'=>['title'=>['type'=>'string'],'due'=>['type'=>'string','description'=>'Son tarih, ör. 2026-09-01'],'notes'=>['type'=>'string']],'required'=>['title']]]],
    ['type'=>'function','function'=>['name'=>'tasks_done','description'=>'Bir Google görevini tamamlandı olarak işaretler (id tasks_list ile bulunur).','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'drive_search','description'=>'Kullanicinin Google Drive\'inda dosya/klasor arar (ad ve dosya icerigine gore). Kullanici Drive, dosya, dokuman, tablo, sunum, klasor sorunca ONCE bunu cagir.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Aranacak kelime, bos birakilirsa en son degisenler'],'type'=>['type'=>'string','description'=>'doc, sheet, slide, pdf, image, video, folder'],'folder'=>['type'=>'string','description'=>'Sadece bu klasorun icinde ara (klasor adi veya id)'],'limit'=>['type'=>'integer','description'=>'Varsayilan 15']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'drive_read','description'=>'Bir Drive dosyasinin icerigini okur: Google Dokuman/E-tablo/Sunum, PDF (metin cikarilir), gorsel (icerik anlatilir), metin dosyalari. Klasor id verilirse icindekileri listeler. id icin once drive_search kullan.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'drive_create','description'=>'Drive\'da yeni dosya olusturur: Google Dokuman (doc), E-tablo (sheet, icerik CSV olarak verilir), duz metin (text) veya klasor (folder).','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string'],'content'=>['type'=>'string','description'=>'Dosya icerigi (sheet icin CSV satirlari)'],'type'=>['type'=>'string','description'=>'doc | sheet | text | folder — varsayilan doc'],'folder'=>['type'=>'string','description'=>'Hedef klasor adi veya id']],'required'=>['name']]]],
    ['type'=>'function','function'=>['name'=>'drive_update','description'=>'Var olan bir Drive dosyasinin icerigini gunceller. append=true ise mevcut metnin sonuna ekler, degilse uzerine yazar.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'content'=>['type'=>'string'],'append'=>['type'=>'boolean']],'required'=>['id','content']]]],
    ['type'=>'function','function'=>['name'=>'drive_move','description'=>'Drive dosyasini baska klasore tasir ve/veya yeniden adlandirir.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'folder'=>['type'=>'string','description'=>'Hedef klasor adi veya id'],'new_name'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'drive_delete','description'=>'Drive dosyasini cop kutusuna tasir. Once kullanicidan net onay al.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'drive_share','description'=>'Drive dosyasini bir e-posta adresiyle paylasir ya da linki olan herkese acar. Kullanicidan onay aldiktan sonra cagir.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'email'=>['type'=>'string','description'=>'Paylasilacak kisi; bos ve anyone=true ise link paylasimi'],'name'=>['type'=>'string','description'=>'Rehberdeki kisi adi (e-postasi rehberden bulunur)'],'role'=>['type'=>'string','description'=>'reader | commenter | writer — varsayilan reader'],'anyone'=>['type'=>'boolean','description'=>'Linki olan herkese acilsin mi']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'drive_send_whatsapp','description'=>'Bir Drive dosyasini WhatsApp\'tan gonderir (Google Dokuman/Sunum PDF, E-tablo Excel olarak; digerleri oldugu gibi).','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'string','description'=>'Drive dosya id'],'name'=>['type'=>'string','description'=>'Rehberdeki kisi adi'],'phone'=>['type'=>'string','description'=>'Ulke kodlu numara'],'caption'=>['type'=>'string']],'required'=>['id']]]],
    ['type'=>'function','function'=>['name'=>'send_email','description'=>'E-postayı kullanıcının bağlı Gmail hesabından gönderir. ÖNCE taslağı (kime/konu/gövde) sohbette göstermiş ve kullanıcıdan net onay almış olmalısın; onaysız çağırma. Panel onay kuyruğu YOK.','parameters'=>['type'=>'object','properties'=>['name'=>['type'=>'string'],'email'=>['type'=>'string'],'subject'=>['type'=>'string'],'body'=>['type'=>'string'],'drive_ids'=>['type'=>'array','items'=>['type'=>'string'],'description'=>'Ek olarak eklenecek Drive dosya id listesi (drive_search ile bul)']],'required'=>['body']]]],
  ];
  if (function_exists('re_enabled') && re_enabled()) $t = array_merge($t, function_exists('re_tools_all') ? re_tools_all() : re_tools());
  $t = array_merge($t, lh_reg_tools());
  return $t;
}

function atlas_http_get(string $url, int $timeout = 20): string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
    CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8','Accept-Language: tr-TR,tr;q=0.9,en;q=0.8']]);
  $html = curl_exec($ch); curl_close($ch);
  return is_string($html) ? $html : '';
}

function atlas_search_mojeek(string $q): array {
  $html = atlas_http_get('https://www.mojeek.com/search?q=' . urlencode($q) . '&t=tr');
  if ($html === '') return [];
  $out = [];
  if (preg_match_all('~<h2><a class="title"[^>]*href="([^"]+)"[^>]*>(.*?)</a></h2>\s*(?:<p class="s">(.*?)</p>)?~s', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $r) {
      $out[] = ['url'=>html_entity_decode($r[1]), 'title'=>trim(html_entity_decode(strip_tags($r[2]))), 'snip'=>trim(html_entity_decode(strip_tags($r[3] ?? '')))];
    }
  }
  return $out;
}

function atlas_search_ddg(string $q): array {
  $ch = curl_init('https://lite.duckduckgo.com/lite/');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(['q'=>$q, 'kl'=>'tr-tr']),
    CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
    CURLOPT_FOLLOWLOCATION=>true]);
  $html = curl_exec($ch); curl_close($ch);
  if (!is_string($html) || $html === '') return [];
  $out = [];
  if (preg_match_all('~<a[^>]*class="result-link"[^>]*href="([^"]+)"[^>]*>(.*?)</a>~s', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $r) $out[] = ['url'=>html_entity_decode($r[1]), 'title'=>trim(html_entity_decode(strip_tags($r[2]))), 'snip'=>''];
  }
  return $out;
}

function atlas_bing_real_url(string $u): string {
  if (strpos($u, 'bing.com/ck/a') === false) return $u;
  if (preg_match('~[?&]u=a1([^&"]+)~', $u, $m)) {
    $b = strtr($m[1], '-_', '+/');
    $b .= str_repeat('=', (4 - strlen($b) % 4) % 4);
    $d = base64_decode($b, true);
    if ($d && preg_match('~^https?://~', $d)) return $d;
  }
  return $u;
}

function atlas_search_bing(string $q): array {
  $html = atlas_http_get('https://www.bing.com/search?q=' . urlencode($q) . '&setlang=tr&cc=TR&count=15');
  if ($html === '') return [];
  $out = [];
  if (preg_match_all('~<li class="b_algo".*?<h2[^>]*><a[^>]*href="([^"]+)"[^>]*>(.*?)</a></h2>(.*?)(?=<li class="b_algo"|</ol>)~s', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $r) {
      $snip = '';
      if (preg_match('~<p class="b_lineclamp[^"]*"[^>]*>(.*?)</p>~s', $r[3], $pm)) $snip = trim(html_entity_decode(strip_tags($pm[1])));
      $out[] = [
        'url' => atlas_bing_real_url(html_entity_decode($r[1])),
        'title' => trim(html_entity_decode(strip_tags($r[2]))),
        'snip' => $snip,
      ];
    }
  }
  return $out;
}

function atlas_tr_ascii(string $t): string {
  $map = ['ı'=>'i','İ'=>'I','ş'=>'s','Ş'=>'S','ğ'=>'g','Ğ'=>'G','ü'=>'u','Ü'=>'U','ö'=>'o','Ö'=>'O','ç'=>'c','Ç'=>'C','â'=>'a','î'=>'i','û'=>'u'];
  return strtr($t, $map);
}

function atlas_search_api(string $q): array {
  $raw = trim((string)gsetting('search_api_key', ''));
  if ($raw === '') return [];
  $keys = array_values(array_filter(array_map('trim', preg_split('~[,\s;]+~', $raw))));
  $prov = strtolower(trim((string)gsetting('search_provider', 'brave')));
  foreach ($keys as $key) {
    $out = [];
    if ($prov === 'serper') {
      $ch = curl_init('https://google.serper.dev/search');
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['X-API-KEY: ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['q'=>$q, 'gl'=>'tr', 'hl'=>'tr', 'num'=>10])]);
      $r = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      $j = json_decode((string)$r, true);
      foreach (($j['organic'] ?? []) as $it) {
        $out[] = ['url'=>$it['link'] ?? '', 'title'=>$it['title'] ?? '', 'snip'=>$it['snippet'] ?? ''];
      }
    } else {
      $ch = curl_init('https://api.search.brave.com/res/v1/web/search?country=TR&search_lang=tr&count=10&q=' . urlencode($q));
      curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20,
        CURLOPT_HTTPHEADER=>['Accept: application/json', 'X-Subscription-Token: ' . $key]]);
      $r = curl_exec($ch);
      $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
      $j = json_decode((string)$r, true);
      foreach (($j['web']['results'] ?? []) as $it) {
        $out[] = ['url'=>$it['url'] ?? '', 'title'=>strip_tags($it['title'] ?? ''), 'snip'=>strip_tags($it['description'] ?? '')];
      }
    }
    $out = array_values(array_filter($out, function ($x) { return !empty($x['url']) && !empty($x['title']); }));
    if ($out) return $out;
    // kota bitti / anahtar reddedildi -> siradaki anahtari dene
    if (!in_array($code, [401, 402, 403, 429], true) && $code >= 200 && $code < 300) return [];
    error_log('atlas search api key failed (http ' . $code . '), trying next');
  }
  return [];
}

function atlas_query_variants(string $q): array {
  $stop = ['civarinda','civari','yaklasik','tl','lira','milyon','bin','usd','euro','dolar','lutfen','bana','bul','icin','olan','bir','ev','arasi'];
  $ascii = atlas_tr_ascii($q);
  $keep = [];
  foreach (preg_split('/\s+/u', trim($ascii)) as $w) {
    $lw = mb_strtolower($w, 'UTF-8');
    if (preg_match('~^[0-9.,]+$~u', $w)) continue;
    if (in_array($lw, $stop, true)) continue;
    $keep[] = $w;
  }
  $short = implode(' ', array_slice($keep, 0, 6));
  $v = [];
  foreach ([$ascii, $short, $q] as $c) {
    $c = trim($c);
    if ($c !== '' && !in_array($c, $v, true)) $v[] = $c;
  }
  return $v;
}

function atlas_result_score(array $r, array $kw): int {
  $hay = mb_strtolower(atlas_tr_ascii($r['title'] . ' ' . $r['snip'] . ' ' . $r['url']), 'UTF-8');
  $n = 0;
  foreach ($kw as $k) if ($k !== '' && mb_strpos($hay, $k) !== false) $n++;
  return $n;
}

function tool_web_search(array $a): string {
  $q = trim((string)($a['query'] ?? ''));
  if ($q === '') return 'Sorgu boş.';
  $kw = [];
  foreach (preg_split('/\s+/u', mb_strtolower(atlas_tr_ascii($q), 'UTF-8')) as $w) {
    if (mb_strlen($w) >= 3 && !preg_match('~^[0-9.,]+$~u', $w)) $kw[] = $w;
  }
  $tried = [];
  $seen = [];
  $all = [];
  foreach (atlas_query_variants($q) as $vq) {
    foreach (['api'=>'atlas_search_api', 'bing'=>'atlas_search_bing', 'mojeek'=>'atlas_search_mojeek', 'ddg'=>'atlas_search_ddg'] as $name => $fn) {
      $res = $fn($vq);
      $tried[] = $name . '=' . count($res);
      foreach ($res as $r) {
        if (!preg_match('~^https?://~', $r['url']) || $r['title'] === '') continue;
        $skip = false;
        foreach (['go.microsoft.com', 'bing.com/', 'mojeek.com/search', 'duckduckgo.com/y.js'] as $bad) {
          if (strpos($r['url'], $bad) !== false) { $skip = true; break; }
        }
        if ($skip) continue;
        $key = preg_replace('~[#?].*$~', '', $r['url']);
        if (isset($seen[$key])) continue;
        $seen[$key] = 1;
        $r['score'] = atlas_result_score($r, $kw);
        $all[] = $r;
      }
      if ($res) break; // bu varyant icin bir motor yetti
    }
    $good = 0;
    foreach ($all as $r) if ($r['score'] >= max(2, (int)ceil(count($kw) * 0.6))) $good++;
    if ($good >= 5) break;
  }
  if (!$all) return 'Arama motorlarından sonuç alınamadı (' . implode(', ', $tried) . '). Kullanıcıya bilgiye ulaşamadığını dürüstçe söyle, uydurma.';
  usort($all, function ($x, $y) { return $y['score'] <=> $x['score']; });
  $lines = [];
  foreach (array_slice($all, 0, 8) as $r) {
    $lines[] = '- ' . $r['title'] . ($r['snip'] !== '' ? ' — ' . mb_substr($r['snip'], 0, 220) : '') . "\n  (" . $r['url'] . ')';
  }
  return implode("\n", $lines);
}

function tool_fetch_page(array $a): string {
  $url = $a['url'] ?? '';
  if (!preg_match('~^https?://~', $url)) return 'Geçersiz URL.';
  $html = atlas_http_get($url, 25);
  if ($html === '') return 'Sayfa alınamadı (erişim engeli olabilir).';
  $html = preg_replace('~<(script|style|noscript)[^>]*>.*?</\1>~is', ' ', $html);
  $txt = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
  if ($txt === '') return 'Sayfa boş döndü (JavaScript koruması olabilir).';
  return mb_substr($txt, 0, 6000);
}

/** Turkce/aksan duyarsiz normalizasyon (arama icin). */
function atlas_norm(string $s): string {
  $s = mb_strtolower(trim($s), 'UTF-8');
  $map = ['ı'=>'i','İ'=>'i','i̇'=>'i','ş'=>'s','ğ'=>'g','ü'=>'u','ö'=>'o','ç'=>'c','â'=>'a','î'=>'i','û'=>'u'];
  $s = strtr($s, $map);
  $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}

/** Rehberde bulanik (aksan/kelime sirasi duyarsiz) arama. */
function atlas_contact_search(string $name, int $limit = 8): array {
  $s = db()->prepare('SELECT name,phone,email,note FROM contacts WHERE user_id=?');
  $s->execute([atlas_uid()]);
  $rows = $s->fetchAll();
  $q = atlas_norm($name);
  if ($q === '') return $rows;
  $words = array_filter(explode(' ', $q));
  $hits = [];
  foreach ($rows as $r) {
    $n = atlas_norm((string)$r['name']);
    if ($n === '') continue;
    $score = 0;
    if ($n === $q) $score = 100;
    elseif (strpos($n, $q) !== false) $score = 80;
    else {
      /* Viktor 2026-08-30: Turkce cekim ekleri ("Bilgehanin ablasini" -> "Bilgehan Ablasi")
         tam substring eslesmesini bozuyordu; kelime bazli ONEK eslesmesi eklendi. */
      $nwords = array_values(array_filter(explode(' ', $n)));
      $ok = 0;
      foreach ($words as $w) {
        if ($w === '') continue;
        if (strpos($n, $w) !== false) { $ok++; continue; }
        foreach ($nwords as $nw) {
          $min = min(mb_strlen($w), mb_strlen($nw));
          if ($min >= 4 && mb_substr($w, 0, $min) === mb_substr($nw, 0, $min)) { $ok++; break; }
        }
      }
      $cw = max(1, count($words));
      if ($ok === $cw) $score = 55;
      elseif ($ok > 0) $score = 20 + $ok;
    }
    if ($score > 0) $hits[] = [$score, $r];
  }
  usort($hits, fn($x, $y) => $y[0] <=> $x[0]);
  return array_map(fn($h) => $h[1], array_slice($hits, 0, $limit));
}

function tool_find_contact(array $a): string {
  $name = trim((string)($a['name'] ?? ''));
  $cnt = db()->prepare('SELECT COUNT(*) FROM contacts WHERE user_id=?');
  $cnt->execute([atlas_uid()]);
  $total = (int)$cnt->fetchColumn();
  if ($total === 0) {
    // Rehber bos: kullanicinin bagli WhatsApp hattindan otomatik aktarmayi dene
    $imp = tool_import_contacts([]);
    $cnt->execute([atlas_uid()]);
    $total = (int)$cnt->fetchColumn();
    if ($total === 0) {
      return 'Rehber bos ve WhatsApp hattindan da aktarilamadi (' . $imp . '). Kullaniciya: WhatsApp hattini bagla, sonra Rehber sayfasindaki "WhatsApp rehberini ice aktar" butonuna bas.';
    }
  }
  if ($name === '') {
    return 'Rehberde toplam ' . $total . ' kişi kayıtlı. Aramak için isim ver.';
  }
  $rows = atlas_contact_search($name);
  if (!$rows) {
    $ph = wa_lookup_contact($name);
    if ($ph) return json_encode([['name'=>$name,'phone'=>$ph,'note'=>'WhatsApp hattindan']], JSON_UNESCAPED_UNICODE);
    return 'Rehberde "' . $name . '" bulunamadı (rehberde toplam ' . $total . ' kişi var).';
  }
  return json_encode($rows, JSON_UNESCAPED_UNICODE);
}

function atlas_wa_contact_list(): array {
  if (!WA_BRIDGE_URL) return [];
  $base = rtrim(WA_BRIDGE_URL, '/');
  $q = '?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . atlas_uid();
  foreach (['/sync', '/contacts'] as $path) {
    $ch = curl_init($base . $path . $q);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>45,
      CURLOPT_HTTPHEADER=>['X-Bridge-Key: ' . WA_BRIDGE_KEY]]);
    $raw = curl_exec($ch); curl_close($ch);
    if ($path === '/contacts') {
      $j = json_decode((string)$raw, true);
      return is_array($j['contacts'] ?? null) ? $j['contacts'] : [];
    }
  }
  return [];
}

/** WhatsApp hattindaki kisileri {APP} rehberine aktarir (api/wa.php import_contacts ile ayni mantik). */
function tool_import_contacts(array $a): string {
  $uid = atlas_uid();
  $list = atlas_wa_contact_list();
  if (!$list) return 'Rehber aktarilamadi: hat BAGLI ama WhatsApp bu cihaza kisi listesini hic gondermemis (eslesme sirasindaki senkron yarim kalmis). Tek kesin cozum: {APP} panelinde WhatsApp bolumunden hatti CIKAR ve YENIDEN BAGLA - kisiler eslesme aninda aktarilir. Alternatif: kisiyi WhatsApp kisi karti (vCard) olarak bana gonder ya da ad+numara yaz, tek tek ekleyeyim. Numarayi biliyorsan rehbere hic eklemeden de mesaj gonderebilirim.';
  $have = []; $names = [];
  $st = db()->prepare('SELECT name, phone FROM contacts WHERE user_id=?');
  $st->execute([$uid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $n = preg_replace('/[^0-9]/', '', (string)$row['phone']);
    if ($n !== '') $have[$n] = true;
    $nm = mb_strtolower(trim((string)$row['name']), 'UTF-8');
    if ($nm !== '') $names[$nm] = true;
  }
  $ins = db()->prepare('INSERT INTO contacts(user_id,name,phone,note) VALUES(?,?,?,?)');
  $added = 0; $skipped = 0;
  foreach ($list as $cc) {
    $phone = preg_replace('/[^0-9]/', '', (string)($cc['phone'] ?? ''));
    $name  = trim((string)($cc['name'] ?? ''));
    if ($phone === '' || isset($have[$phone])) { $skipped++; continue; }
    if ($name === '') $name = '+' . $phone;
    $name = mb_substr($name, 0, 160);
    if (isset($names[mb_strtolower($name, 'UTF-8')])) {
      $base = $name . ' (' . substr($phone, -4) . ')';
      $cand = $base; $i = 2;
      while (isset($names[mb_strtolower($cand, 'UTF-8')]) && $i < 50) { $cand = $base . ' ' . $i; $i++; }
      $name = mb_substr($cand, 0, 180);
    }
    try {
      $ins->execute([$uid, $name, $phone, 'WhatsApp rehberinden']);
      $names[mb_strtolower($name, 'UTF-8')] = true; $have[$phone] = true; $added++;
    } catch (PDOException $e) { $skipped++; }
  }
  $tot = db()->prepare('SELECT COUNT(*) FROM contacts WHERE user_id=?'); $tot->execute([$uid]);
  return 'Rehber aktarildi: ' . $added . ' yeni kisi eklendi, ' . $skipped . ' zaten vardi/atlandi. Rehberde toplam ' . (int)$tot->fetchColumn() . ' kisi.';
}

/* Viktor 2026-08-30: gercek rehber listesi (model uydurmasin diye). */
function tool_list_contacts(array $a): string {
  $limit  = max(1, min(50, (int)($a['limit'] ?? 10)));
  $offset = max(0, (int)($a['offset'] ?? 0));
  $sw     = trim((string)($a['starts_with'] ?? ''));
  $uid    = atlas_uid();
  $cnt = db()->prepare('SELECT COUNT(*) FROM contacts WHERE user_id=?');
  $cnt->execute([$uid]);
  $total = (int)$cnt->fetchColumn();
  if ($sw !== '') {
    $st = db()->prepare('SELECT name,phone,email FROM contacts WHERE user_id=? AND name LIKE ? ORDER BY (name REGEXP \'^[0-9+]\') ASC, name LIMIT ' . $limit . ' OFFSET ' . $offset);
    $st->execute([$uid, $sw . '%']);
  } else {
    $st = db()->prepare('SELECT name,phone,email FROM contacts WHERE user_id=? ORDER BY (name REGEXP \'^[0-9+]\') ASC, name LIMIT ' . $limit . ' OFFSET ' . $offset);
    $st->execute([$uid]);
  }
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 'Bu aralikta kayit yok (rehberde toplam ' . $total . ' kisi).';
  return json_encode(['total'=>$total,'offset'=>$offset,'contacts'=>$rows], JSON_UNESCAPED_UNICODE);
}

function tool_add_contact(array $a): string {
  $uid   = atlas_uid();
  $name  = trim((string)($a['name'] ?? ''));
  $phone = preg_replace('/[^0-9]/', '', (string)($a['phone'] ?? ''));
  if ($name === '') return 'Kişi adı gerekli.';
  if (strlen($phone) === 10 && $phone[0] === '5') $phone = '90' . $phone;
  elseif (strlen($phone) === 11 && substr($phone, 0, 2) === '05') $phone = '90' . substr($phone, 1);
  // MySQL uq_user_name aksan/buyuk-kucuk duyarsiz: ayni isim BASKA numarayla varsa
  // uzerine YAZMA, son 4 haneyi ekleyerek yeni kayit ac. (Viktor 2026-08-30)
  if ($phone !== '') {
    $q = db()->prepare('SELECT name, phone FROM contacts WHERE user_id=? AND name=? LIMIT 1');
    $q->execute([$uid, $name]);
    $ex = $q->fetch(PDO::FETCH_ASSOC);
    if ($ex) {
      $exp = preg_replace('/[^0-9]/', '', (string)$ex['phone']);
      if ($exp !== '' && substr($exp, -9) !== substr($phone, -9)) {
        $base = $name . ' (' . substr($phone, -4) . ')';
        $cand = $base; $i = 2;
        while ($i < 50) {
          $q->execute([$uid, $cand]);
          if (!$q->fetch(PDO::FETCH_ASSOC)) break;
          $cand = $base . ' ' . $i; $i++;
        }
        $name = mb_substr($cand, 0, 180);
      }
    }
  }
  $s = db()->prepare('INSERT INTO contacts(user_id,name,phone,email,note) VALUES(?,?,?,?,?)
    ON DUPLICATE KEY UPDATE phone=VALUES(phone),email=COALESCE(VALUES(email),email),note=COALESCE(VALUES(note),note)');
  $s->execute([$uid, $name, $phone !== '' ? $phone : null, $a['email'] ?? null, $a['note'] ?? null]);
  return 'Kişi kaydedildi: ' . $name . ($phone !== '' ? ' (' . $phone . ')' : '');
}

/* --- Viktor 2026-08-30: TELEFON REHBERI ({APP} DB disi) --- */

/** Kisiyi gercek telefon rehberine kaydeder: Google Kisiler, olmazsa vCard karti. */
function tool_save_to_phone_contacts(array $a): string {
  $name  = trim((string)($a['name'] ?? ''));
  $phone = trim((string)($a['phone'] ?? ''));
  $email = trim((string)($a['email'] ?? ''));
  if ($name === '') return 'Kisi adi gerekli.';
  if ($phone === '') { [$p, $e] = resolve_contact($name); $phone = (string)($p ?? ''); if ($email === '') $email = (string)($e ?? ''); }
  if ($phone === '') return 'Bu kisi icin numara bulunamadi; numarayi sor.';
  // {APP} rehberine de yaz (tek kaynak kalmasin)
  tool_add_contact(['name'=>$name, 'phone'=>$phone, 'email'=>$email ?: null]);
  $g = gcontacts_add($name, $phone, $email);
  if (!empty($g['ok'])) {
    return 'GOOGLE KISILER: ' . $name . ' (' . $phone . ') ' . (!empty($g['dup']) ? 'zaten kayitliydi' : 'kaydedildi')
         . '. Telefon Google hesabiyla senkronsa kisi birkac dakika icinde telefon rehberinde gorunur.';
  }
  // Yedek yol: WhatsApp kisi karti
  $card = tool_send_contact_card(['name'=>$name, 'phone'=>$phone, 'email'=>$email]);
  $why = ($g['msg'] ?? '') === 'no_scope'
    ? 'Google Kisiler izni yok (kullanici Ayarlar > Google baglantisindan bir kez yeniden baglanmali).'
    : 'Google Kisiler yazilamadi: ' . (string)($g['msg'] ?? '');
  return $why . ' Bunun yerine kisi karti gonderildi -> ' . $card;
}

/** WhatsApp'tan kisi karti (vCard) gonderir. */
function tool_send_contact_card(array $a): string {
  $rows = [];
  $names = is_array($a['names'] ?? null) ? $a['names'] : [];
  if (($a['name'] ?? '') !== '' && !$names) {
    $phone = trim((string)($a['phone'] ?? ''));
    if ($phone === '') { [$p, $e] = resolve_contact((string)$a['name']); $phone = (string)($p ?? ''); }
    if ($phone === '') return 'Numara bulunamadi: ' . $a['name'];
    $rows[] = ['name'=>(string)$a['name'], 'phone'=>$phone, 'email'=>(string)($a['email'] ?? '')];
  }
  foreach ($names as $n) {
    $n = trim((string)$n); if ($n === '') continue;
    $hit = atlas_contact_search($n, 1);
    $r = $hit[0] ?? null;
    if (!$r || empty($r['phone'])) { $rows[] = ['name'=>$n, 'phone'=>'', 'skip'=>1]; continue; }
    $rows[] = ['name'=>$r['name'], 'phone'=>$r['phone'], 'email'=>$r['email'] ?? ''];
  }
  $miss = array_values(array_filter($rows, fn($r) => !empty($r['skip'])));
  $rows = array_values(array_filter($rows, fn($r) => empty($r['skip'])));
  if (!$rows) return 'Gonderilecek gecerli kisi yok' . ($miss ? ' (rehberde bulunamadi: ' . implode(', ', array_column($miss, 'name')) . ')' : '') . '.';
  $to = trim((string)($a['to'] ?? ''));
  if ($to === '' && ($a['to_name'] ?? '') !== '') { [$tp,] = resolve_contact((string)$a['to_name']); $to = (string)($tp ?? ''); }
  $payload = ['key'=>WA_BRIDGE_KEY, 'uid'=>atlas_uid(), 'contacts'=>$rows];
  if ($to !== '') $payload['to'] = $to;
  $j = wa_bridge_post('/send-contact', $payload, 60);
  if (empty($j['ok'])) return 'Kisi karti GONDERILEMEDI: ' . (string)($j['error'] ?? 'bilinmeyen hata');
  $who = $to !== '' ? ($to . ' numarasina') : 'kendi WhatsApp\'ina';
  return 'KISI KARTI GONDERILDI (' . count($rows) . ' kisi) ' . $who
       . '. Karta dokunup "Rehbere ekle" denince telefon rehberine kaydedilir.'
       . ($miss ? ' Bulunamayanlar: ' . implode(', ', array_column($miss, 'name')) . '.' : '');
}

/** Rehberi .vcf dosyasi olarak WhatsApp'tan gonderir (toplu ice aktarim). */
function tool_export_contacts_vcf(array $a): string {
  $uid = atlas_uid();
  $limit = max(1, min(5000, (int)($a['limit'] ?? 5000)));
  $sw = trim((string)($a['starts_with'] ?? ''));
  if ($sw !== '') {
    $st = db()->prepare('SELECT name,phone,email FROM contacts WHERE user_id=? AND name LIKE ? AND phone IS NOT NULL AND phone<>\'\' ORDER BY name LIMIT ' . $limit);
    $st->execute([$uid, $sw . '%']);
  } else {
    $st = db()->prepare('SELECT name,phone,email FROM contacts WHERE user_id=? AND phone IS NOT NULL AND phone<>\'\' ORDER BY name LIMIT ' . $limit);
    $st->execute([$uid]);
  }
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 'Disari aktarilacak kayit bulunamadi.';
  $vcf = gcontacts_vcf($rows);
  $extra = '';
  if (!empty($a['sync_google'])) {
    $g = gcontacts_add_many($rows);
    $extra = ($g['ok'] ?? 0) > 0
      ? ' Ayrica Google Kisiler\'e ' . $g['ok'] . ' kisi yazildi.'
      : ' Google Kisiler\'e yazilamadi (' . (($g['msg'] ?? '') === 'no_scope' ? 'izin yok, yeniden baglanmali' : (string)($g['msg'] ?? 'hata')) . ').';
  }
  $to = trim((string)($a['to'] ?? ''));
  $fname = 'rehber-' . date('Ymd') . ($sw !== '' ? '-' . preg_replace('/[^A-Za-z0-9]/', '', $sw) : '') . '.vcf';
  $payload = ['key'=>WA_BRIDGE_KEY, 'uid'=>$uid,
    'data'=>base64_encode($vcf), 'mime'=>'text/vcard', 'filename'=>$fname,
    'caption'=>count($rows) . ' kisi — dosyayi acip "Ice aktar" diyerek telefon rehberine ekleyebilirsin.'];
  if ($to !== '') $payload['to'] = $to; else {
    $st2 = wa_bridge_get('/status', []);
    $me = preg_replace('/[^0-9]/', '', (string)($st2['number'] ?? ''));
    if ($me === '') return 'Kendi numaran alinamadi; WhatsApp hatti bagli mi kontrol et.';
    $payload['to'] = $me;
  }
  $j = wa_bridge_post('/send-media', $payload, 120);
  if (empty($j['ok'])) return 'VCF GONDERILEMEDI: ' . (string)($j['error'] ?? 'bilinmeyen hata') . $extra;
  return 'REHBER DOSYASI GONDERILDI: ' . $fname . ' (' . count($rows) . ' kisi). Kullanici dosyayi acip telefon rehberine toplu aktarabilir.' . $extra;
}

function atlas_rem_key(string $t): array {
  $t = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t), 'UTF-8');
  return array_values(array_filter(explode(' ', $t), function ($w) { return mb_strlen($w) > 2; }));
}

/* Viktor 2026-08-31: WhatsApp'ta kendi kendine mesaj BILDIRIM URETMIYOR.
   Bu yuzden her hatirlatici icin ayrica telefonda gercek bildirim uretecek bir kanal kuruyoruz:
   1) Google bagliysa: popup bildirimli takvim etkinligi (Android + iOS).
   2) Degilse: alarmli .ics dosyasi WhatsApp'tan gonderilir (iPhone'da tek dokunusla takvime eklenir). */
function atlas_reminder_push(int $rid, string $title, string $dueLocal): string {
  require_once LH_INC . '/google.php';
  /* Viktor 2026-09-10: gorev/hatirlatici Google'a OTOMATIK yazilir; kullanicinin
     WhatsApp'a gidip .ics dosyasina dokunmasi GEREKMEZ. Saat varsa alarmli Takvim
     etkinligi + Google Gorevler; saat yoksa sadece Google Gorevler. */
  if (function_exists('gmail_ready') && gmail_ready()) {
    $note = ''; $ok = false;
    $ts = ($dueLocal !== '') ? strtotime($dueLocal) : false;
    if ($ts) {
      $old = '';
      try {
        $q = db()->prepare('SELECT gcal_event_id FROM reminders WHERE id=?');
        $q->execute([$rid]); $old = (string)($q->fetchColumn() ?: '');
      } catch (Throwable $e) {}
      $r = gcal_reminder_event($title, $dueLocal, $old);
      if (!empty($r['ok'])) {
        try { db()->prepare('UPDATE reminders SET gcal_event_id=? WHERE id=?')->execute([$r['id'], $rid]); } catch (Throwable $e) {}
        $note .= ' Google Takvim\'e otomatik eklendi (o saatte alarm çalacak).'; $ok = true;
      } else {
        $note .= ' (Takvime eklenemedi: ' . (string)($r['msg'] ?? '') . ')';
      }
    }
    if (function_exists('gtasks_add')) {
      try {
        $g = gtasks_add($title, $ts ? date('Y-m-d', $ts) : '',
          'LUNAHAN' . ($ts ? ' — ' . date('d.m.Y H:i', $ts) : ''));
        if (stripos($g, 'eklendi') !== false || stripos($g, 'ZATEN VAR') !== false) {
          $note .= ' Google Görevler\'e de otomatik eklendi.'; $ok = true;
        } else {
          $note .= ' (Görevlere eklenemedi: ' . mb_substr($g, 0, 120) . ')';
        }
      } catch (Throwable $e) {}
    }
    if ($ok) return $note;
    return $note !== '' ? $note : ' (Google\'a eklenemedi.)';
  }
  /* Google bagli degil: alarmli .ics gonder (son care) */
  try {
    if (!WA_BRIDGE_URL || $dueLocal === '' || !strtotime($dueLocal)) return '';
    $st = wa_bridge_get('/status', []);
    $me = preg_replace('/[^0-9]/', '', (string)($st['number'] ?? ''));
    if ($me === '') return '';
    $j = wa_bridge_post('/send-media', ['key'=>WA_BRIDGE_KEY, 'uid'=>atlas_uid(), 'to'=>$me,
      'data'=>base64_encode(atlas_build_ics($title, $dueLocal)), 'mime'=>'text/calendar',
      'filename'=>'hatirlatma-' . date('Hi', strtotime($dueLocal)) . '.ics',
      'caption'=>'📅 Bu dosyaya dokunup "Ekle" dersen telefonun tam saatinde alarm çalar (WhatsApp kendi kendine mesajda bildirim vermiyor).'], 60);
    if (!empty($j['ok'])) return ' Ayrıca alarmlı takvim dosyası (.ics) gönderildi — dokunup ekleyince telefonun saatinde bildirim çalar. Google hesabını bağlarsan bu otomatik olur.';
  } catch (Throwable $e) {}
  return '';
}

function tool_create_reminder(array $a): string {
  $when = (string)($a['when_text'] ?? '');
  $body = (string)($a['body'] ?? '');
  $due  = function_exists('atlas_when_to_dt') ? atlas_when_to_dt($when) : null;
  if (!$due) {
    /* Viktor 2026-09-10: saat yoksa is kaybolmasin — tarihsiz GOREV olarak Google
       Gorevler'e otomatik yazilir; saat gerekiyorsa kullaniciya sonra sorulur. */
    if ($body === '') return 'HATIRLATICI KURULMADI: ne hatırlatacağım belli değil, kullanıcıya sor.';
    $s0 = db()->prepare('INSERT INTO reminders(user_id,when_text,due_at,body) VALUES(?,?,NULL,?)');
    $s0->execute([atlas_uid(), $when !== '' ? $when : null, $body]);
    $p0 = atlas_reminder_push((int)db()->lastInsertId(), $body, '');
    return 'GÖREV OLARAK EKLENDİ (saat verilmedi, bu yüzden alarm kurulmadı).' . $p0
      . ' Kullanıcıya saat sormak istersen sor, ama görev zaten kayıtlı.';
  }
  /* Viktor 2026-08-31: ayni konudaki acik hatirlatici varsa YENISINI ACMA, saatini guncelle.
     Onceki surum sadece ayni saat icin kopya engelliyordu; kullanici "daha erken hatirlat"
     dedeginde eski kayit acik kaliyor + yeni kayit due_at NULL ile hic gonderilmiyordu. */
  $q = db()->prepare("SELECT id,body,due_at FROM reminders WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 20");
  $q->execute([atlas_uid()]);
  $tn = atlas_rem_key($body);
  foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $to = atlas_rem_key((string)$r['body']);
    $ratio = ($tn && $to) ? count(array_intersect($tn, $to)) / min(count($tn), count($to)) : 0;
    if ($ratio >= 0.6) {
      if ($r['due_at'] === $due) {
        return 'ZATEN VAR (tekrar kurulmadi): ' . date('d.m.Y H:i', strtotime($due)) . ' — ' . mb_substr((string)$r['body'], 0, 80);
      }
      $u = db()->prepare('UPDATE reminders SET when_text=?, due_at=?, body=? WHERE id=?');
      $u->execute([$when !== '' ? $when : null, $due, $body !== '' ? $body : $r['body'], (int)$r['id']]);
      $push = atlas_reminder_push((int)$r['id'], $body !== '' ? $body : (string)$r['body'], $due);
      return 'MEVCUT HATIRLATICI GUNCELLENDI — yeni zamanı: ' . date('d.m.Y H:i', strtotime($due)) . ', o saatte WhatsApp\'tan hatırlatılacak.' . $push;
    }
  }
  $s = db()->prepare('INSERT INTO reminders(user_id,when_text,due_at,body) VALUES(?,?,?,?)');
  $s->execute([atlas_uid(), $when !== '' ? $when : null, $due, $body]);
  $push = atlas_reminder_push((int)db()->lastInsertId(), $body, $due);
  return 'Hatırlatıcı oluşturuldu — zamanı: ' . date('d.m.Y H:i', strtotime($due)) . ', o saatte WhatsApp\'tan hatırlatılacak.' . $push;
}

/** WhatsApp köprüsündeki hat rehberinde isimle numara arar. */
function wa_lookup_contact(string $name): ?string {
  if (!WA_BRIDGE_URL || trim($name) === '') return null;
  $url = rtrim(WA_BRIDGE_URL, '/') . '/contacts?key=' . urlencode(WA_BRIDGE_KEY) . '&uid=' . atlas_uid();
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10,
    CURLOPT_HTTPHEADER=>['X-Bridge-Key: ' . WA_BRIDGE_KEY]]);
  $raw = curl_exec($ch); curl_close($ch);
  $j = json_decode((string)$raw, true);
  if (!is_array($j['contacts'] ?? null)) return null;
  $needle = mb_strtolower(trim($name), 'UTF-8');
  $best = null;
  foreach ($j['contacts'] as $c) {
    $cn = mb_strtolower(trim((string)($c['name'] ?? '')), 'UTF-8');
    if ($cn === '') continue;
    if ($cn === $needle) return (string)$c['phone'];
    if ($best === null && mb_strpos($cn, $needle) !== false) $best = (string)$c['phone'];
  }
  return $best;
}

function resolve_contact(?string $name): array {
  if (!$name) return [null,null];
  $hit = atlas_contact_search($name, 1);
  $r = $hit[0] ?? null;
  $phone = $r['phone'] ?? null; $email = $r['email'] ?? null;
  if (!$phone) $phone = wa_lookup_contact($name);   // rehberde yoksa WhatsApp hattının kişilerine bak
  return [$phone, $email];
}

function queue_action(string $type, ?string $target, string $payload, array $meta = []): string {
  $s = db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status) VALUES(?,?,?,?,?,\'pending\')');
  $s->execute([atlas_uid(), $type, $target, $payload, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
  return db()->lastInsertId();
}

function tool_get_call_number(array $a): string {
  [$phone,] = resolve_contact($a['name'] ?? null);
  $phone = $a['phone'] ?? $phone;
  if (!$phone) return 'Numara bulunamadı; kişiyi rehbere ekle ya da numara ver.';
  db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status,result) VALUES(?,\'call\',?,?,?,\'sent\',\'numara verildi\')')
    ->execute([atlas_uid(), $phone, 'Arama: ' . $phone, json_encode(['name'=>$a['name']??null], JSON_UNESCAPED_UNICODE)]);
  return "Aranacak numara: $phone (arama botla yapılamaz, kullanıcı kendisi arar).";
}

/** WhatsApp mesajını kullanıcının kendi bağlı hattından doğrudan gönderir. */
function tool_send_whatsapp(array $a): string {
  [$phone,] = resolve_contact($a['name'] ?? null);
  $phone = $a['phone'] ?? $phone;
  $msg = trim((string)($a['message'] ?? ''));
  $num = preg_replace('/[^0-9]/', '', (string)$phone);
  if ($num === '') return 'Numara bulunamadı; kişiyi rehbere ekle ya da numarayı ver. Mesaj GÖNDERİLMEDİ.';
  if ($msg === '') return 'Mesaj metni boş, gönderilmedi.';
  $uid = atlas_uid();
  $status = 'sent'; $result = '';
  if (!WA_BRIDGE_URL) { $status = 'failed'; $result = 'Köprü ayarlı değil'; }
  else {
    $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . '/send');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>30,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>json_encode(['key'=>WA_BRIDGE_KEY,'uid'=>$uid,'to'=>$num,'message'=>$msg])]);
    $resp = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if (!is_array($j)) { $status='failed'; $result = 'Köprüye ulaşılamadı' . ($cerr ? " ($cerr)" : ''); }
    elseif (!empty($j['ok'])) { $result = 'Gönderildi' . (!empty($j['id']) ? ' (' . $j['id'] . ')' : ''); }
    else { $status='failed'; $result = $j['error'] ?? 'Gönderilemedi'; }
  }
  $ins = db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status,result) VALUES(?,\'whatsapp\',?,?,?,?,?)');
  $ins->execute([$uid, $num, $msg, json_encode(['name'=>$a['name']??null], JSON_UNESCAPED_UNICODE), $status, $result]);
  if ($status === 'sent') return "WhatsApp mesajı $num numarasına GÖNDERİLDİ.";
  return "Mesaj GÖNDERİLEMEDİ ($result). WhatsApp sayfasından hattın bağlı mı kontrol et.";
}

/** Köprüye JSON POST atar. */
function wa_bridge_post(string $path, array $payload, int $timeout = 120): array {
  if (!WA_BRIDGE_URL) return ['error' => 'Köprü ayarlı değil'];
  $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . $path);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>$timeout,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode($payload, JSON_UNESCAPED_UNICODE)]);
  $resp = curl_exec($ch); $cerr = curl_error($ch); curl_close($ch);
  $j = json_decode((string)$resp, true);
  if (!is_array($j)) return ['error' => 'Köprüye ulaşılamadı' . ($cerr ? " ($cerr)" : '')];
  return $j;
}

/** Kayıtlı ({APP}'a gelen) medyayı listeler. */
function tool_list_whatsapp_media(array $a): string {
  if (!WA_BRIDGE_URL) return 'WhatsApp köprüsü ayarlı değil.';
  $q = ['key'=>WA_BRIDGE_KEY, 'uid'=>atlas_uid(), 'limit'=>(int)($a['limit'] ?? 15)];
  if (!empty($a['query'])) $q['q'] = (string)$a['query'];
  if (!empty($a['kind']))  $q['kind'] = (string)$a['kind'];
  if (!empty($a['album'])) $q['album'] = (string)$a['album'];
  $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . '/media?' . http_build_query($q));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
    CURLOPT_HTTPHEADER=>['X-Bridge-Key: ' . WA_BRIDGE_KEY]]);
  $raw = curl_exec($ch); curl_close($ch);
  $j = json_decode((string)$raw, true);
  if (!is_array($j) || empty($j['ok'])) return 'Medya listesi alınamadı.';
  $list = $j['media'] ?? [];
  if (!$list) return 'Kayıtlı medya bulunamadı (kullanıcı {APP} sohbetine henüz dosya/fotoğraf göndermemiş olabilir).';
  $out = [];
  foreach ($list as $m) {
    $out[] = '- id=' . $m['id'] . ' | ' . $m['kind'] . ' | ' . date('d.m.Y H:i', (int)$m['ts'])
           . ' | ' . ($m['filename'] ?? '')
           . (($m['album'] ?? '') !== '' ? ' | albüm: ' . $m['album'] : '')
           . (($m['caption'] ?? '') !== '' ? ' | not: ' . $m['caption'] : '')
           . (($m['desc'] ?? '') !== '' ? ' | içerik: ' . mb_substr($m['desc'], 0, 120) : '');
  }
  return "Kayıtlı medya (en yeni önce):\n" . implode("\n", $out);
}

/** Köprüden GET ile veri çeker. */
function wa_bridge_get(string $path, array $query): array {
  if (!WA_BRIDGE_URL) return ['error' => 'Köprü ayarlı değil'];
  $query['key'] = WA_BRIDGE_KEY; $query['uid'] = atlas_uid();
  $ch = curl_init(rtrim(WA_BRIDGE_URL, '/') . $path . '?' . http_build_query($query));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30,
    CURLOPT_HTTPHEADER=>['X-Bridge-Key: ' . WA_BRIDGE_KEY]]);
  $raw = curl_exec($ch); curl_close($ch);
  $j = json_decode((string)$raw, true);
  return is_array($j) ? $j : ['error' => 'Köprüye ulaşılamadı'];
}

/** Gelen medyayı isimli albüm olarak kaydeder. */
function tool_save_media_album(array $a): string {
  $album = trim((string)($a['album'] ?? ''));
  if ($album === '') return 'Albüm adı gerekli.';
  $payload = ['key'=>WA_BRIDGE_KEY, 'uid'=>atlas_uid(), 'album'=>$album];
  $ids = array_values(array_filter(array_map('strval', (array)($a['media_ids'] ?? []))));
  if ($ids) $payload['media_ids'] = $ids;
  elseif (!empty($a['last'])) $payload['last'] = (int)$a['last'];
  else return 'Hangi dosyalar? Mesajdaki medya_id\'leri ya da last (son N dosya) ver.';
  $j = wa_bridge_post('/media-album', $payload, 30);
  if (empty($j['ok'])) return 'Albüm kaydedilemedi: ' . (string)($j['error'] ?? 'bilinmeyen hata');
  return (int)$j['tagged'] . " dosya \"$album\" albümüne KAYDEDİLDİ. Kullanıcıya albümü daha sonra listeleyebileceğini veya birine gönderebileceğini söyle.";
}

/** Kayıtlı albümleri listeler. */
function tool_list_media_albums(array $a): string {
  $j = wa_bridge_get('/albums', []);
  if (empty($j['ok'])) return 'Albüm listesi alınamadı.';
  if (empty($j['albums'])) return 'Kayıtlı albüm yok.';
  $out = [];
  foreach ($j['albums'] as $al) $out[] = '- ' . $al['album'] . ' (' . (int)$al['count'] . ' dosya, son: ' . date('d.m.Y', (int)$al['last']) . ')';
  return "Albümler:\n" . implode("\n", $out);
}

/** Fotoğraf / video / ses / PDF'i kullanıcının bağlı hattından gönderir. */
function tool_send_whatsapp_media(array $a): string {
  [$phone,] = resolve_contact($a['name'] ?? null);
  $phone = $a['phone'] ?? $phone;
  $num = preg_replace('/[^0-9]/', '', (string)$phone);
  if ($num === '') return 'Numara bulunamadı; kişiyi rehbere ekle ya da numarayı ver. Dosya GÖNDERİLMEDİ.';
  $items = [];
  foreach ((array)($a['media_ids'] ?? []) as $id) { $id = trim((string)$id); if ($id !== '') $items[] = ['media_id'=>$id]; }
  foreach ((array)($a['urls'] ?? []) as $u) { $u = trim((string)$u); if ($u !== '') $items[] = ['url'=>$u]; }
  /* yerel dosya yollari (Anahtar albumleri) — Viktor 2026-09-10 */
  foreach ((array)($a['paths'] ?? []) as $fp) { $fp = trim((string)$fp); if ($fp !== '') $items[] = ['path'=>$fp]; }
  $albumName = trim((string)($a['album'] ?? ''));
  if (!$items && $albumName !== '') $items = null; // köprü albümü kendisi çözer
  if ($items === [] && $albumName === '') return 'Gönderilecek dosya belirtilmedi. Önce list_whatsapp_media ile media_id bul ya da bir dosya URL\'si ver.';
  $caption = trim((string)($a['caption'] ?? ''));
  $uid = atlas_uid();
  $payload = ['key'=>WA_BRIDGE_KEY, 'uid'=>$uid, 'to'=>$num, 'items'=>(array)$items, 'caption'=>$caption];
  if ($albumName !== '') $payload['album'] = $albumName;
  $j = wa_bridge_post('/send-media', $payload);
  $ok = !empty($j['ok']);
  $result = $ok ? ('Gönderildi (' . (int)$j['sent'] . ' dosya)' . (!empty($j['errors']) ? '; hatalı: ' . implode(', ', (array)$j['errors']) : ''))
                : (string)($j['error'] ?? 'Gönderilemedi');
  db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status,result) VALUES(?,\'whatsapp\',?,?,?,?,?)')
    ->execute([$uid, $num, ($caption !== '' ? $caption : '(medya)'),
               json_encode(['name'=>$a['name']??null,'media'=>$items,'album'=>$albumName], JSON_UNESCAPED_UNICODE),
               $ok ? 'sent' : 'failed', $result]);
  if ($ok) return "WhatsApp'tan $num numarasına " . (int)$j['sent'] . " dosya GÖNDERİLDİ."
    . (!empty($j['errors']) ? ' Gönderilemeyenler: ' . implode(', ', (array)$j['errors']) : '');
  return "Dosya GÖNDERİLEMEDİ ($result). WhatsApp sayfasından hattın bağlı mı kontrol et.";
}

/** Drive dosyasini paylasir; kisi adi verilirse e-postasini rehberden bulur. */
function tool_drive_share(array $a): string {
  $email = trim((string)($a['email'] ?? ''));
  if ($email === '' && !empty($a['name'])) { [,$e] = resolve_contact((string)$a['name']); $email = (string)$e; }
  $anyone = !empty($a['anyone']) || $email === '';
  return gdrive_share((string)($a['id'] ?? ''), $email, (string)($a['role'] ?? 'reader'), $anyone);
}

/** Drive dosyasini WhatsApp'tan gonderir. */
function tool_drive_send_whatsapp(array $a): string {
  $id = trim((string)($a['id'] ?? ''));
  if ($id === '') return 'Drive dosya id gerekli (once drive_search).';
  [$phone,] = resolve_contact($a['name'] ?? null);
  $phone = $a['phone'] ?? $phone;
  $num = preg_replace('/[^0-9]/', '', (string)$phone);
  if ($num === '') return 'Numara bulunamadi; kisiyi rehbere ekle ya da numarayi ver. Dosya GONDERILMEDI.';
  [$bytes, $fname, $mime] = gdrive_bytes($id);
  if ($bytes === null) return 'Dosya indirilemedi: ' . $fname . ' ' . gdrive_scope_hint((string)$fname);
  if (strlen($bytes) > 60 * 1024 * 1024) return 'Dosya cok buyuk (60MB ustu). Bunun yerine drive_share ile link paylas.';
  $caption = trim((string)($a['caption'] ?? ''));
  $uid = atlas_uid();
  $payload = ['key'=>WA_BRIDGE_KEY, 'uid'=>$uid, 'to'=>$num, 'caption'=>$caption,
    'items'=>[['data'=>base64_encode($bytes), 'mime'=>$mime ?: 'application/octet-stream', 'filename'=>$fname]]];
  $j = wa_bridge_post('/send-media', $payload);
  $ok = !empty($j['ok']);
  $result = $ok ? 'Gonderildi' : (string)($j['error'] ?? 'Gonderilemedi');
  db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status,result) VALUES(?,\'whatsapp\',?,?,?,?,?)')
    ->execute([$uid, $num, ($caption !== '' ? $caption : '(drive dosyasi)'),
               json_encode(['drive_id'=>$id, 'file'=>$fname], JSON_UNESCAPED_UNICODE),
               $ok ? 'sent' : 'failed', $result]);
  return $ok ? "WhatsApp'tan $num numarasina \"$fname\" GONDERILDI."
             : "Dosya GONDERILEMEDI ($result).";
}

/** E-postayı kullanıcının bağlı Gmail hesabından doğrudan gönderir (onay kuyruğu yok). */
function tool_send_email(array $a): string {
  [,$email] = resolve_contact($a['name'] ?? null);
  $email = trim((string)($a['email'] ?? $email));
  $subject = trim((string)($a['subject'] ?? ''));
  $body = (string)($a['body'] ?? '');
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Geçerli alıcı e-posta adresi yok; kullanıcıya sor. Mail GÖNDERİLMEDİ.';
  if (trim($body) === '') return 'Mail metni boş, gönderilmedi.';
  if ($subject === '') $subject = 'Mesaj';
  $status = 'sent'; $result = '';
  if (!gmail_ready()) { $status = 'failed'; $result = 'Gmail bağlı değil'; }
  else {
    $atts = [];
    foreach ((array)($a['drive_ids'] ?? []) as $did) {
      $did = trim((string)$did); if ($did === '') continue;
      [$bytes, $fname, $mime] = gdrive_bytes($did);
      if ($bytes === null) return 'Drive eki alinamadi (' . $fname . '). Mail GÖNDERİLMEDİ.';
      if (strlen($bytes) > 20 * 1024 * 1024) return 'Ek çok büyük (20MB üstü): ' . $fname . '. Bunun yerine drive_share ile link paylaş.';
      $atts[] = ['name'=>$fname, 'mime'=>$mime ?: 'application/octet-stream', 'data'=>$bytes];
    }
    [$ok, $m] = gmail_send($email, $subject, $body, $atts);
    $status = $ok ? 'sent' : 'failed'; $result = (string)$m . ($atts ? ' | ' . count($atts) . ' ek' : '');
  }
  db()->prepare('INSERT INTO actions(user_id,type,target,payload,meta_json,status,result) VALUES(?,\'email\',?,?,?,?,?)')
    ->execute([atlas_uid(), $email, $body, json_encode(['name'=>$a['name']??null,'subject'=>$subject], JSON_UNESCAPED_UNICODE), $status, $result]);
  if ($status === 'sent') return "E-posta $email adresine GÖNDERİLDİ (konu: $subject).";
  return "E-posta GÖNDERİLEMEDİ ($result). Gmail bağlı değilse {APP}'ta Google sayfasından bağlanması gerekiyor.";
}

function run_tool(string $name, array $args): string {
  $reg = lh_reg_run($name, $args);
  if ($reg !== null) return (string)$reg;
  if (str_starts_with($name, 're_') && function_exists('re_run_tool') && re_enabled()) {
    $r = re_run_tool($name, $args);
    if ($r !== null) return $r;
  }
  switch ($name) {
    case 'web_search': return tool_web_search($args);
    case 'fetch_page': return tool_fetch_page($args);
    case 'find_contact': return tool_find_contact($args);
    case 'list_contacts': return tool_list_contacts($args);
    case 'add_contact': return tool_add_contact($args);
    case 'save_to_phone_contacts': return tool_save_to_phone_contacts($args);
    case 'send_contact_card': return tool_send_contact_card($args);
    case 'export_contacts_vcf': return tool_export_contacts_vcf($args);
    case 'import_contacts': return tool_import_contacts($args);
    case 'create_reminder': return tool_create_reminder($args);
    case 'remember_fact': return tool_remember_fact($args);
    case 'forget_fact': return tool_forget_fact($args);
    case 'recall_history': return tool_recall_history($args);
    case 'get_call_number': return tool_get_call_number($args);
    case 'send_whatsapp': return tool_send_whatsapp($args);
    case 'send_whatsapp_media': return tool_send_whatsapp_media($args);
    case 'list_whatsapp_media': return tool_list_whatsapp_media($args);
    case 'save_media_album': return tool_save_media_album($args);
    case 'list_media_albums': return tool_list_media_albums($args);
    case 'drive_search': return gdrive_search((string)($args['query'] ?? ''), (string)($args['type'] ?? ''), (int)($args['limit'] ?? 15), (string)($args['folder'] ?? ''));
    case 'drive_read': return gdrive_read((string)($args['id'] ?? ''));
    case 'drive_create': return gdrive_create((string)($args['name'] ?? ''), (string)($args['content'] ?? ''), (string)($args['type'] ?? 'doc'), (string)($args['folder'] ?? ''));
    case 'drive_update': return gdrive_update((string)($args['id'] ?? ''), (string)($args['content'] ?? ''), !empty($args['append']));
    case 'drive_move': return gdrive_move((string)($args['id'] ?? ''), (string)($args['folder'] ?? ''), (string)($args['new_name'] ?? ''));
    case 'drive_delete': return gdrive_trash((string)($args['id'] ?? ''));
    case 'drive_share': return tool_drive_share($args);
    case 'drive_send_whatsapp': return tool_drive_send_whatsapp($args);
    case 'send_email': return tool_send_email($args);
    case 'gmail_search': return gmail_list((string)($args['query'] ?? ''));
    case 'gmail_read': return gmail_read((string)($args['id'] ?? ''));
    case 'calendar_list': return gcal_list((int)($args['days'] ?? 7));
    case 'calendar_add': return gcal_add((string)($args['title'] ?? ''), (string)($args['start'] ?? ''), (string)($args['end'] ?? ''), (string)($args['description'] ?? ''), (string)($args['location'] ?? ''));
    case 'calendar_delete': return gcal_delete((string)($args['id'] ?? ''));
    case 'tasks_list': return gtasks_list(!empty($args['show_done']));
    case 'tasks_add': return gtasks_add((string)($args['title'] ?? ''), (string)($args['due'] ?? ''), (string)($args['notes'] ?? ''));
    case 'tasks_done': return gtasks_done((string)($args['id'] ?? ''));

    default: return 'Bilinmeyen araç: ' . $name;
  }
}

/* ---------- Hafıza (kalıcı bilgi + konuşma geçmişi) ---------- */
function tool_remember_fact(array $a): string {
  $f = trim($a['fact'] ?? '');
  if ($f === '') return 'Boş bilgi.';
  $st = db()->prepare('INSERT INTO memories(user_id,fact) VALUES(?,?) ON DUPLICATE KEY UPDATE updated_at=NOW()');
  $st->execute([atlas_uid(), mb_substr($f, 0, 500)]);
  return 'Hafızaya kaydedildi: ' . $f;
}
function tool_forget_fact(array $a): string {
  $f = trim($a['fact'] ?? '');
  if ($f === '') return 'Boş.';
  $st = db()->prepare('DELETE FROM memories WHERE user_id=? AND fact LIKE ?');
  $st->execute([atlas_uid(), '%' . $f . '%']);
  return $st->rowCount() ? 'Silindi.' : 'Eşleşen kayıt yok.';
}
function tool_recall_history(array $a): string {
  $q = trim($a['query'] ?? '');
  if ($q === '') return 'Boş sorgu.';
  $st = db()->prepare('SELECT user_text, agent_reply, created_at FROM commands WHERE user_id=? AND (user_text LIKE ? OR agent_reply LIKE ?) ORDER BY id DESC LIMIT 8');
  $st->execute([atlas_uid(), '%' . $q . '%', '%' . $q . '%']);
  $out = [];
  foreach ($st->fetchAll() as $r) {
    $out[] = '[' . $r['created_at'] . '] Kullanıcı: ' . mb_substr($r['user_text'], 0, 200)
      . "\n  {APP}: " . mb_substr((string)$r['agent_reply'], 0, 300);
  }
  return $out ? implode("\n", $out) : 'Eski konuşmalarda bulunamadı.';
}
function atlas_memory_block(): string {
  try { $st = db()->prepare('SELECT fact FROM memories WHERE user_id=? ORDER BY id DESC LIMIT 40');
        $st->execute([atlas_uid()]); $rows = $st->fetchAll(); }
  catch (Throwable $e) { return ''; }
  if (!$rows) return '';
  $lines = array_map(fn($r) => '- ' . $r['fact'], $rows);
  return "\n\nKULLANICI HAKKINDA HATIRLADIKLARIN:\n" . implode("\n", $lines);
}
/* Aktif sohbet (konusma) kimligi — api/command.php ayarlar */
function atlas_conv(?int $set = null): int {
  static $cid = 0;
  if ($set !== null) $cid = $set;
  return $cid;
}
function atlas_history_messages(int $limit = 12): array {
  try {
    $conv = atlas_conv();
    $sql = 'SELECT user_text, agent_reply FROM commands WHERE user_id=? AND agent_reply IS NOT NULL AND agent_reply <> \'\''
         . ($conv ? ' AND conv_id=?' : '') . ' ORDER BY id DESC LIMIT ' . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute($conv ? [atlas_uid(), $conv] : [atlas_uid()]);
    $rows = array_reverse($st->fetchAll());
  } catch (Throwable $e) { return []; }
  $msgs = [];
  foreach ($rows as $r) {
    $msgs[] = ['role'=>'user','content'=>mb_substr($r['user_text'], 0, 2000)];
    $rep = mb_substr($r['agent_reply'], 0, 2000);
    /* Eski surumden kalan "onay kuyrugu / gonderme yetkim yok" cevaplari modeli yaniltiyordu; gecmisten temizle. */
    if (preg_match('/onay kuyru[ğg]u|g[öo]nderme yetkim yok|arac[ıi]m yok|do[ğg]rudan (mesaj )?g[öo]nderemiyorum/iu', $rep)) {
      $rep = '(eski sürüme ait cevap — artık geçersiz; WhatsApp send_whatsapp, e-posta send_email ile onay sonrası gerçekten gönderiliyor)';
    }
    $msgs[] = ['role'=>'assistant','content'=>$rep];
  }
  return $msgs;
}

/** Ana giriş: kullanıcı metnini işle, cevabı ve kullanılan araçları döndür. */

/* Model gercekten bir eylem araci cagirdi mi */
function atlas_used_action_tool(array $used): bool {
  foreach ($used as $u) {
    if (in_array($u['tool'] ?? '', ['send_whatsapp','send_whatsapp_media','list_whatsapp_media','save_media_album','send_contact_card','save_to_phone_contacts','export_contacts_vcf','send_email','get_call_number','add_contact','create_reminder','remember_fact','forget_fact','re_add_listing','re_add_lead','re_update_listing','re_update_lead','re_set_followup','import_contacts','tasks_add','tasks_done','gcal_add','gcal_create','drive_create','drive_update','drive_delete','drive_share','drive_move','drive_send_whatsapp'], true)) return true;
  }
  return false;
}

/* Cevap "yaptim/ekledim/gonderdim" iddiasi iceriyor mu (TR+EN) */
function atlas_claims_action(string $reply): bool {
  /* Viktor 2026-08-28: EDILGEN bicimler de yakalanmali ("basariyla gonderildi",
     "iletildi", "olusturuldu") — model tool cagirmadan bunlari yaziyordu. */
  $pat = '/(kuyru[ğg]una?\s*(ald[ıi]m|ekledim)|ekledim|kaydettim|g[öo]nderdim|olu[şs]turdum|hat[ıi]rlatt[ıi]m|'
       . 'g[öo]nderil(di|mi[şs])|iletil(di|mi[şs])|olu[şs]turul(du|mu[şs])|eklen(di|mi[şs])|kaydedil(di|mi[şs])|'
       . 'rehberine?\s*(ekledim|kaydettim)|kur(du|dum|uldu)|ayarlad[ıi]m|planlad[ıi]m|sildim|silin(di|mi[şs])|'
       . 'g[üu]ncelledim|g[üu]ncellen(di|mi[şs])|i[şs]aretledim|tamamland[ıi]|'
       . 'hat[ıi]rlataca[ğg][ıi]m|hat[ıi]rlat[ıi]r[ıi]m|hat[ıi]rlat[ıi]c[ıi](y[ıi])?\s*(kur|g[üu]ncell)|kuruyorum|g[üu]ncelliyorum|ayarl[ıi]yorum|'
       . 'queued|added it|saved it|sent it|created it|has been (sent|added|saved|created|set)|i.?ve (added|saved|queued|sent|created|set))/iu';
  return (bool)preg_match($pat, $reply);
}

/* Viktor 2026-08-30: model find_contact cagirmadan "rehberde bulamadim" diyordu.
   Asagidaki iki yardimci + dongudeki guvenlik agi bunu engelliyor. */
function atlas_used_lookup_tool(array $used): bool {
  foreach ($used as $u) {
    if (in_array($u['tool'] ?? '', ['find_contact','list_contacts','import_contacts','add_contact'], true)) return true;
  }
  return false;
}

/* Cevap "rehberde bulamadim / kayitli degil" iddiasi iceriyor mu */
function atlas_claims_not_found(string $reply): bool {
  $pat = '/(bulamad[ıi]m|bulunamad[ıi]|rehber(im|in)?de\s+(yok|kay[ıi]tl[ıi]\s*de[ğg]il)|kay[ıi]tl[ıi]\s*de[ğg]il|'
       . 'kayd[ıi]\s*yok|couldn.?t find|not found|no contact)/iu';
  return (bool)preg_match($pat, $reply);
}

/* Kullanici metninden aranacak isim adaylarini cikar (komut kelimelerini at). */
function atlas_name_guess(string $userText): string {
  $t = preg_replace('/^\s*\[[^\]]*\]\s*/u', '', $userText);          // [WhatsApp · ...] onekini at
  $t = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $t);
  $stop = ['bul','bulur','bulsana','ara','arar','kisisini','kişisini','kisi','kişi','kişiyi','kisiyi','rehber','rehberde',
           'rehberimde','numarasi','numarası','numarasını','numara','telefon','telefonu','mesaj','gonder','gönder',
           'gonderir','at','musun','misin','mısın','lutfen','lütfen','ve','ile','icin','için','bana','bir','de','da',
           'albumleri','albümleri','album','albüm','fotolari','fotoğrafları','whatsapp','yaz'];
  $out = [];
  foreach (preg_split('/\s+/u', mb_strtolower(trim($t), 'UTF-8')) as $w) {
    if ($w === '' || mb_strlen($w) < 2) continue;
    if (in_array($w, $stop, true)) continue;
    $out[] = $w;
  }
  return implode(' ', array_slice($out, 0, 5));
}

/* Viktor 2026-08-30: cevapta gecen telefon numaralari GERCEKTEN arac sonucundan mi geliyor? */
/* URL/dosya adlarindaki rakamlar telefon sanilmasin (Viktor 2026-09-09: CSV/PDF indirme linkleri
   "portfoy-24-20260909-134351.csv" numara sanilip maskeleniyor, link bozuluyordu). */
function atlas_scan_text(string $t): string {
  return (string)preg_replace('#https?://\S+|\S+\.(?:csv|pdf|xlsx|vcf|jpg|jpeg|png|zip)\b#iu', ' ', $t);
}

function atlas_reply_phones(string $reply): array {
  $reply = atlas_scan_text($reply);
  preg_match_all('/\d[\d\s\-\(\)]{8,}\d/u', $reply, $m);
  $out = [];
  foreach ($m[0] as $x) {
    $d = preg_replace('/\D/', '', $x);
    if (strlen($d) >= 10) $out[] = $d;
  }
  return array_values(array_unique($out));
}

/* Numaralarin arac ciktilarinda gecmeyenlerini dondurur. */
function atlas_unbacked_phones(string $reply, array $used, string $userText = ''): array {
  $blob = $userText;
  foreach ($used as $u) { $blob .= ' ' . json_encode($u, JSON_UNESCAPED_UNICODE); }
  $blob = preg_replace('/\D/', ' ', $blob);
  $bad = [];
  foreach (atlas_reply_phones($reply) as $p) {
    /* Viktor 2026-08-30 (7): bicim farklari (0 onek, +90, bosluk) yuzunden GERCEK numaralar
       "uydurma" sanilip maskeleniyordu; son 9 hane uzerinden karsilastir. */
    $tail = substr($p, -9);
    if (strpos($blob, $p) === false && ($tail === '' || strpos($blob, $tail) === false)) $bad[] = $p;
  }
  return $bad;
}

/* Viktor 2026-08-30: e-posta adresleri de arac ciktisindan gelmeli (uydurma engeli). */
function atlas_unbacked_emails(string $reply, array $used, string $userText = ''): array {
  preg_match_all('/[\w.+-]+@[\w-]+\.[\w.]{2,}/u', $reply, $m);
  $blob = mb_strtolower($userText, 'UTF-8');
  foreach ($used as $u) { $blob .= ' ' . mb_strtolower(json_encode($u, JSON_UNESCAPED_UNICODE), 'UTF-8'); }
  $bad = [];
  foreach (array_unique($m[0]) as $e) {
    $le = mb_strtolower($e, 'UTF-8');
    if (mb_strpos($blob, $le) === false) $bad[] = $e;
  }
  return $bad;
}

/* Emlak (portfoy/musteri) verisi iddiasi var mi ve okuma araci cagrildi mi? */
function atlas_used_re_read_tool(array $used): bool {
  foreach ($used as $u) {
    $t = $u['tool'] ?? '';
    if (strpos($t, 're_') === 0 || in_array($t, ['recall_history','find_contact','list_contacts'], true)) return true;
  }
  return false;
}
function atlas_claims_re_data(string $reply): bool {
  /* Portfoy/musteri listesi ya da fiyat/metrekare/oda gibi kayit detayi veriyor mu? */
  $pat = '/(portf[öo]y|ilan|m[üu][şs]teri\s*kayd|lead|dair(e|eler)|arsa|d[üu]kkan|villa|kirac[ıi]|'
       . '\d+\s*\+\s*\d|\d+\s*m2|\d+\s*metrekare|\d[\d.,]{4,}\s*(tl|₺|usd|\$|euro|€))/iu';
  return (bool)preg_match($pat, $reply);
}


/* Viktor 2026-08-30 (7): mesajda gecen rehber kisilerini DB'den deterministik bul.
   Model find_contact cagirmasa bile gercek kayitlar sisteme enjekte edilir. */
/* Viktor 2026-08-30 (7): mesajda gecen rehber kisilerini DB'den deterministik bul. */
function atlas_match_contacts_in_text(string $text, int $limit = 10): array {
  $blob = atlas_norm($text);
  if ($blob === '') return [];
  /* Cok gecen genel kelimeler eslesme uretmesin (or. "numaralariyla" -> "... 7 Numara") */
  $stop = ['numara','numarasi','numaralari','telefon','rehber','rehberde','kisi','kisiyi','kisisine',
           'mesaj','gonder','album','albumu','dosya','dosyalar','kiraci','musteri','adres','fiyat',
           'lutfen','tamam','evet','hepsi','listele','yazar','yazsana','bilgi','whatsapp'];
  $s = db()->prepare('SELECT name,phone,email FROM contacts WHERE user_id=?');
  $s->execute([atlas_uid()]);
  $scored = [];
  foreach ($s->fetchAll() as $r) {
    $n = atlas_norm((string)$r['name']);
    if ($n === '' || mb_strlen($n) < 5) continue;
    $score = 0;
    if (preg_match('/(^| )' . preg_quote($n, '/') . '( |$)/u', $blob)) {
      $score = 1000 + mb_strlen($n);                       // tam ad esleşmesi
    } else {
      foreach (explode(' ', $n) as $w) {
        if (mb_strlen($w) < 5 || in_array($w, $stop, true)) continue;
        if (preg_match('/(^| )' . preg_quote($w, '/') . '/u', $blob)) $score = max($score, mb_strlen($w));
      }
    }
    if ($score > 0) { $r['_s'] = $score; $scored[] = $r; }
  }
  usort($scored, function ($a, $b) { return $b['_s'] <=> $a['_s']; });
  $out = [];
  foreach (array_slice($scored, 0, $limit) as $r) { unset($r['_s']); $out[] = $r; }
  return $out;
}

/* Viktor 2026-08-30: model bazen ic muhakemesini (chain-of-thought) cevap diye
   yaziyordu ("Kullanici net talimat verdi... onay bekliyorum"). Bunu yakala. */
function atlas_is_meta_reply(string $reply): bool {
  $r = trim($reply);
  if ($r === '') return false;
  $pat = '/(kullan[ıi]c[ıi]\s+(net\s+)?(talimat|onay|cevap|hen[üu]z)|ben\s+onay\s+sordum|onay(ı|i)n(ı|i)\s+bekliyorum|cevab(ı|i)m(ı|i)\s+burada\s+b(ı|i)rak|kullan[ıi]c[ıi]ya\s+(sormal|cevap\s+vermel)|bu\s+y[üu]zden\s+.{0,40}\s+bekle(meliyim|yece[ğg]im)|d[üu][şs][üu]n[üu]yorum\s+ki|the\s+user\s+(said|asked|has\s+not)|i\s+should\s+wait\s+for)/iu';
  return (bool)preg_match($pat, $r);
}

/* Meta/ic-ses cumlelerini cikar; geriye anlamli metin kalmazsa bos doner. */
function atlas_strip_meta(string $reply): string {
  $keep = [];
  foreach (preg_split('/\n/u', $reply) as $line) {
    if (atlas_is_meta_reply($line)) continue;
    $keep[] = $line;
  }
  return trim(implode("\n", $keep));
}

function atlas_process(string $userText, string $lang = 'tr', ?int $userId = null): array {
  $userId = $userId ?? (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);


  if ($lang === 'en') {
    $sys = "You are {APPFULL}, a personal assistant agent. "
      . "You understand the user's commands and get work done by calling the right tools: web research, "
      . "opening pages, contact management, reminders, sending WhatsApp messages and sending email from the user's own Gmail. There is NO approval queue. "
      . "WhatsApp flow: FIRST draft the message and ask the user to confirm ('Shall I send this message to X (number)?', showing the text). "
      . "As soon as the user clearly confirms (yes/send/ok), immediately call send_whatsapp — it really sends from the user's own connected line. "
      . "After confirmation never ask again and never say it is queued or awaiting approval; call the tool and report the result. EMAIL FLOW: never send blind — FIRST show the full draft in the chat as plain text (To: address / Subject: ... / then the body, exactly as it will be sent) and ask 'Shall I send it like this? (you can ask for changes)'. Only after a clear yes call send_email; if the user asks for edits, show the corrected draft again and wait for approval. You cannot place calls; use get_call_number to give the number. "
      . "IMPORTANT: when giving current or factual information (news, rates, prices, dates) you MUST rely on tool results. "
       . "SEARCH TIP: give web_search SHORT keywords, not a long sentence; never put budget figures in the query. If the first results look irrelevant, simplify and retry once or twice, then open promising links with fetch_page. Some sites (e.g. sahibinden.com) block bots; use other sources then. "
      . "If web_search or fetch_page returns nothing, NEVER invent the information; honestly tell the user you could not find it. "
      . "MEMORY: previous messages are provided as conversation history; refer to them, NEVER say you have no memory. " /*en_sys_mem*/
      . "AUTOMATIC LEARNING (IMPORTANT): whenever a lasting fact about the user comes up, save it with remember_fact WITHOUT asking permission and without mentioning it in your reply: name/how they like to be addressed, job and company, city, family and close people, frequent contacts and relationships, language and tone preference (formal/casual, short/long answers), working hours, routines, goals, likes and dislikes. Save it even if the user did not state it explicitly but it is clear from the conversation. NEVER store secrets (passwords, API keys, verification codes) or one-off/temporary tasks. If a stored fact turns out wrong, delete it with forget_fact and save the correct one. Use recall_history to search older conversations. "
      . "CONTACTS: the user's address book lives in the database and can hold thousands of entries. For ANY question about contacts (a person's number/email, how many contacts, is X saved) you MUST call find_contact first; call it with an empty name to get the total count. NEVER answer from memory or invent a contact list. If the first search returns nothing, retry with a shorter form of the name (e.g. just the first word) 1-2 times. NEVER say a person is not in the address book without an actual find_contact result. For listing requests (\"list my contacts\", \"first 10 contacts\") call list_contacts and use ONLY its output. add_contact only writes to the {APP} database: to put a contact into the user's REAL phone address book call save_to_phone_contacts (Google Contacts, syncs to the phone; falls back to a WhatsApp contact card), send_contact_card to share a tappable card, export_contacts_vcf to send the whole address book as one .vcf file. Never write a phone number that did not come from a tool result. "
      . "CRITICAL — NEVER LIE ABOUT ACTIONS: only say something was queued, saved, added or sent if you ACTUALLY called the matching tool in this turn and it returned success. Never write \"I have queued/added/saved it\" without the tool call. To send a WhatsApp message you MUST call send_whatsapp; for email send_email (it really sends from the user's connected Gmail); for a phone number get_call_number; for a contact add_contact; for a reminder create_reminder. If you lack information (e.g. a phone number), ask the user instead of pretending. "
      . "Reply in English, short, clear and friendly. Today is " . date('Y-m-d H:i') . " (Istanbul time).";
  } else {
    $sys = "Sen {APPFULL}'sın, Türkçe konuşan kişisel bir asistan agent. "
      . "Kullanıcının komutlarını anlar ve uygun araçları çağırarak iş yaparsın: web araştırması, "
      . "sayfa açma, rehber yönetimi, hatırlatıcı, WhatsApp mesajı gönderme ve e-posta/arama işlerini ONAY KUYRUĞUNA ekleme. "
      . "WhatsApp mesajı gönderme akışı: ÖNCE mesajı yaz ve kullanıcıya onaylat — 'X kişisine (numara) şu mesajı göndereyim mi?' diye sor ve metni göster. "
      . "Kullanıcı 'evet/gönder/tamam/olur' gibi net onay verince HEMEN send_whatsapp aracını çağır ve mesaj kullanıcının kendi bağlı hattından gerçekten gider. "
      . "Onay geldikten sonra bir daha sorma, 'kuyruğa ekledim / onayını bekliyorum' DEME; aracı çağır ve sonucu bildir. E-POSTA AKIŞI: mailleri ASLA görmeden gönderme. ÖNCE taslağı sohbette düz metin olarak tam göster — 'Kime: adres', 'Konu: ...', sonra gövde metni birebir gidecek hâliyle — ve 'Böyle gönderelim mi? (değişiklik isteyebilirsin)' diye sor. Kullanıcı net onay verince send_email aracını çağır; mail kendi bağlı Gmail hesabından gerçekten gider. Düzeltme isterse düzeltilmiş taslağı tekrar göster ve yeniden onay bekle; onay olmadan gönderme. Panelde onay kuyruğu YOK, 'kuyruğa ekledim / panelden onayla' DEME. Arama yapamazsın; numara gerekiyorsa get_call_number ile numarayı ver. "
      . "ÖNEMLI: Güncel/olgusal bilgi (haber, kur, fiyat, tarih vb.) verirken MUTLAKA araç sonucuna dayan. "
       . "ARAMA IPUCU: web_search icin uzun cumle degil KISA anahtar kelimeler kullan (or. bodrum satilik daire deniz manzarali); fiyat/butce rakamlarini sorguya koyma, sonuclari kendin ele. Ilk sonuclar alakasizsa sorguyu sadelestirip 1-2 kez daha dene ve umut verici baglantilari fetch_page ile ac. sahibinden.com gibi bazi emlak siteleri bot korumali olabilir; o zaman diger kaynaklari dene. "
      . "web_search veya fetch_page boş/başarısız dönerse bilgiyi ASLA uydurma; kullanıcıya bilgiye ulaşamadığını dürüstçe söyle. "
      . "HAFIZA: Önceki mesajlar konuşma geçmişi olarak sana verilir; bunlara atıfta bulun, 'hafızam yok' DEME. "
    . "OTOMATİK ÖĞRENME (ÇOK ÖNEMLİ): kullanıcı hakkında kalıcı bir bilgi geçtiğinde İZİN İSTEMEDEN, sormadan remember_fact ile kaydet ve bunu cevabında belirtme: adı/hitap şekli, işi ve şirketi, şehri, ailesi ve yakınları, sık iletişim kurduğu kişiler ve ilişkileri, dil/üslup tercihi (resmi-samimi, kısa-uzun cevap), çalışma saatleri, rutinleri, hedefleri, sevdiği/sevmediği şeyler. Bilgi kullanıcı tarafından açıkça söylenmese de konuşmadan net anlaşılıyorsa kaydet. Şifre, API anahtarı, doğrulama kodu gibi gizli verileri ve tek seferlik/geçici işleri ASLA kaydetme. Yanlış olduğu anlaşılan bilgiyi forget_fact ile sil ve doğrusunu kaydet. Çok eski bir konuyu araman gerekirse recall_history kullan. "
    . "REHBER: kullanıcının rehberi veritabanındadır ve binlerce kişi içerebilir. Rehberle ilgili HER soruda (kişinin numarası/e-postası, kaç kişi var, X kayıtlı mı) MUTLAKA önce find_contact aracını çağır; toplam sayı için name'i boş gönder. ASLA hafızandan cevap verme, kişi listesi uydurma. Bir kişinin adı geçtiğinde (\"X'i bul\", \"X'e gönder\", \"X'in numarası\") ÖNCE find_contact çağır; arama sonuç vermezse ismi kısaltıp (sadece ilk kelime, örn. \"Serkan Maden\" yerine \"Serkan\", \"Aaa Ömrümmm\" yerine \"ömrüm\") 1-2 kez daha dene. find_contact çağırmadan ASLA \"rehberde bulamadım / kayıtlı değil\" DEME. Rehberi sıralama/listeleme isteklerinde (\"rehberimi sırala\", \"ilk 10 kişi\", \"A ile başlayanlar\") list_contacts aracını çağır ve SADECE onun döndürdüğü isim/numaraları yaz. Telefon numaralarını ASLA kendin yazma/tahmin etme; yalnızca araç sonucundaki numarayı aktar. E-posta adresleri ve emlak kayıtları (ilan/müşteri: fiyat, metrekare, oda, adres) için de aynı kural geçerli: SADECE araç sonucundan gelen veriyi yaz, bilmiyorsan sor. TELEFON REHBERİ: add_contact SADECE {APP} veritabanına yazar. Kullanıcı kişinin GERÇEK telefon rehberine düşmesini isterse (\"telefonuma kaydet\", \"telefon rehberime ekle\") save_to_phone_contacts çağır (bağlı Google hesabındaki Kişiler'e yazar, telefona senkronla düşer; izin yoksa otomatik kişi kartı gönderir). Bir kişiyi başkasına iletmek ya da tek dokunuşla eklettirmek için send_contact_card, tüm rehberi toplu aktarmak için export_contacts_vcf (.vcf dosyası) kullan. Kullanıcı rehberini {APP}'a aktarmak isterse (\"rehberimi aktar/yükle\") import_contacts aracını çağır; WhatsApp'ta kişi kartı (vCard) paylaşarak da tek tek ekleyebilir. "
    . "ÇOK ÖNEMLI — YAPTIĞIN İŞLER HAKKINDA ASLA YALAN SÖYLEME: bir şeyi kuyruğa aldığını, kaydettiğini, eklediğini veya gönderdiğini SADECE ilgili aracı bu turda GERÇEKTEN çağırdıysan ve başarılı döndüyse söyle. Araç çağırmadan \"ekledim / kaydettim / kuyruğa aldım / gönderdim\" DEME. WhatsApp mesajı için MUTLAKA send_whatsapp, e-posta için send_email, numara için get_call_number, rehber için add_contact, hatırlatıcı için create_reminder aracını çağır. Eksik bilgi varsa (örn. telefon numarası) uydurma, kullanıcıya sor. "
    . "CEVAP BICIMI (COK ONEMLI): kullanıcıya DOĞRUDAN hitap et. İç muhakemeni, plan/analiz notlarını, \"kullanıcı şunu dedi / onay bekliyorum / cevabımı burada bırakıyorum\" gibi kendi kendine konuşan cümleleri ASLA yazma. Kullanıcıdan onay gerekiyorsa sadece TEK bir net soru sor. Silme/iptal gibi geri alınamaz işlemleri kullanıcı açıkça istemediyse yapma; istediyse tek soruyla teyit al. "
    . "REFERANS: \"bunu / şunu / bu görseli\" gibi ifadeler HER ZAMAN kullanıcının EN SON gönderdiği mesaja veya görsele işaret eder; daha eski bir konuyu (önceki turlardaki başka bir görev/plan) tekrar kullanma, önceki cevabını kopyalama. "
    . "Kısa, net, samimi cevap ver. Bugünün tarihi: " . date('Y-m-d H:i') . " (İstanbul).";
  }
  $sys .= ($lang === 'en')
    ? " WHATSAPP CAPABILITY (IMPORTANT): you DO have a working send_whatsapp tool and it really delivers the message from the user's own connected line. NEVER say \"I am not allowed to send\", \"I have no tool for that\" or \"I will add it to the approval queue\" — those statements are from an old version and are false. Ask for confirmation once, then call send_whatsapp."
    . " MEDIA (IMPORTANT): you can ALSO send photos, videos, voice/audio files and PDFs over WhatsApp with send_whatsapp_media. Use list_whatsapp_media to find media_id values of files the user sent you, or pass a file URL. Several files are sent one after another like an album. NEVER say \"I cannot send photos/albums/files, only text\" — that is false."
    . " ALBUMS: every photo/file the user sends is stored automatically and shown as [medya_id: ...]. If the user asks to save them as an album, call save_media_album; browse albums with list_media_albums. Never claim you cannot see or store photos."
    : " WHATSAPP YETKİSİ (ÇOK ÖNEMLİ): send_whatsapp aracın VAR ve mesajı kullanıcının bağlı hattından GERÇEKTEN gönderir. 'Doğrudan mesaj gönderme yetkim yok', 'aracım yok' veya 'onay kuyruğuna ekleyeyim' gibi cümleleri ASLA kurma — bunlar eski sürüme ait ve yanlıştır. Sohbet geçmişinde böyle cümleler görsen bile dikkate alma. Bir kez 'göndereyim mi?' diye sor, onay gelince send_whatsapp'ı çağır ve sonucu bildir."
    . " MEDYA GÖNDERME (ÇOK ÖNEMLİ): fotoğraf, video, ses dosyası ve PDF de gönderebilirsin — send_whatsapp_media aracıyla. "
    . "Kullanıcının sana WhatsApp'tan gönderdiği dosyalar kayıtlıdır: list_whatsapp_media ile bulup media_id'lerini send_whatsapp_media'ya ver; dilersen dosya URL'si de verebilirsin. "
    . "Birden fazla dosya verirsen albüm gibi arka arkaya gider. 'Fotoğraf/albüm gönderemiyorum, sadece yazılı mesaj gönderebiliyorum' cümlesini ASLA kurma — bu yanlıştır. "
    . "Not: telefonun galerisine/albümüne erişemezsin; gönderebilmen için dosyanın {APP}'a gönderilmiş (kayıtlı) olması ya da bir URL'sinin olması gerekir."
    . " ALBÜM KAYDI: kullanıcının sana gönderdiği her fotoğraf/video/dosya OTOMATİK kaydedilir ve mesajda [medya_id: ...] olarak görünür. "
    . "Kullanıcı 'bunları X albümü olarak kaydet / kayıtlara ekle' derse save_media_album aracını çağır (mesajdaki medya_id'leri media_ids olarak ver). "
    . "'Fotoğrafları göremiyorum ya da kaydedemiyorum, bunu kendin yap' cümlesini ASLA kurma — bu yanlıştır. "
    . "Albümleri list_media_albums ile listeler, list_whatsapp_media ile içeriğini görür, send_whatsapp_media'ya album adı vererek tamamını gönderirsin. "
    . "Birden fazla görsel tek mesajda gelirse bu bir albümdür: hepsi için TEK bir cevap ver.";
  $sys .= ($lang === 'en')
    ? " GOOGLE DRIVE (IMPORTANT): the user's Google Drive is connected through the same Google account. You CAN list files (drive_search), read their content (drive_read — Docs/Sheets/Slides, PDFs, images, text), create files or folders (drive_create), edit or append to them (drive_update), rename/move (drive_move), trash (drive_delete), share with a person or by link (drive_share), attach Drive files to email (send_email with drive_ids) and send them over WhatsApp (drive_send_whatsapp). NEVER say \"I cannot access your Drive/files\" — that is false. Always call drive_search first to get the file id; never invent file names or ids. Ask for confirmation before deleting, sharing or sending a file. If a tool reports missing Drive permission, tell the user to reconnect Google once from the {APP} panel (Google page)."
    : " GOOGLE DRIVE (ÇOK ÖNEMLİ): kullanıcının Google Drive'ı aynı Google bağlantısı üzerinden bağlıdır. Dosyaları LİSTELEYEBİLİR (drive_search), İÇERİĞİNİ OKUYABİLİR (drive_read — Google Dokuman/E-tablo/Sunum, PDF metni, görsel içeriği, metin dosyaları), yeni dosya/klasör OLUŞTURABİLİR (drive_create), içeriğini GÜNCELLEYEBİLİR veya sonuna EKLEYEBİLİR (drive_update), yeniden adlandırıp TAŞIYABİLİR (drive_move), ÇÖPE ATABİLİR (drive_delete), kişiyle ya da linkle PAYLAŞABİLİR (drive_share), e-postaya EK olarak koyabilir (send_email içinde drive_ids) ve WhatsApp'tan GÖNDEREBİLİRSİN (drive_send_whatsapp). "
    . "'Drive'a erişemiyorum / dosyalarını göremiyorum' cümlesini ASLA kurma — bu yanlıştır. Dosya id'sini MUTLAKA önce drive_search ile bul; dosya adı veya id uydurma. Silme, paylaşma ve gönderme işlemlerinden önce kullanıcıdan net onay al. "
    . "Bir araç 'Drive izni yok' derse kullanıcıya {APP} panelindeki Google sayfasından bağlantıyı bir kez yenilemesi gerektiğini söyle.";
  if (function_exists('re_enabled') && re_enabled()) { $sys .= re_system_prompt($lang); if (function_exists('rv_system_prompt')) $sys .= rv_system_prompt($lang); }
  $sys .= lh_reg_prompt($lang);
  $sys .= atlas_memory_block();
  $sys = lh_b($sys);
  $messages = array_merge(
    [['role'=>'system','content'=>$sys]],
    atlas_history_messages(12),
    [['role'=>'user','content'=>$userText]]
  );
  /* Viktor 2026-08-30 (7): Mesajda (ve son cevapta) gecen kisiler icin rehber
     kayitlarini ONCEDEN enjekte et — model find_contact cagirmasa bile numara gercek olsun. */
  $hintBlob = $userText;
  $hist = atlas_history_messages(8);
  // Son 6 mesaji da 'dogrulanmis kaynak' say: kullanicinin ONCEKI turda yazdigi numara
  // '(numara dogrulanamadi)' diye maskelenmesin. (Viktor 2026-08-30)
  for ($h = count($hist) - 1, $hc = 0; $h >= 0 && $hc < 6; $h--, $hc++) {
    $hintBlob .= ' ' . (is_string($hist[$h]['content'] ?? '') ? $hist[$h]['content'] : '');
  }
  $preContacts = atlas_match_contacts_in_text($hintBlob);
  $verified = '';
  if ($preContacts) {
    $verified = json_encode($preContacts, JSON_UNESCAPED_UNICODE);
    $messages[] = ['role'=>'system','content'=>
      'REHBER (veritabanindan DOGRULANMIS kayitlar, arac ciktisi sayilir): ' . $verified . '. '
      . 'Kullanicinin bahsettigi kisi bunlardan biriyse numarayi BURADAN al; ayrica find_contact cagirmana gerek yok. '
      . 'Gonderim istenmisse dogrudan send_whatsapp / send_whatsapp_media aracini bu numarayla CAGIR.'];
  }

  /* Viktor 2026-08-30 (7): kisa ONAY mesajlarinda ("gonder", "evet", "olur") model bazen
     hicbir arac cagirmadan "gonderildi" yaziyordu; onay turunu acikca isaretle. */
  $ut = trim(preg_replace('/^\s*\[[^\]]*\]\s*/u', '', $userText));
  if (preg_match('/^(g[öo]nder|yolla|at|evet|tamam|olur|onayl[ıi]yorum|ok|okey|hadi|yes|send)\b[\s.!]*$/iu', $ut)) {
    $lastA = '';
    for ($h = count($hist) - 1; $h >= 0; $h--) {
      if (($hist[$h]['role'] ?? '') === 'assistant' && is_string($hist[$h]['content'] ?? null)) { $lastA = $hist[$h]['content']; break; }
    }
    $messages[] = ['role'=>'system','content'=>
      'KULLANICI ONAY VERDI. Bir onceki cevabinda teklif ettigin islemi SIMDI araci cagirarak GERCEKTEN yap: '
      . 'medya/albüm icin send_whatsapp_media (phone + album veya media_ids), metin icin send_whatsapp, e-posta icin send_email. '
      . 'Bu turda ONCE araci cagir; arac cagirmadan "gonderildi/yapildi" YAZMA. Teklif ettigin islem: '
      . mb_substr($lastA, 0, 400)];
  }

  /* Viktor 2026-08-30: "bunu ... ekle" gibi isaret zamirli komutlarda model eski konuyu
     tekrar kullaniyordu; son gorseli/mesaji acikca baglayalim. */
  if (preg_match('/\b(bunu|bunlar[ıi]|[şs]unu|bu\s+g[öo]rseli|bu\s+mesaj[ıi]|bu\s+fotoğraf[ıi])\b/iu', $ut)) {
    $lastUser = '';
    for ($h = count($hist) - 1; $h >= 0; $h--) {
      if (($hist[$h]['role'] ?? '') === 'user' && is_string($hist[$h]['content'] ?? null)
          && trim($hist[$h]['content']) !== '' && trim($hist[$h]['content']) !== $userText) {
        $lastUser = $hist[$h]['content']; break;
      }
    }
    $lastAsst = '';
    for ($h = count($hist) - 1; $h >= 0; $h--) {
      if (($hist[$h]['role'] ?? '') === 'assistant' && is_string($hist[$h]['content'] ?? null)) { $lastAsst = $hist[$h]['content']; break; }
    }
    $messages[] = ['role'=>'system','content'=>
      'REFERANS KILIDI: Kullanicinin "bunu/sunu" dedigi sey EN SON konusulan icerik. '
      . 'Son kullanici mesaji: ' . mb_substr($lastUser, 0, 700) . ' || Senin son cevabin: ' . mb_substr($lastAsst, 0, 700) . '. '
      . 'Gorevi/hatirlaticiyi SADECE bu icerige gore olustur. Daha ESKI turlardaki baska bir konuyu (onceki gorev basligini) '
      . 'tekrar kullanma ve onceki cevabini kopyalama. Icerik belirsizse tek bir soruyla sor.'];
  }

  $tools = lh_b(atlas_tools());
  $used = [];
  /* Viktor 2026-08-30 (7): $used icindeki 'result' 500 karakterde kesiliyor; dogrulama
     bunun uzerinden yapilinca GERCEK arac ciktisindaki numaralar "uydurma" sanilip
     maskeleniyordu. Tam ciktiyi ayri bir blob'da tutuyoruz. */
  $toolBlob = '';
  $nudgeCount = 0;
  $nudged = false;
  $findNudged = false;
  $phoneNudged = false;
  $mailNudged = false;
  $reNudged = false;
  $metaNudged = 0;
  $actNudged = 0;
  for ($i = 0; $i < 6; $i++) {
    $r = llm_chat($messages, $tools, (int)$userId);
    if (isset($r['error'])) return ['reply'=>'⚠️ ' . $r['error'], 'tools'=>$used];
    $msg = $r['choices'][0]['message'] ?? null;
    if (!$msg) return ['reply'=>'⚠️ Model yanıt vermedi.', 'tools'=>$used];
    $messages[] = $msg;
    if (!empty($msg['tool_calls'])) {
      foreach ($msg['tool_calls'] as $tc) {
        $fn = $tc['function']['name'];
        $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
        $res = lh_b(run_tool($fn, $args));
        $used[] = ['tool'=>$fn, 'args'=>$args, 'result'=>mb_substr($res,0,500)];
        $toolBlob .= ' ' . $res;
        $messages[] = ['role'=>'tool','tool_call_id'=>$tc['id'],'content'=>$res];
      }
      continue;
    }
    $reply = $msg['content'] ?? '';
    // GUVENLIK AGI: model islem yaptigini soyluyor ama hicbir arac cagirmadiysa bir kez zorla
    if ($nudgeCount < 3 && !atlas_used_action_tool($used) && atlas_claims_action($reply)) {
      $nudgeCount++;
      $nudged = true;
      $extra = $verified !== '' ? ' Kullanabilecegin DOGRULANMIS rehber kayitlari: ' . $verified . '.' : '';
      $messages[] = ['role'=>'system','content'=>
        'UYARI: Bu turda hiçbir araç çağırmadın ama kullanıcıya bir işlem yaptığını söyledin. '
        . 'Bu yanlış. Gerekli aracı (send_whatsapp / send_whatsapp_media / send_email / get_call_number / add_contact / save_to_phone_contacts / send_contact_card / export_contacts_vcf / create_reminder / tasks_add) '
        . 'ŞİMDİ çağır — metin yazma, SADECE araç çağrısı yap.' . $extra
        . ' Albüm/medya gönderimi icin send_whatsapp_media (phone + album veya media_ids) kullan. '
        . 'Bilgi gercekten eksikse araç çağırmak yerine kullanıcıya sor.'];
      continue;
    }
    /* GUVENLIK AGI 4 (Viktor 2026-08-30): arac ciktisinda olmayan E-POSTA adresi. */
    $badMails = atlas_unbacked_emails($reply, $used, $hintBlob . ' ' . $verified . ' ' . $toolBlob);
    if (!$mailNudged && $badMails) {
      $mailNudged = true;
      $guess = atlas_name_guess($userText);
      $cands = $guess !== '' ? atlas_contact_search($guess, 5) : [];
      $messages[] = ['role'=>'system','content'=>
        'UYARI: Cevabindaki su e-posta adres(ler)i hicbir arac sonucundan gelmiyor, UYDURMA: ' . implode(', ', $badMails) . '. '
        . 'E-posta adresi SADECE find_contact / list_contacts / gmail araclarinin dondurdugu veriden ya da kullanicinin kendi yazdigi metinden alinabilir. '
        . 'Rehberdeki gercek adaylar: ' . ($cands ? json_encode($cands, JSON_UNESCAPED_UNICODE) : '(yok)') . '. '
        . 'Adresi bilmiyorsan uydurmak yerine kullaniciya sor.'];
      continue;
    }
    if ($badMails) {
      $reply = str_replace($badMails, '(adres doğrulanamadı)', $reply);
    }
    /* GUVENLIK AGI 5 (Viktor 2026-08-30): emlak portfoy/musteri verisi arac cagirmadan yazilmis. */
    if (!$reNudged && function_exists('re_enabled') && re_enabled() && !atlas_used_re_read_tool($used) && atlas_claims_re_data($reply)) {
      $reNudged = true;
      $messages[] = ['role'=>'system','content'=>
        'UYARI: Portfoy/musteri (emlak) verisi iceren bir cevap yaziyorsun ama bu turda hicbir kayit okuma araci cagirmadin. '
        . 'Ilan/musteri bilgileri (fiyat, metrekare, oda sayisi, adres, durum) SADECE re_* araclarinin dondurdugu veriden ya da '
        . 'kullanicinin bu konusmada yazdigi bilgiden alinabilir. SIMDI ilgili re_* aracini cagir; kayit yoksa acikca "kayitli ilan/musteri bulunmuyor" de. '
        . 'Kayit uydurma.'];
      continue;
    }
    /* GUVENLIK AGI 3 (Viktor 2026-08-30): cevapta arac ciktisinda OLMAYAN telefon numarasi varsa
       model uyduruyor demektir; rehberden gercek kayitlari verip tekrar denetiyoruz. */
    $badPhones = atlas_unbacked_phones($reply, $used, $hintBlob . ' ' . $verified . ' ' . $toolBlob);
    if (!$phoneNudged && $badPhones) {
      $phoneNudged = true;
      $guess = atlas_name_guess($userText);
      $cands = $guess !== '' ? atlas_contact_search($guess, 8) : [];
      $info  = $cands ? json_encode($cands, JSON_UNESCAPED_UNICODE) : '(yok)';
      $messages[] = ['role'=>'system','content'=>
        'UYARI: Cevabinda gecen su numaralar hicbir arac sonucundan gelmiyor, UYDURMA: ' . implode(', ', $badPhones) . '. '
        . 'Telefon numarasi SADECE find_contact / list_contacts araclarinin dondurdugu veriden alinabilir. '
        . 'Rehberde "' . $guess . '" icin gercek adaylar: ' . $info . '. '
        . 'SIMDI dogru araci cagir (kisi listesi icin list_contacts, tek kisi icin find_contact) ve cevabi SADECE arac sonucuna gore yeniden yaz.'];
      continue;
    }
    if ($badPhones) {
      /* Maskeleme URL ve dosya adlarina dokunmaz (Viktor 2026-09-09). */
      $reply = (string)preg_replace_callback(
        '#(https?://\S+|\S+\.(?:csv|pdf|xlsx|vcf|jpg|jpeg|png|zip)\b)|(\d[\d\s\-\(\)]{8,}\d)#iu',
        fn(array $m) => ($m[1] ?? '') !== '' ? $m[1] : '(numara doğrulanamadı)',
        $reply);
    }
    /* GUVENLIK AGI 2 (Viktor 2026-08-30): "rehberde bulamadim" dedi ama find_contact cagirmadi.
       Rehberde gercek adaylari kendimiz arayip modele veriyoruz ve tekrar denetiyoruz. */
    if (!$findNudged && !atlas_used_lookup_tool($used) && atlas_claims_not_found($reply)) {
      $findNudged = true;
      $guess = atlas_name_guess($userText);
      $cands = $guess !== '' ? atlas_contact_search($guess, 8) : [];
      $info  = $cands ? json_encode($cands, JSON_UNESCAPED_UNICODE) : '(bu isimle eslesen kayit yok)';
      $messages[] = ['role'=>'system','content'=>
        'UYARI: find_contact aracini CAGIRMADAN kullaniciya kisinin rehberde bulunmadigini soyledin. '
        . 'Bu yanlis olabilir; rehber binlerce kisi iceriyor. Rehberde "' . $guess . '" icin bulunan gercek adaylar: ' . $info . '. '
        . 'SIMDI find_contact aracini dogru isimle cagir ve sonuca gore cevap ver. Arac sonucu olmadan "bulamadim" DEME.'];
      continue;
    }
    /* Nudge'a ragmen arac cagrilmadiysa kullaniciya YANLIS basari bildirimi gitmesin. */
    if (!atlas_used_action_tool($used) && atlas_claims_action($reply)) {
      /* Once modele bir sans daha ver: yanlis pozitif olabilir (or. "bugun ne var" gibi
         bilgi sorularinda). Ancak 2. kez de olursa uyari mesajini yaz. (Viktor 2026-08-30) */
      if ($actNudged < 1) {
        $actNudged++;
        $messages[] = ['role'=>'system','content'=>
          'UYARI: Bir islem yaptigini soyluyorsun ama gerekli araci CAGIRMADIN. '
          . 'Eger gercekten bir islem gerekiyorsa SIMDI dogru araci cagir. '
          . 'Kullanici sadece bilgi sorduysa (or. gununde ne var, liste, aciklama) araca gerek yok; '
          . 'o zaman islem yaptigini ima etmeden dogrudan cevabi yaz.'];
        continue;
      }
      // Mesaji istege gore yaz: kayit/hatirlatici isteginde "mesaj GITMEDI" demek yaniltici. (Viktor 2026-08-30)
      $lc = mb_strtolower($userText, 'UTF-8');
      if (preg_match('/(rehber|kaydet|kayıt|ekle|contact|save)/u', $lc)) {
        $reply = "\u{26A0}\u{FE0F} Kayd\u{131} yapamad\u{131}m \u{2014} teknik bir hata olu\u{15F}tu ve ki\u{15F}i KAYDED\u{130}LMED\u{130}. Tekrar denememi ister misin?";
      } elseif (preg_match('/(hatırlat|görev|takvim|remind|task)/u', $lc)) {
        $reply = "\u{26A0}\u{FE0F} \u{130}\u{15F}lemi tamamlayamad\u{131}m \u{2014} teknik bir hata olu\u{15F}tu ve kay\u{131}t OLU\u{15E}TURULMADI. Tekrar denememi ister misin?";
      } else {
        if (preg_match('/(mesaj|gönder|yaz(ar mısın)?|whatsapp|sms)/u', $lc)) {
          $reply = "\u{26A0}\u{FE0F} \u{130}\u{15F}lemi ger\u{E7}ekle\u{15F}tiremedim \u{2014} teknik bir hata olu\u{15F}tu, mesaj G\u{130}TMED\u{130}. Tekrar denememi ister misin?";
        } else {
          $reply = "\u{26A0}\u{FE0F} \u{130}\u{15F}lemi tamamlayamad\u{131}m \u{2014} teknik bir hata olu\u{15F}tu. Tekrar denememi ister misin?";
        }
      }
    }
    /* GUVENLIK AGI 6 (Viktor 2026-08-30): ic muhakeme (chain-of-thought) kullaniciya gitmesin. */
    // Cevap meta ise VEYA meta temizligi sonrasi geriye sadece "baska bir sey?" gibi
    // icerksiz bir kalip kaliyorsa yeniden yazdir. (Viktor 2026-08-30)
    $hollow = preg_match('/^\s*(ba[şs]ka bir [şs]ey|bir [şs]ey daha|yard[ıi]mc[ıi] olabilece[ğg]im)/iu', trim($reply))
              && mb_strlen(trim($reply), 'UTF-8') < 60;
    if (atlas_is_meta_reply($reply) || $hollow) {
      if ($metaNudged < 2) {
        $metaNudged++;
        $messages[] = ['role'=>'system','content'=>
          'UYARI: Son cevabin ya ic muhakeme metni ya da icerksiz ("baska bir sey ister misin?" gibi bos kalip). '
          . 'Kullanicinin SON sorusunu gercekten CEVAPLA; gerekiyorsa aracla dogrula. Ic muhakeme ornegi: ("kullanici sunu dedi", '
          . '"onay bekliyorum" vb.). Bu kullaniciya GONDERILEMEZ. Simdi cevabi kullaniciya DOGRUDAN hitap ederek, '
          . 'kisa ve net yeniden yaz. Onay gerekiyorsa SADECE tek bir soru sor (or. "Su 3 gorevi silmemi onayliyor musun?"). '
          . 'Ic muhakemeni yazma.'];
        continue;
      }
      $clean = atlas_strip_meta($reply);
      $reply = $clean !== '' ? $clean
        : 'Devam etmeden önce onayına ihtiyacım var — bu işlemi yapmamı istiyor musun? (evet/hayır)';
    }
    return ['reply'=>$reply, 'tools'=>$used];
  }
  return ['reply'=>'İşlem çok uzun sürdü, tekrar dener misin?', 'tools'=>$used];
}
