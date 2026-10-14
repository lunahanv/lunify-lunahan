<?php
// CSP ihlal raporu toplayıcı (Viktor, 2026-09-15). Sadece log yazar.
http_response_code(204);
$f = '/opt/lunify/lunahan/includes/csp-reports.log';
if (file_exists($f) && filesize($f) > 2097152) exit;
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 16384) exit;
$line = date('c') . " " . preg_replace('/\s+/', ' ', $raw) . "\n";
file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
