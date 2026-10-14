<?php
/**
 * LUNAHAN — panel içi dosya yükleme + içerik çıkarma (Viktor, 2026-09-09).
 * WhatsApp'a bağımlı değil: kullanıcı sohbete PDF/görsel/ses/metin yükler,
 * içerik metne çevrilir ve ajanın o turdaki bağlamına girer.
 * Yardımcı servis: 127.0.0.1:3010 (/pdf /ocr /vision /stt), X-Stt-Key ile.
 */

if (!defined('LH_STT_URL')) {
  $lhSttUrlEnv = getenv('LH_STT_URL');
  define('LH_STT_URL', is_string($lhSttUrlEnv) ? rtrim(trim($lhSttUrlEnv), '/') : '');
  unset($lhSttUrlEnv);
}
if (!defined('LH_STT_KEY')) {
  $lhSttEnvKey = getenv('LH_STT_KEY');
  define('LH_STT_KEY', is_string($lhSttEnvKey) ? trim($lhSttEnvKey) : '');
  unset($lhSttEnvKey);
}
if (!defined('LH_MAX_BYTES')) define('LH_MAX_BYTES', 32 * 1024 * 1024);

function lh_files_schema(): void {
  static $done = false;
  if ($done) return;
  $done = true;
  db()->exec("CREATE TABLE IF NOT EXISTS lh_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    conv_id INT NOT NULL DEFAULT 0,
    kind VARCHAR(20) NOT NULL DEFAULT 'file',
    mime VARCHAR(120) NOT NULL DEFAULT '',
    filename VARCHAR(255) NOT NULL DEFAULT '',
    path VARCHAR(255) NOT NULL DEFAULT '',
    bytes INT NOT NULL DEFAULT 0,
    extracted_text MEDIUMTEXT NULL,
    extract_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY k_user (user_id, id), KEY k_conv (conv_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function lh_kind_of(string $mime, string $name): string {
  $mime = strtolower($mime);
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (str_starts_with($mime, 'image/')) return 'image';
  if ($mime === 'application/pdf' || $ext === 'pdf') return 'pdf';
  if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3','m4a','ogg','opus','wav','oga'], true)) return 'audio';
  if (str_starts_with($mime, 'video/')) return 'video';
  if (str_starts_with($mime, 'text/') || in_array($ext, ['txt','csv','md','json','log'], true)) return 'text';
  return 'file';
}

/** Yardımcı servise dosya gönderip metin alır. */
function lh_stt_call(string $endpoint, string $path, string $filename, array $extra = []): string {
  if (!is_readable($path)) return '';
  if (!defined('LH_STT_URL') || trim((string)LH_STT_URL) === '' || !defined('LH_STT_KEY') || trim((string)LH_STT_KEY) === '') return '';
  /* DIKKAT: yardimci servis her uc icin FARKLI alan adi bekliyor
     (/pdf => file, /ocr ve /vision => image, /stt => audio). Yanlis ad = sessiz bos sonuc. */
  $field = ['pdf' => 'file', 'ocr' => 'image', 'vision' => 'image', 'stt' => 'audio'][trim($endpoint, '/')] ?? 'file';
  $ch = curl_init(rtrim(LH_STT_URL, '/') . '/' . ltrim($endpoint, '/'));
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['X-Stt-Key: ' . LH_STT_KEY],
    CURLOPT_POSTFIELDS     => array_merge($extra, [$field => new CURLFile($path, 'application/octet-stream', $filename)]),
  ]);
  $r = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code !== 200) return '';
  $j = json_decode((string)$r, true);
  if (!is_array($j)) return '';
  foreach (['text', 'result', 'content', 'description'] as $k) {
    if (!empty($j[$k]) && is_string($j[$k])) return trim($j[$k]);
  }
  return '';
}

/** Dosya içeriğini metne çevirir. Dönüş: [metin, durum] */
function lh_extract(string $path, string $mime, string $name, string $kind): array {
  switch ($kind) {
    case 'pdf':
      $t = lh_stt_call('pdf', $path, $name);
      if ($t === '') $t = lh_stt_call('ocr', $path, $name);   // taranmis PDF
      break;
    case 'image':
      /* Once OCR (gercek yazi, guvenilir); yazi bolsa gorsel-anlama modelini hic cagirmiyoruz.
         Sebep: metin agirlikli goruntude vision modeli uyduruyor (Viktor 2026-09-09 testi). */
      $ocr = lh_stt_call('ocr', $path, $name);
      $t = $ocr !== '' ? "GÖRSELDEKİ YAZI (OCR, güvenilir): " . $ocr : '';
      if (mb_strlen($ocr, 'UTF-8') < 40) {
        $vis = lh_stt_call('vision', $path, $name);
        if ($vis !== '') $t = trim($t . "\nGÖRSEL YORUMU (model tahmini, yazı okunamadı — kesin bilgi sayma): " . $vis);
      }
      break;
    case 'audio':
      /* Dil verilmezse Whisper yanlis dil secip sacma cikti uretiyor (Viktor 2026-09-09). */
      $t = lh_stt_call('stt', $path, $name, ['lang' => function_exists('atlas_lang') ? atlas_lang() : 'tr']);
      if ($t !== '') $t = "SES KAYDI ÇÖZÜMÜ: " . $t;
      break;
    case 'text':
      $t = (string)@file_get_contents($path, false, null, 0, 200000);
      break;
    default:
      $t = '';
  }
  $t = trim((string)$t);
  if ($t === '') return ['', 'empty'];
  if (mb_strlen($t, 'UTF-8') > 60000) $t = mb_substr($t, 0, 60000, 'UTF-8') . "\n…(kısaltıldı)";
  return [$t, 'ok'];
}

function lh_upload_dir(int $uid): string {
  $d = dirname(__DIR__) . '/uploads/lh/u' . $uid;
  if (!is_dir($d)) @mkdir($d, 0775, true);
  return $d;
}

/**
 * $_FILES girdisini kaydeder ve içeriğini çıkarır.
 * Dönüş: ['id'=>int,'filename'=>..,'kind'=>..,'chars'=>int,'status'=>..] veya ['error'=>..]
 */
function lh_store_upload(array $f, int $uid, int $conv = 0): array {
  lh_files_schema();
  if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) return ['error' => 'Dosya yüklenemedi (hata kodu ' . (int)($f['error'] ?? -1) . ').'];
  $bytes = (int)($f['size'] ?? 0);
  if ($bytes <= 0)             return ['error' => 'Dosya boş.'];
  if ($bytes > LH_MAX_BYTES)   return ['error' => 'Dosya çok büyük (en fazla 32 MB).'];

  $name = preg_replace('/[\x00-\x1F\/\\\\]+/u', '_', (string)($f['name'] ?? 'dosya'));
  $name = mb_substr($name, 0, 180, 'UTF-8');
  $mime = (string)($f['type'] ?? '');
  if (function_exists('mime_content_type')) {
    $det = @mime_content_type($f['tmp_name']);
    if (is_string($det) && $det !== '') $mime = $det;
  }
  $kind = lh_kind_of($mime, $name);

  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $safe = bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . preg_replace('/[^a-z0-9]+/', '', $ext) : '');
  $dest = lh_upload_dir($uid) . '/' . $safe;
  if (!@move_uploaded_file($f['tmp_name'], $dest) && !@rename($f['tmp_name'], $dest)) {
    return ['error' => 'Dosya sunucuya yazılamadı.'];
  }
  @chmod($dest, 0664);

  [$text, $status] = lh_extract($dest, $mime, $name, $kind);

  $ins = db()->prepare('INSERT INTO lh_files(user_id,conv_id,kind,mime,filename,path,bytes,extracted_text,extract_status)
                        VALUES(?,?,?,?,?,?,?,?,?)');
  $ins->execute([$uid, $conv, $kind, $mime, $name, $dest, $bytes, $text !== '' ? $text : null, $status]);
  $id = (int)db()->lastInsertId();

  return ['id' => $id, 'filename' => $name, 'kind' => $kind, 'bytes' => $bytes,
          'chars' => mb_strlen($text, 'UTF-8'), 'status' => $status];
}

function lh_file_row(int $id, int $uid): ?array {
  lh_files_schema();
  $q = db()->prepare('SELECT * FROM lh_files WHERE id=? AND user_id=?');
  $q->execute([$id, $uid]);
  $r = $q->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

function lh_kind_label(string $k): string {
  return ['pdf'=>'PDF', 'image'=>'görsel', 'audio'=>'ses kaydı', 'text'=>'metin dosyası', 'video'=>'video'][$k] ?? 'dosya';
}

/** Bu turda yüklenen dosyaların içeriğini ajana verilecek metin bloğu haline getirir. */
function lh_context_block(array $ids, int $uid): string {
  $out = [];
  foreach ($ids as $id) {
    $r = lh_file_row((int)$id, $uid);
    if (!$r) continue;
    $head = '[YÜKLENEN DOSYA #' . $r['id'] . ' — ' . $r['filename'] . ' (' . lh_kind_label($r['kind']) . ')]';
    $txt  = trim((string)($r['extracted_text'] ?? ''));
    $out[] = $txt !== ''
      ? $head . "\n" . $txt
      : $head . "\n(İçerik okunamadı. Kullanıcıya dosyanın okunamadığını söyle, uydurma.)";
  }
  return $out ? implode("\n\n", $out) : '';
}

/* ---------- Ajan araçları ---------- */

function lh_tool_list_uploads(array $a): string {
  lh_files_schema();
  $uid = atlas_uid();
  $lim = max(1, min(30, (int)($a['limit'] ?? 10)));
  $sql = 'SELECT id,filename,kind,bytes,extract_status,created_at FROM lh_files WHERE user_id=?';
  $p = [$uid];
  if (!empty($a['kind']))  { $sql .= ' AND kind=?';           $p[] = (string)$a['kind']; }
  if (!empty($a['query'])) { $sql .= ' AND filename LIKE ?';  $p[] = '%' . $a['query'] . '%'; }
  $sql .= ' ORDER BY id DESC LIMIT ' . $lim;
  $q = db()->prepare($sql); $q->execute($p);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  if (!$rows) return 'Yüklenmiş dosya yok.';
  $out = [];
  foreach ($rows as $r) {
    $out[] = '#' . $r['id'] . ' ' . $r['filename'] . ' (' . lh_kind_label($r['kind']) . ', '
           . round($r['bytes'] / 1024) . ' KB, ' . $r['created_at']
           . ($r['extract_status'] === 'ok' ? '' : ', içerik okunamadı') . ')';
  }
  return implode("\n", $out);
}

function lh_tool_read_upload(array $a): string {
  $uid = atlas_uid();
  $id  = (int)($a['id'] ?? 0);
  if ($id <= 0) return 'Dosya id gerekli. Önce list_uploads çağır.';
  $r = lh_file_row($id, $uid);
  if (!$r) return 'Bu id ile senin bir dosyan yok (#' . $id . ').';
  $t = trim((string)($r['extracted_text'] ?? ''));
  if ($t === '') return $r['filename'] . ': içerik çıkarılamadı (' . $r['extract_status'] . '). Bilgi uydurma.';
  $max = max(500, min(40000, (int)($a['max_chars'] ?? 20000)));
  return $r['filename'] . ' (#' . $r['id'] . ', ' . lh_kind_label($r['kind']) . "):\n"
       . mb_substr($t, 0, $max, 'UTF-8');
}

if (function_exists('lh_register_tools')) {
  lh_register_tools([
    ['type'=>'function','function'=>['name'=>'list_uploads','description'=>'Kullanıcının bu uygulamaya (panel sohbetine) yüklediği dosyaları listeler: PDF, görsel, ses, metin. Dosya sormadan önce bunu çağır.','parameters'=>['type'=>'object','properties'=>['query'=>['type'=>'string','description'=>'Dosya adında geçen kelime'],'kind'=>['type'=>'string','description'=>'pdf | image | audio | text'],'limit'=>['type'=>'integer']],'required'=>[]]]],
    ['type'=>'function','function'=>['name'=>'read_upload','description'=>'Yüklenmiş bir dosyanın çıkarılmış metnini okur (PDF metni, görselin açıklaması+yazısı, ses kaydının çözümü). id için önce list_uploads kullan. İçerik yoksa uydurma.','parameters'=>['type'=>'object','properties'=>['id'=>['type'=>'integer'],'max_chars'=>['type'=>'integer']],'required'=>['id']]]],
  ], function (string $name, array $args) {
    if ($name === 'list_uploads') return lh_tool_list_uploads($args);
    if ($name === 'read_upload')  return lh_tool_read_upload($args);
    return null;
  }, 'lh_files_system_prompt');
}

/** Sistem promptuna eklenecek dosya yeteneği açıklaması (core bunu çağırır). */
function lh_files_system_prompt(string $lang = 'tr'): string {
  return $lang === 'en'
    ? " FILES (IMPORTANT): the user can upload PDFs, photos, audio and text files directly in this chat; their content is extracted to text. Files uploaded in this turn are given to you as [UPLOADED FILE #id]. For older files call list_uploads, then read_upload. NEVER say you cannot see or open files. If a file's content could not be extracted, say so honestly instead of guessing."
    : " DOSYALAR (ÇOK ÖNEMLİ): kullanıcı bu sohbete doğrudan PDF, fotoğraf, ses kaydı ve metin dosyası yükleyebilir; içerikleri metne çevrilip sana verilir. Bu turda yüklenen dosyalar mesajda [YÜKLENEN DOSYA #id] olarak görünür. Daha önce yüklenmiş dosyalar için list_uploads ile listele, sonra read_upload ile oku. 'Dosya göremiyorum / açamıyorum / WhatsApp'tan gönder' DEME — panelden yüklenen dosyayı okuyabilirsin. Bir dosyanın içeriği çıkarılamadıysa dürüstçe söyle, içerik uydurma.";
}
