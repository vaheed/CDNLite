<?php

namespace App\Support;

class Logger
{
    /** @var array<string,int> */
    private const LEVELS = [
        'debug' => 10,
        'info' => 20,
        'warn' => 30,
        'error' => 40,
    ];

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warn(string $message, array $context = []): void
    {
        self::log('warn', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function isDebug(): bool
    {
        $value = getenv('APP_DEBUG');
        if ($value === false) {
            return false;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function log(string $level, string $message, array $context): void
    {
        if (!self::enabled() || !self::shouldLog($level)) {
            return;
        }

        $payload = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
        ];
        if ($context !== []) {
            $payload['context'] = $context;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = sprintf('{"ts":"%s","level":"error","message":"logger_json_encode_failed"}', gmdate('c'));
        }
        error_log($json);
    }

    private static function enabled(): bool
    {
        $value = getenv('APP_LOG_ENABLED');
        if ($value === false) {
            return true;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function shouldLog(string $level): bool
    {
        $configured = strtolower(trim((string) (getenv('APP_LOG_LEVEL') ?: 'info')));
        $minLevel = self::LEVELS[$configured] ?? self::LEVELS['info'];
        $current = self::LEVELS[$level] ?? self::LEVELS['info'];
        return $current >= $minLevel;
    }
}
