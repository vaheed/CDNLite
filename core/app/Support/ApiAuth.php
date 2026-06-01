<?php

namespace App\Support;

final class ApiAuth
{
    public static function expectedToken(): string
    {
        return trim((string) getenv('CDNLITE_API_TOKEN'));
    }

    public static function isConfigured(): bool
    {
        return self::expectedToken() !== '';
    }

    public static function requiresAuth(): bool
    {
        return self::isConfigured();
    }

    public static function isValid(?string $token): bool
    {
        $expected = self::expectedToken();
        if ($expected === '') {
            return true;
        }
        $provided = (string) ($token ?? '');
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    public static function productionMissingToken(): bool
    {
        $env = strtolower(trim((string) getenv('APP_ENV')));
        return $env === 'production' && !self::isConfigured();
    }
}
