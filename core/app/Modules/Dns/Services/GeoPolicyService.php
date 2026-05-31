<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class GeoPolicyService
{
    public function defaultTarget(): string
    {
        return $this->targetForLabel((string) (getenv('CDNLITE_EDGE_DEFAULT_TARGET') ?: 'geo'));
    }

    public function targetForRecord(array $record): string
    {
        $edgeTarget = trim((string) ($record['edge_target'] ?? ''));
        if ($edgeTarget !== '' && $this->isSafeTarget($edgeTarget)) {
            return $this->fqdn($edgeTarget);
        }

        $policyId = trim((string) ($record['geo_policy_id'] ?? ''));
        if ($policyId !== '') {
            $policy = $this->find($policyId);
            if ($policy !== null) {
                return $this->targetForPolicy($policy);
            }
        }

        return $this->defaultTarget();
    }

    public function targetForPolicy(array $policy): string
    {
        $hash = (string) ($policy['policy_hash'] ?? '');
        if ($hash === '') {
            $config = json_decode((string) ($policy['config_json'] ?? '{}'), true);
            $hash = $this->hash(is_array($config) ? $config : []);
        }
        return $this->targetForLabel('p-' . $hash);
    }

    public function targetForLabel(string $label): string
    {
        $label = $this->sanitizeLabelPath($label);
        $prefix = $this->sanitizeLabelPath((string) (getenv('CDNLITE_EDGE_ZONE_PREFIX') ?: 'edge'));
        $base = rtrim(strtolower((string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net')), '.');
        return $this->fqdn($label . '.' . $prefix . '.' . $base);
    }

    public function hash(array $config): string
    {
        ksort($config);
        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return substr(hash('sha256', $json === false ? '{}' : $json), 0, 10);
    }

    public function find(string $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM geo_policies WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : (array) $row;
    }

    private function sanitizeLabelPath(string $value): string
    {
        $value = strtolower(trim($value, '. '));
        $parts = array_filter(explode('.', $value), static fn(string $part): bool => $part !== '');
        $safe = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9-]/', '-', $part) ?? '';
            $part = trim($part, '-');
            if ($part !== '') {
                $safe[] = substr($part, 0, 63);
            }
        }
        return $safe === [] ? 'geo' : implode('.', $safe);
    }

    private function isSafeTarget(string $target): bool
    {
        $base = rtrim(strtolower((string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net')), '.') . '.';
        $target = $this->fqdn($target);
        return str_ends_with($target, '.' . $base) || $target === $base;
    }

    private function fqdn(string $value): string
    {
        return rtrim(strtolower(trim($value)), '.') . '.';
    }
}
