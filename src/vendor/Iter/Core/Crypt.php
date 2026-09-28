<?php

declare(strict_types=1);

namespace Iter\Core;

/**
 * Secrets saved in the DB (e.g. the SMTP password): sodium secretbox with the
 * key in src/etc/env.ini (git-ignored). The key is created on first use; losing
 * it only means typing the secrets again.
 */
class Crypt
{
    private const PREFIX = 'enc:';

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    /** '' when the value can't be read (other key, not encrypted, tampered) */
    public static function decrypt(?string $value): string
    {
        if ($value === null || !str_starts_with($value, self::PREFIX)) {
            return '';
        }
        $raw = (string) base64_decode(substr($value, strlen(self::PREFIX)), true);
        $plain = strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::key()
        ) : false;
        if ($plain === false) {
            error_log('Crypt: valor não pôde ser decifrado (chave de src/etc/env.ini diferente?)');
        }

        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        $file = ETC . 'env.ini';
        $key = base64_decode((string) ((is_file($file) ? parse_ini_file($file) : [])['crypt_key'] ?? ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            file_put_contents($file, (is_file($file) ? file_get_contents($file) : '') . 'crypt_key="' . base64_encode($key) . "\"\n", LOCK_EX);
            chmod($file, 0600);
        }

        return $key;
    }
}
