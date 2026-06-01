<?php

namespace App\Support;

final class Validator
{
    public static function requiredString(array $body, string $key, int $max = 255): array
    {
        if (!array_key_exists($key, $body) || !is_string($body[$key]) || trim($body[$key]) === '') {
            return ['ok' => false, 'error' => $key . '_required', 'status' => 422];
        }
        $value = trim($body[$key]);
        if (strlen($value) > $max) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'max_length_' . $max, 'status' => 422];
        }
        return ['ok' => true, 'value' => $value];
    }

    public static function optionalString(array $body, string $key, int $max = 255): array
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return ['ok' => true, 'exists' => false, 'value' => null];
        }
        if (!is_string($body[$key])) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_string', 'status' => 422];
        }
        $value = trim($body[$key]);
        if (strlen($value) > $max) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'max_length_' . $max, 'status' => 422];
        }
        return ['ok' => true, 'exists' => true, 'value' => $value];
    }

    public static function bool(array $body, string $key, ?bool $default = false): array
    {
        if (!array_key_exists($key, $body)) {
            return ['ok' => true, 'exists' => false, 'value' => $default];
        }
        if (!is_bool($body[$key])) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_boolean', 'status' => 422];
        }
        return ['ok' => true, 'exists' => true, 'value' => $body[$key]];
    }

    public static function intRange(array $body, string $key, int $min, int $max, mixed $default = null): array
    {
        if (!array_key_exists($key, $body)) {
            return ['ok' => true, 'exists' => false, 'value' => $default];
        }
        if (!is_int($body[$key])) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_integer', 'status' => 422];
        }
        $value = $body[$key];
        if ($value < $min || $value > $max) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => "must_be_between_{$min}_and_{$max}", 'status' => 422];
        }
        return ['ok' => true, 'exists' => true, 'value' => $value];
    }

    public static function enum(array $body, string $key, array $allowed): array
    {
        if (!array_key_exists($key, $body)) {
            return ['ok' => true, 'exists' => false, 'value' => null];
        }
        if (!is_string($body[$key])) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_string', 'status' => 422];
        }
        $value = strtolower(trim($body[$key]));
        if (!in_array($value, $allowed, true)) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_one_of_' . implode('_', $allowed), 'status' => 422];
        }
        return ['ok' => true, 'exists' => true, 'value' => $value];
    }

    public static function domain(array $body, string $key): array
    {
        $string = self::requiredString($body, $key, 255);
        if (($string['ok'] ?? false) !== true) {
            return $string;
        }
        $value = (string) $string['value'];
        if (!preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $value)) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_valid_domain', 'status' => 422];
        }
        return ['ok' => true, 'value' => strtolower($value)];
    }
}
