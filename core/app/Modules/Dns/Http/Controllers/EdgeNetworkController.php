<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsReconciler;
use App\Modules\Dns\Services\GeoRoutingService;
use App\Modules\Settings\Repositories\SettingsRepository;

class EdgeNetworkController
{
    public function __construct(
        private SettingsRepository $settings = new SettingsRepository(),
        private GeoRoutingService $geo = new GeoRoutingService()
    ) {
    }

    public function anycast(): array
    {
        $values = $this->settings->group('platform.edge_dns')['values'];
        return ['data' => $this->anycastPayload($values)];
    }

    public function updateAnycast(array $input, string $actor): array
    {
        $fields = ['anycast_ipv4_1', 'anycast_ipv4_2', 'anycast_ipv6_1', 'anycast_ipv6_2'];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = trim((string) ($input[$field] ?? ''));
        }
        $configured = array_filter($values, static fn(string $value): bool => $value !== '');
        if ($configured !== [] && count($configured) !== 4) {
            return ['error' => 'anycast_requires_two_ipv4_and_two_ipv6', 'status' => 422];
        }
        foreach ($values as $field => $value) {
            $flag = str_contains($field, 'ipv4') ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP, $flag) === false) {
                return ['error' => 'invalid_' . $field, 'field' => $field, 'status' => 422];
            }
        }
        $group = $this->settings->patch('platform.edge_dns', $values, $actor);
        (new DnsReconciler())->reconcile(true);
        return ['data' => $this->anycastPayload($group['values'])];
    }

    public function countries(): array
    {
        return ['data' => $this->geo->countries()];
    }

    private function anycastPayload(array $values): array
    {
        $base = rtrim(strtolower((string) $values['base_domain']), '.');
        $prefix = strtolower((string) $values['zone_prefix']);
        return [
            'anycast_ipv4_1' => (string) $values['anycast_ipv4_1'],
            'anycast_ipv4_2' => (string) $values['anycast_ipv4_2'],
            'anycast_ipv6_1' => (string) $values['anycast_ipv6_1'],
            'anycast_ipv6_2' => (string) $values['anycast_ipv6_2'],
            'global_anycast_hostname' => 'global.' . $prefix . '.' . $base . '.',
        ];
    }
}
