<?php
/**
 * Lunify — Giris deneme sinirlayici (brute-force kilidi). Viktor, 2026-09-10
 *
 * Neden: giris formlarinda hiz siniri/kilit yoktu; saldirgan saniyede yuzlerce
 * sifre deneyebiliyordu. Pulse'ta uygulanan aynı desen tum uygulamalara tasindi.
 *
 * Kural: 15 dakika icinde 8 basarisiz deneme -> KILIT (IP veya e-posta bazli).
 *        5. denemeden sonra her cevaba 1.5 sn gecikme (otomatik araclari yavaslatir).
 *
 * Tablo `login_attempts` ONCEDEN olusturulur (kurulum SQL'i ile). Burada DDL YOK:
 * her istekte ALTER/CREATE calistirmak hem yavas hem risklidir.
 *
 * Sayac SADECE basarisiz denemeleri kilitler; basarili giriste kayitlar temizlenir.
 */

if (!defined('LG_MAX_FAILS'))   define('LG_MAX_FAILS', 8);
if (!defined('LG_WINDOW_MIN'))  define('LG_WINDOW_MIN', 15);
if (!defined('LG_SLOW_AFTER'))  define('LG_SLOW_AFTER', 5);

/** Gercek istemci IP'si (Cloudflare DNS-only oldugu icin REMOTE_ADDR guvenilir). */
function lgClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string)$ip, 0, 45);
}

/** Son pencerede IP VEYA e-posta icin basarisiz deneme sayisi (hangisi buyukse). */
function lgRecentFails(PDO $pdo, string $email): int {
    // NOT: MySQL "INTERVAL ? MINUTE" icinde bagli parametre KABUL ETMEZ.
    // Bu yuzden pencere degeri (int)'e zorlanip sorguya gomulur - kullanici girdisi degil, sabit.
    $win = (int)LG_WINDOW_MIN;
    try {
        $st = $pdo->prepare(
            "SELECT MAX(c) FROM (
               SELECT COUNT(*) c FROM login_attempts
                 WHERE ok = 0 AND ip = ? AND attempted_at > (NOW() - INTERVAL $win MINUTE)
               UNION ALL
               SELECT COUNT(*) c FROM login_attempts
                 WHERE ok = 0 AND email = ? AND attempted_at > (NOW() - INTERVAL $win MINUTE)
             ) x");
        $st->execute([lgClientIp(), mb_strtolower(trim($email))]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('lgRecentFails: ' . $e->getMessage());
        return 0;   // sayac bozulursa girisi kilitlemeyiz (kullaniciyi kilitli birakmamak icin)
    }
}

/** Kilitli mi? Kilitli degilse, deneme sayisina gore yavaslatma uygular. */
function lgIsLocked(PDO $pdo, string $email): bool {
    $n = lgRecentFails($pdo, $email);
    if ($n >= LG_MAX_FAILS) return true;
    if ($n >= LG_SLOW_AFTER) usleep(1500000);   // 1.5 sn
    return false;
}

/** Denemeyi kaydet. */
function lgLog(PDO $pdo, string $email, bool $ok): void {
    try {
        $st = $pdo->prepare("INSERT INTO login_attempts (ip, email, ok) VALUES (?, ?, ?)");
        $st->execute([lgClientIp(), mb_substr(mb_strtolower(trim($email)), 0, 190), $ok ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('lgLog: ' . $e->getMessage());
    }
}

/** Basarili giristen sonra bu IP + e-posta icin sayaci sifirla. */
function lgClear(PDO $pdo, string $email): void {
    try {
        $st = $pdo->prepare("DELETE FROM login_attempts WHERE ip = ? OR email = ?");
        $st->execute([lgClientIp(), mb_strtolower(trim($email))]);
    } catch (Throwable $e) {
        error_log('lgClear: ' . $e->getMessage());
    }
}

/** Kilit mesaji (kullaniciya gosterilecek). */
function lgLockMessage(): string {
    return 'Cok fazla basarisiz giris denemesi. Guvenlik icin bu hesap ' . LG_WINDOW_MIN . ' dakika kilitlendi.';
}
