<?php
/**
 * LUNAHAN — panel sohbetinden dosya yükleme ucu (Viktor, 2026-09-09).
 * POST multipart: files[] (bir veya birden fazla), conv (opsiyonel)
 * Cevap: {"files":[{"id":..,"filename":..,"kind":..,"chars":..,"status":..}], "errors":[...]}
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/files.php';
header('Content-Type: application/json; charset=utf-8');

atlas_session();
$u = current_user();
if (!$u || $u['status'] !== 'active') { http_response_code(401); echo json_encode(['error' => 'oturum yok']); exit; }

$q = quota_check($u);
if (!$q['ok']) { echo json_encode(['error' => 'Paketiniz şu an dosya yüklemeye izin vermiyor.', 'code' => $q['err'], 'upgrade' => 'account.php'], JSON_UNESCAPED_UNICODE); exit; }

$conv = (int)($_POST['conv'] ?? 0);
if ($conv) {
  $chk = db()->prepare('SELECT 1 FROM conversations WHERE id=? AND user_id=?');
  $chk->execute([$conv, $u['id']]);
  if (!$chk->fetchColumn()) $conv = 0;
}

if (empty($_FILES['files']) || !is_array($_FILES['files']['name'])) {
  echo json_encode(['error' => 'dosya yok']); exit;
}

$ok = [];
$errors = [];
$n = min(10, count($_FILES['files']['name']));
for ($i = 0; $i < $n; $i++) {
  $f = [
    'name'     => $_FILES['files']['name'][$i],
    'type'     => $_FILES['files']['type'][$i],
    'tmp_name' => $_FILES['files']['tmp_name'][$i],
    'error'    => $_FILES['files']['error'][$i],
    'size'     => $_FILES['files']['size'][$i],
  ];
  $r = lh_store_upload($f, (int)$u['id'], $conv);
  if (isset($r['error'])) $errors[] = $f['name'] . ': ' . $r['error'];
  else $ok[] = $r;
}
echo json_encode(['files' => $ok, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
