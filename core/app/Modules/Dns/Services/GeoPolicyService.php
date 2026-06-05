<?php

namespace App\Modules\Dns\Services;

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

        return $this->defaultTarget();
    }

    public function targetForLabel(string $label): string
    {
        $label = $this->sanitizeLabelPath($label);
        $prefix = $this->sanitizeLabelPath((string) (getenv('CDNLITE_EDGE_ZONE_PREFIX') ?: 'edge'));
        $base = rtrim(strtolower((string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net')), '.');
        return $this->fqdn($label . '.' . $prefix . '.' . $base);
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
