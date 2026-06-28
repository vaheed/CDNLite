<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;

final class DnsReconcileQueue
{
    public function __construct(private AuditWriter $audit)
    {
    }

    public function queueForDomain(string $domainId): void
    {
        if (!$this->powerDnsEnabled()) {
            return;
        }

        $this->audit->write('dns.reconcile.queued', 'dns', 'powerdns', null, null, 'system', null, $domainId, [
            'local_state_saved' => true,
            'strict' => $this->powerDnsStrict(),
        ]);
    }

    private function powerDnsEnabled(): bool
    {
        return $this->settingBool('platform.powerdns.enabled', false);
    }

    private function powerDnsStrict(): bool
    {
        return $this->settingBool('platform.powerdns.strict', false);
    }

    private function settingBool(string $key, bool $default): bool
    {
        $raw = DB::table('platform_settings')->where('key', $key)->value('value_json');
        if (!is_string($raw)) {
            return $default;
        }

        return filter_var(json_decode($raw, true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}

