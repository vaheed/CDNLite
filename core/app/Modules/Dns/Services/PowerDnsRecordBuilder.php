<?php

namespace App\Modules\Dns\Services;

class PowerDnsRecordBuilder
{
    public function soa(string $baseDomain): string
    {
        $ns = $this->fqdn('ns1.' . $baseDomain);
        $hostmaster = 'hostmaster.' . rtrim(strtolower($baseDomain), '.') . '.';
        $serial = gmdate('Ymd') . '01';
        return "{$ns} {$hostmaster} {$serial} 3600 600 604800 60";
    }

    /**
     * @return list<string>
     */
    public function nameservers(): array
    {
        $raw = trim((string) (getenv('POWERDNS_ZONE_NAMESERVERS') ?: ''));
        if ($raw === '') {
            $base = (string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net');
            return [$this->fqdn('ns1.' . $base), $this->fqdn('ns2.' . $base)];
        }

        $items = [];
        foreach (array_map('trim', explode(',', $raw)) as $item) {
            if ($item !== '') {
                $items[] = $this->fqdn($item);
            }
        }
        return $items === [] ? ['ns1.local.'] : array_values(array_unique($items));
    }

    public function fqdn(string $name): string
    {
        $name = rtrim(strtolower(trim($name)), '.');
        return $name === '' ? '.' : $name . '.';
    }

    public function hostname(string $label, string $baseDomain): string
    {
        $label = trim($label, '.');
        return $label === '' ? $this->fqdn($baseDomain) : $this->fqdn($label . '.' . $baseDomain);
    }
}
