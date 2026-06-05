<?php

namespace App\Modules\Dns\Services;

class CustomerDnsService
{
    public function __construct(private GeoPolicyService $geoPolicy = new GeoPolicyService())
    {
    }

    public function publicRecordFor(array $domain, array $record): array
    {
        if (($record['proxied'] ?? false) !== true) {
            return [
                'type' => strtoupper((string) $record['type']),
                'content' => (string) $record['content'],
            ];
        }

        $target = $this->geoPolicy->targetForRecord($record);
        return [
            'type' => $this->isApex((string) $record['name'], (string) $domain['domain']) ? $this->apexMode() : 'CNAME',
            'content' => $target,
        ];
    }

    public function isApex(string $name, string $domainDomain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domainDomain), '.'));
        return $name === '' || $name === '@' || $name === $domain;
    }

    private function apexMode(): string
    {
        $mode = strtoupper((string) (getenv('CDNLITE_EDGE_APEX_MODE') ?: 'ALIAS'));
        return in_array($mode, ['ALIAS', 'CNAME'], true) ? $mode : 'ALIAS';
    }
}
