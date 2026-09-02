<?php
/**
 * Lunify Atlas — kullanıcı hesapları, oturum, şifre sıfırlama, Google ile giriş, paket/abonelik.
 * Copyright (c) 2026 Lunify A.Ş. Tüm hakları saklıdır.
 */
require_once __DIR__ . '/db.php';

/* ---------- oturum ---------- */
function atlas_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'samesite'=>'Lax',
      'secure'=>(!empty($_SERVER['HTTPS']))]);
    session_start();
  }
}

/** Aktif kullanıcı kimliği. Arka plan işleri için atlas_set_ctx_user() ile de ayarlanabilir. */
function atlas_uid(): int {
  global $ATLAS_CTX_UID;
  if (!empty($ATLAS_CTX_UID)) return (int)$ATLAS_CTX_UID;
  atlas_session();
  return (int)($_SESSION['uid'] ?? 0);
}
function atlas_set_ctx_user(int $id): void { global $ATLAS_CTX_UID; $ATLAS_CTX_UID = $id; }

function current_user(): ?array {
  static $cache = [];
  $id = atlas_uid(); if (!$id) return null;
  if (isset($cache[$id])) return $cache[$id];
  $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]);
  $u = $s->fetch() ?: null;
  return $cache[$id] = $u;
}
function require_login(): array {
  $u = current_user();
  if (!$u || $u['status'] !== 'active') { header('Location: login.php'); exit; }
  atlas_enforce_trial($u);
  return $u;
}
function require_login_api(): array {
  $u = current_user();
  if (!$u || $u['status'] !== 'active') {
    http_response_code(401); header('Content-Type: application/json');
    echo json_encode(['error'=>'unauthorized']); exit;
  }
  return $u;
}
function require_admin(): array {
  $u = require_login();
  if (empty($u['is_admin'])) { http_response_code(403); exit('403'); }
  return $u;
}
function auth_start_session(array $u): void {
  atlas_session();
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$u['id']]);
}

/* ---------- kayıt / giriş ---------- */
function auth_find_by_email(string $email): ?array {
  $s = db()->prepare('SELECT * FROM users WHERE email=?'); $s->execute([mb_strtolower(trim($email))]);
  return $s->fetch() ?: null;
}
function auth_register(string $email, string $pass, string $name = '', string $lang = 'tr'): array {
  $email = mb_strtolower(trim($email));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'err'=>'email'];
  if (mb_strlen($pass) < 8) return ['ok'=>false,'err'=>'weak'];
  if (auth_find_by_email($email)) return ['ok'=>false,'err'=>'exists'];
  $trial = date('Y-m-d H:i:s', strtotime('+90 days'));
  $s = db()->prepare('INSERT INTO users(email,pass_hash,name,plan_code,plan_status,plan_until,lang)
                      VALUES(?,?,?,\'trial\',\'trial\',?,?)');
  $s->execute([$email, password_hash($pass, PASSWORD_DEFAULT), $name ?: null, $trial, $lang]);
  $id = (int)db()->lastInsertId();
  db()->prepare('INSERT INTO subscription_log(user_id,plan_code,action,note) VALUES(?,\'trial\',\'signup\',?)')
      ->execute([$id, 'kayıt']);
  $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]);
  return ['ok'=>true,'user'=>$s->fetch()];
}
function auth_login(string $email, string $pass): array {
  $u = auth_find_by_email($email);
  if (!$u || !$u['pass_hash'] || !password_verify($pass, $u['pass_hash'])) return ['ok'=>false,'err'=>'bad'];
  if ($u['status'] !== 'active') return ['ok'=>false,'err'=>'suspended'];
  auth_start_session($u);
  return ['ok'=>true,'user'=>$u];
}

/* ---------- şifre sıfırlama ---------- */
function auth_reset_create(array $u): string {
  $token = bin2hex(random_bytes(32));
  db()->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,?)')
     ->execute([$u['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + 3600)]);
  return $token;
}
function auth_reset_lookup(string $token): ?array {
  $s = db()->prepare('SELECT r.*, u.email FROM password_resets r JOIN users u ON u.id=r.user_id
                      WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at > NOW()');
  $s->execute([hash('sha256', $token)]);
  return $s->fetch() ?: null;
}
function auth_reset_apply(array $row, string $newPass): bool {
  if (mb_strlen($newPass) < 8) return false;
  db()->prepare('UPDATE users SET pass_hash=? WHERE id=?')
     ->execute([password_hash($newPass, PASSWORD_DEFAULT), $row['user_id']]);
  db()->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
  return true;
}

/* ---------- e-posta (SMTP) ---------- */
function atlas_send_mail(string $to, string $subject, string $html): array {
  $host = SMTP_HOST; $port = SMTP_PORT; $user = SMTP_USER; $pass = SMTP_PASS;
  if (!$host || !$user) return ['ok'=>false,'err'=>'smtp yapılandırılmadı'];
  $ctx = stream_context_create(['ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
  $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $en, $es, 20, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) return ['ok'=>false,'err'=>"bağlanamadı: $es"];
  stream_set_timeout($fp, 20);
  $read = function() use ($fp) { $o=''; while (($l = fgets($fp, 1024)) !== false) { $o .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return $o; };
  $cmd  = function($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };
  $read();
  $cmd('EHLO ' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'atlas'));
  $cmd('AUTH LOGIN'); $cmd(base64_encode($user)); $r = $cmd(base64_encode($pass));
  if (strpos($r, '235') !== 0) { fclose($fp); return ['ok'=>false,'err'=>'kimlik doğrulama: ' . trim($r)]; }
  $cmd('MAIL FROM:<' . SMTP_FROM . '>'); $r = $cmd('RCPT TO:<' . $to . '>');
  if ($r === '' || $r[0] !== '2') { fclose($fp); return ['ok'=>false,'err'=>'alıcı reddedildi: ' . trim($r)]; }
  $cmd('DATA');
  $headers = 'From: ' . SMTP_FROM_NAME . ' <' . SMTP_FROM . ">\r\n"
    . 'To: <' . $to . ">\r\n"
    . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
    . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n"
    . "Content-Transfer-Encoding: base64\r\n\r\n";
  $body = chunk_split(base64_encode($html));
  $r = $cmd($headers . $body . "\r\n.");
  $cmd('QUIT'); fclose($fp);
  if ($r === '' || $r[0] !== '2') return ['ok'=>false,'err'=>'gönderilemedi: ' . trim($r)];
  return ['ok'=>true];
}

/* ---------- Google ile giriş ---------- */
function google_oauth_ready(): bool { return gsetting('google_client_id','') !== '' && gsetting('google_client_secret','') !== ''; }
function google_redirect_uri(): string { return rtrim(APP_URL, '/') . '/auth-google.php'; }
function google_auth_url(string $state): string {
  return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => gsetting('google_client_id',''),
    'redirect_uri' => google_redirect_uri(),
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'prompt' => 'select_account',
  ]);
}
function google_exchange(string $code): ?array {
  $ch = curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>25,
    CURLOPT_POSTFIELDS=>http_build_query([
      'code'=>$code, 'client_id'=>gsetting('google_client_id',''),
      'client_secret'=>gsetting('google_client_secret',''),
      'redirect_uri'=>google_redirect_uri(), 'grant_type'=>'authorization_code'])]);
  $r = curl_exec($ch); curl_close($ch);
  $j = json_decode((string)$r, true);
  if (empty($j['id_token'])) return null;
  $parts = explode('.', $j['id_token']);
  if (count($parts) < 2) return null;
  $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
  if (empty($payload['email'])) return null;
  return $payload;
}
/** Google profiliyle giriş yap; hesap yoksa oluştur. */
function google_login(array $p): array {
  $email = mb_strtolower($p['email']);
  $u = auth_find_by_email($email);
  if (!$u) {
    $s = db()->prepare('SELECT * FROM users WHERE google_sub=?'); $s->execute([$p['sub'] ?? '']);
    $u = $s->fetch() ?: null;
  }
  if (!$u) {
    $trial = date('Y-m-d H:i:s', strtotime('+90 days'));
    db()->prepare('INSERT INTO users(email,name,google_sub,avatar,plan_code,plan_status,plan_until)
                   VALUES(?,?,?,?,\'trial\',\'trial\',?)')
       ->execute([$email, $p['name'] ?? null, $p['sub'] ?? null, $p['picture'] ?? null, $trial]);
    $id = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO subscription_log(user_id,plan_code,action,note) VALUES(?,\'trial\',\'signup\',\'google\')')->execute([$id]);
    $s = db()->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$id]); $u = $s->fetch();
  } else {
    db()->prepare('UPDATE users SET google_sub=COALESCE(google_sub,?), avatar=COALESCE(avatar,?) WHERE id=?')
       ->execute([$p['sub'] ?? null, $p['picture'] ?? null, $u['id']]);
  }
  if ($u['status'] !== 'active') return ['ok'=>false,'err'=>'suspended'];
  auth_start_session($u);
  return ['ok'=>true,'user'=>$u];
}

/* ---------- paket / abonelik ---------- */
function plan_row(string $code): array {
  static $c = [];
  if (isset($c[$code])) return $c[$code];
  $s = db()->prepare('SELECT * FROM plans WHERE code=?'); $s->execute([$code]);
  $r = $s->fetch();
  if (!$r) { $s = db()->prepare('SELECT * FROM plans WHERE code=\'trial\''); $s->execute(); $r = $s->fetch(); }
  return $c[$code] = $r;
}
function all_plans(): array { return db()->query('SELECT * FROM plans ORDER BY sort_order')->fetchAll(); }

function sub_expired(array $u): bool {
  return !empty($u['plan_until']) && strtotime($u['plan_until']) < time();
}
function usage_month(int $uid): int {
  $s = db()->prepare('SELECT COUNT(*) c FROM commands WHERE user_id=? AND created_at >= ?');
  $s->execute([$uid, date('Y-m-01 00:00:00')]);
  return (int)$s->fetch()['c'];
}
/** Komut çalıştırılabilir mi? ['ok'=>bool,'err'=>kod] */
function quota_check(array $u): array {
  if ($u['status'] !== 'active') return ['ok'=>false,'err'=>'suspended'];
  if (in_array($u['plan_status'], ['canceled','past_due'], true)) return ['ok'=>false,'err'=>'inactive'];
  if (sub_expired($u)) return ['ok'=>false,'err'=>'expired'];
  $p = plan_row($u['plan_code']);
  if ((int)$p['monthly_commands'] >= 0 && usage_month((int)$u['id']) >= (int)$p['monthly_commands'])
    return ['ok'=>false,'err'=>'quota'];
  return ['ok'=>true,'err'=>''];
}
function plan_allows(array $u, string $feature): bool {
  $p = plan_row($u['plan_code']);
  return !empty($p[$feature . '_enabled']);
}
function set_plan(int $uid, string $code, ?string $until, string $status = 'active', string $note = ''): void {
  db()->prepare('UPDATE users SET plan_code=?, plan_status=?, plan_until=? WHERE id=?')
     ->execute([$code, $status, $until, $uid]);
  db()->prepare('INSERT INTO subscription_log(user_id,plan_code,action,note) VALUES(?,?,?,?)')
     ->execute([$uid, $code, 'set_plan', $note]);
}

/* --- 3 aylik ucretsiz deneme bitince otomatik kilit --- */

function atlas_trial_lock_page(string $lang, string $upgradeUrl): void {
    $tr = ($lang === 'tr');
    $t = $tr ? 'Ücretsiz deneme süreniz doldu' : 'Your free trial has ended';
    $p = $tr
      ? '3 aylık ücretsiz deneme süreniz sona erdi. Hesabınız ve verileriniz güvende — devam etmek için bir plan seçmeniz yeterli.'
      : 'Your 3-month free trial has ended. Your account and data are safe — choose a plan to continue.';
    $b = $tr ? 'Plan seç / bize yaz' : 'Choose a plan / contact us';
    $o = $tr ? 'Çıkış yap' : 'Log out';
    http_response_code(402);
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . $t . '</title><style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#faf7f0;font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;color:#1c1a17;padding:24px}'
       . '.c{max-width:520px;background:#fff;border:1px solid #eadfc6;border-radius:20px;padding:34px;box-shadow:0 18px 50px rgba(0,0,0,.07);text-align:center}'
       . 'h1{font-size:22px;margin:0 0 12px}p{font-size:15px;line-height:1.6;color:#4a453d;margin:0 0 22px}'
       . 'a.btn{display:inline-block;background:#c9a227;color:#fff;text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:600}'
       . 'a.alt{display:block;margin-top:14px;color:#8a8378;font-size:13px;text-decoration:none}</style></head><body><div class="c">'
       . '<h1>' . $t . '</h1><p>' . $p . '</p>'
       . '<a class="btn" href="' . htmlspecialchars($upgradeUrl) . '">' . $b . '</a>'
       . '<a class="alt" href="login.php?logout=1">' . $o . '</a></div></body></html>';
    exit;
}

function atlas_enforce_trial(array $u): void {
  if (!empty($u['is_admin'])) return;
  if (!sub_expired($u)) return;
  $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
  if (in_array($self, ['account.php','login.php','reset.php','index.php','guide.php','gmail-callback.php','auth-google.php'], true)) return;
  atlas_trial_lock_page($u['lang'] ?? 'tr', 'account.php');
}
