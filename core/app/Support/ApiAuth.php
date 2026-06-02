<?php

namespace App\Support;

use App\Modules\Admin\Services\AdminAuthService;

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
        return self::isConfigured() || (new AdminAuthService())->hasUsers();
    }

    public static function isValid(?string $token): bool
    {
        $expected = self::expectedToken();
        $provided = (string) ($token ?? '');

        if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        if ((new AdminAuthService())->userForToken($provided) !== null) {
            return true;
        }

        return $expected === '' && !(new AdminAuthService())->hasUsers();
    }

    public static function productionMissingToken(): bool
    {
        $env = strtolower(trim((string) getenv('APP_ENV')));
        return $env === 'production' && !self::isConfigured();
    }
}
