<?php

namespace App\Modules\Dns\Services;

use App\Modules\Settings\Repositories\SettingsRepository;

class PowerDnsRecordBuilder
{
    public function __construct(private ?SettingsRepository $settings = null)
    {
        $this->settings ??= new SettingsRepository();
    }

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
        $configured = $this->settings->value('platform.nameservers', 'hostnames');
        $configured = is_array($configured) ? $configured : explode(',', (string) $configured);
        $items = [];
        foreach (array_map('trim', $configured) as $item) {
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
