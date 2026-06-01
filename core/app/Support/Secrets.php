<?php

namespace App\Support;

final class Secrets
{
    public static function isConfigured(): bool
    {
        return self::key() !== '';
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === '') {
            throw new \RuntimeException('ssl_secret_key_missing');
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('ssl_secret_encrypt_failed');
        }
        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        if ($key === '') {
            throw new \RuntimeException('ssl_secret_key_missing');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            throw new \RuntimeException('ssl_secret_decode_failed');
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) {
            throw new \RuntimeException('ssl_secret_decrypt_failed');
        }
        return $plain;
    }

    private static function key(): string
    {
        $raw = (string) (getenv('CDNLITE_SSL_SECRET_KEY') ?: '');
        if ($raw === '') {
            return '';
        }
        return hash('sha256', $raw, true);
    }
}
