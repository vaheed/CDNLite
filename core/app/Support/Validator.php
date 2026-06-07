<?php

namespace App\Support;

final class Validator
{
    public static function dnsRecordContent(string $type, string $content): array
    {
        $normalizedType = strtoupper(trim($type));
        $value = trim($content);
        if ($value === '') {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'must_be_non_empty', 'status' => 422];
        }

        if ($normalizedType === 'A' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'must_be_valid_ipv4', 'status' => 422];
        }
        if ($normalizedType === 'AAAA' && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'must_be_valid_ipv6', 'status' => 422];
        }
        if ($normalizedType === 'CNAME' && !preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+\.?$/i', $value)) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'must_be_valid_hostname', 'status' => 422];
        }
        if ($normalizedType === 'MX' && !preg_match('/^(?=.{1,253}$)(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+\.?$/i', $value)) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'must_be_valid_hostname_for_mx', 'status' => 422];
        }
        if ($normalizedType === 'CAA' && strlen($value) > 1024) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'max_length_1024', 'status' => 422];
        }
        if ($normalizedType === 'TXT' && strlen($value) > 1024) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => 'content', 'detail' => 'max_length_1024', 'status' => 422];
        }

        return ['ok' => true, 'value' => $value];
    }
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

    public static function ipv4Cidr(array $body, string $key): array
    {
        $string = self::requiredString($body, $key, 64);
        if (($string['ok'] ?? false) !== true) {
            return $string;
        }
        $parts = explode('/', (string) $string['value'], 2);
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_valid_ipv4_cidr', 'status' => 422];
        }
        if (!ctype_digit($parts[1])) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_valid_ipv4_cidr', 'status' => 422];
        }
        $prefix = (int) $parts[1];
        if ($prefix < 0 || $prefix > 32) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_valid_ipv4_cidr', 'status' => 422];
        }
        return ['ok' => true, 'value' => $parts[0] . '/' . $prefix];
    }

    public static function headerName(array $body, string $key): array
    {
        $string = self::requiredString($body, $key, 255);
        if (($string['ok'] ?? false) !== true) {
            return $string;
        }
        $value = (string) $string['value'];
        if (!preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $value)) {
            return ['ok' => false, 'error' => 'invalid_field', 'field' => $key, 'detail' => 'must_be_valid_http_header_name', 'status' => 422];
        }
        return ['ok' => true, 'value' => $value];
    }
}
