<?php
/**
 * Lunify Lunahan — uploads/ icin nginx auth_request ucu (Viktor, 2026-09-10)
 *
 * Neden: uploads/ altindaki musteri dosyalari (PDF, sozlesme, kisi ekleri) kimlik
 * dogrulamasi olmadan servis ediliyordu. Dosya adini bilen herkes indirebiliyordu.
 *
 * Nasil: nginx bu ucu "auth_request" ile cagirir. 204 -> dosya verilir, 401 -> 403 doner.
 * Kasitli olarak HAFIF: DB'ye dokunmaz, uygulama include'u yuklemez; sadece oturum
 * dosyasini okur. Boylece her dosya istegine agir bir bootstrap binmez.
 */

// Oturum ayarlari uygulamanin kendi ayariyla BIREBIR ayni olmali, yoksa oturum bulunamaz.
@ini_set('session.use_strict_mode', '1');
@ini_set('session.save_path', '/var/lib/php/lunahan_sessions');
session_name('PHPSESSID');
if (isset($_COOKIE['PHPSESSID'])) {
    @session_start();
}

$ok = !empty($_SESSION['uid']);
if ($ok) {
    http_response_code(204);           // nginx: dosyayi ver
} else {
    header('Cache-Control: no-store');
    http_response_code(401);           // nginx: 403'e cevir
}
