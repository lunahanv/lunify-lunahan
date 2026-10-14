<?php
/**
 * Lunify Lunahan — Gizli deger sifreleme (Viktor, 2026-09-10)
 *
 * Neden: atlas_ai_providers.api_key ve user_ai_keys.api_key (AI saglayici anahtarlari).
 * Bu degerler duz metin tutuluyordu; DB dokumu sizarsa saldirgan ucretli
 * AI hesaplarini dogrudan kullanabilirdi (fatura + veri erisimi).
 *
 * Nasil: libsodium secretbox. Anahtar web kokunun DISINDA: /etc/lunify/lunahan.key
 *
 * GERI UYUMLU: hsecOpen() sifreli olmayan degeri AYNEN dondurur, boylece
 * kod once devreye alinip veri sonra tasinabilir (kesintisiz gecis).
 *
 * DIKKAT: /etc/lunify/lunahan.key kaybolursa sifreli degerler GERI DONMEZ. Yedekle,
 * ama DB yedeginden AYRI yerde tut (yoksa sifrelemenin anlami kalmaz).
 */

const HSEC_PREFIX = 'enc:v1:';

/** Hangi tablo+kolon sifrelenecek (YAZMA tarafi). */
function hsecColumns(): array {
    return [
        'atlas_ai_providers' => ['api_key'],
        'user_ai_keys'       => ['api_key'],
    ];
}

/** OKUMA tarafinda cozulecek kolon adlari. */
function hsecReadColumns(): array {
    static $c = null;
    if ($c === null) {
        $c = [];
        foreach (hsecColumns() as $cols) { foreach ($cols as $col) $c[$col] = true; }
    }
    return $c;
}

function hsecKey(): ?string {
    static $key = false;
    if ($key !== false) return $key;
    $key = null;
    $p = '/etc/lunify/lunahan.key';
    if (is_readable($p)) {
        $bin = base64_decode(trim((string)file_get_contents($p)), true);
        if (is_string($bin) && strlen($bin) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) $key = $bin;
        else error_log('lunahan secrets: anahtar gecersiz, sifreleme devre disi');
    }
    return $key;
}

function hsecAvailable(): bool {
    return function_exists('sodium_crypto_secretbox') && hsecKey() !== null;
}

function hsecIsSealed($v): bool {
    return is_string($v) && strncmp($v, HSEC_PREFIX, strlen(HSEC_PREFIX)) === 0;
}

/** Duz metni sifreler. Anahtar yoksa DEGERI OLDUGU GIBI dondurur (veri kaybetmemek icin). */
function hsecSeal($v) {
    if (!is_string($v) || $v === '') return $v;
    if (hsecIsSealed($v)) return $v;
    $key = hsecKey();
    if ($key === null || !function_exists('sodium_crypto_secretbox')) {
        error_log('hsecSeal: anahtar yok, duz metin korundu');
        return $v;
    }
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return HSEC_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($v, $nonce, $key));
}

/** Sifreli degeri cozer. Sifreli DEGILSE aynen dondurur (geri uyumluluk). */
function hsecOpen($v) {
    if (!is_string($v) || $v === '') return $v;
    if (!hsecIsSealed($v)) return $v;
    $key = hsecKey();
    if ($key === null || !function_exists('sodium_crypto_secretbox_open')) {
        error_log('hsecOpen: anahtar yok');
        return '';
    }
    $raw = base64_decode(substr($v, strlen(HSEC_PREFIX)), true);
    if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
        error_log('hsecOpen: bozuk deger');
        return '';
    }
    try {
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $key
        );
    } catch (Throwable $e) {
        error_log('hsecOpen istisnasi: ' . $e->getMessage());
        return '';
    }
    if ($plain === false) { error_log('hsecOpen: dogrulama basarisiz'); return ''; }
    return $plain;
}

/** Gosterim icin maskeler: sk-abcd…wxyz */
function hsecMask($v): string {
    $v = (string)$v;
    if ($v === '') return '';
    if (strlen($v) <= 10) return str_repeat('*', strlen($v));
    return substr($v, 0, 6) . '…' . substr($v, -4);
}

function hsecSealRow(string $table, array $data): array {
    $map = hsecColumns();
    $t = strtolower($table);
    if (!isset($map[$t])) return $data;
    foreach ($map[$t] as $col) {
        if (array_key_exists($col, $data)) $data[$col] = hsecSeal($data[$col]);
    }
    return $data;
}

function hsecOpenRow($row) {
    if (!is_array($row)) return $row;
    $cols = hsecReadColumns();
    foreach ($row as $k => $v) {
        if (isset($cols[$k]) && hsecIsSealed($v)) $row[$k] = hsecOpen($v);
    }
    return $row;
}
