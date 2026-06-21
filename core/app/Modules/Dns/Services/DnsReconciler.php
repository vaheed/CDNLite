<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsReconciler
{
    private const LOCK_NAME = 'cdnlite_dns_reconciler';

    public function __construct(
        private DnsDesiredStateBuilder $builder = new DnsDesiredStateBuilder(),
        private PowerDnsService $powerDns = new PowerDnsService(),
        private DnsSyncStateService $syncState = new DnsSyncStateService(),
        private PowerDnsSoaService $soa = new PowerDnsSoaService(),
        private EdgeDnsPoolRenderer $edgePool = new EdgeDnsPoolRenderer()
    ) {
    }

    public function reconcile(bool $force = false): array
    {
        if (!$this->powerDns->isEnabled()) {
            return ['ok' => true, 'powerdns_enabled' => false, 'zones' => 0, 'changes' => 0];
        }
        $pdo = Database::pdo();
        $lock = $pdo->prepare('SELECT pg_try_advisory_lock(hashtext(:name))');
        $lock->execute(['name' => self::LOCK_NAME]);
        if (!(bool) $lock->fetchColumn()) {
            return ['ok' => true, 'queued' => true, 'reason' => 'dns_reconciler_busy'];
        }

        try {
            $previous = $this->storedIdentities();
            $desired = $this->builder->build();
            $desiredIdentities = $this->identities($desired);
            $desiredZones = $this->desiredZones($desired);
            $generation = $this->builder->persist($desired);
            $zones = $this->byZone($desired);
            foreach ($previous as $identity => $rrset) {
                if (!isset($desiredIdentities[$identity])) {
                    $zones[$rrset['zone_name']][] = [
                        'name' => $rrset['rrset_name'],
                        'type' => $rrset['rrset_type'],
                        'changetype' => 'DELETE',
                    ];
                }
            }

            $changes = 0;
            $failures = [];
            foreach ($zones as $zone => $rrsets) {
                if (!isset($desiredZones[$zone]) && $this->onlyDeletes($rrsets)) {
                    $deleteResult = $this->powerDns->deleteZone($zone);
                    if (($deleteResult['ok'] ?? false) !== true) {
                        $failures[] = $deleteResult + ['zone' => $zone];
                        continue;
                    }
                    $changes++;
                    continue;
                }
                $zoneResult = $this->powerDns->ensureZone($zone);
                if (($zoneResult['ok'] ?? false) !== true) {
                    $failures[] = $zoneResult + ['zone' => $zone];
                    continue;
                }
                $patch = $force ? $rrsets : $this->changes($zone, $rrsets);
                if ($patch === []) {
                    $soaResult = $this->soa->repair($zone, $rrsets);
                    if (($soaResult['ok'] ?? false) !== true) {
                        $failures[] = $soaResult + ['zone' => $zone, 'rrset_name' => $zone, 'rrset_type' => 'SOA'];
                        continue;
                    }
                    if (($soaResult['repaired'] ?? false) === true) {
                        $changes++;
                    }
                    $this->syncState->markConverged($zone, $rrsets, $generation);
                    continue;
                }
                foreach ($this->orderedPatches($patch) as $orderedPatch) {
                    foreach ($orderedPatch as $rrset) {
                        $result = $this->powerDns->patchRrsets($zone, [$rrset]);
                        if (($result['ok'] ?? false) !== true) {
                            $failures[] = $result + [
                                'zone' => $zone,
                                'rrset_name' => $rrset['name'] ?? null,
                                'rrset_type' => $rrset['type'] ?? null,
                            ];
                            continue;
                        }
                        $changes++;
                    }
                }
                $soaResult = $this->soa->repair($zone, $rrsets);
                if (($soaResult['ok'] ?? false) !== true) {
                    $failures[] = $soaResult + ['zone' => $zone, 'rrset_name' => $zone, 'rrset_type' => 'SOA'];
                    continue;
                }
                if (($soaResult['repaired'] ?? false) === true) {
                    $changes++;
                }
            }
            if ($failures === []) {
                $this->builder->prune($generation);
            }
            return [
                'ok' => $failures === [],
                'generation_id' => $generation,
                'zones' => count($zones),
                'changes' => $changes,
                'failures' => $failures,
                'error' => $failures === [] ? null : 'powerdns_reconcile_partial_failure',
            ];
        } finally {
            $unlock = $pdo->prepare('SELECT pg_advisory_unlock(hashtext(:name))');
            $unlock->execute(['name' => self::LOCK_NAME]);
        }
    }

    public function preview(): array
    {
        $desired = $this->builder->build();
        $zones = $this->byZone($desired);
        return [
            'rrsets' => $desired,
            'zones' => count($zones),
            'changes' => count($desired),
            'counts' => $this->previewCounts($desired),
            'errors' => $this->edgePool->edgeSelectionRrsets() === [] ? ['no_eligible_edge_ips'] : [],
            'soa' => $this->soa->preview($zones),
        ];
    }

    private function previewCounts(array $desired): array
    {
        $counts = [
            'zones_scanned' => count($this->byZone($desired)),
            'proxied_apex_records_scanned' => 0,
            'apex_lua_records_to_create_or_update' => 0,
            'platform_proxy_lua_records_to_update' => 0,
            'old_managed_apex_alias_records_to_remove' => 0,
            'skipped_uncertain_ownership' => 0,
            'errors' => 0,
        ];

        foreach ($desired as $rrset) {
            $source = (string) ($rrset['source'] ?? '');
            if (str_contains($source, ':apex_lua:') || str_contains($source, ':apex_static_anycast:')) {
                $counts['proxied_apex_records_scanned']++;
                $counts['apex_lua_records_to_create_or_update']++;
            }
            if (str_starts_with($source, 'shared_proxy:')) {
                $counts['platform_proxy_lua_records_to_update']++;
            }
        }

        foreach ($this->storedIdentities() as $rrset) {
            if (strtoupper((string) $rrset['rrset_type']) === 'ALIAS') {
                $counts['old_managed_apex_alias_records_to_remove']++;
            }
        }

        if ($this->edgePool->edgeSelectionRrsets() === []) {
            $counts['errors']++;
        }

        return $counts;
    }

    private function byZone(array $desired): array
    {
        $zones = [];
        foreach ($desired as $rrset) {
            $zones[$rrset['zone_name']][] = [
                'name' => $rrset['rrset_name'],
                'type' => $rrset['rrset_type'],
                'ttl' => $rrset['ttl'],
                'changetype' => 'REPLACE',
                'records' => array_map(
                    static fn(string $content): array => ['content' => $content, 'disabled' => false],
                    $rrset['records']
                ),
            ];
        }
        return $zones;
    }

    private function changes(string $zone, array $desired): array
    {
        $actualResult = $this->powerDns->getZone($zone);
        if (($actualResult['ok'] ?? false) !== true) {
            return $desired;
        }
        $actual = [];
        foreach ((array) ($actualResult['zone']['rrsets'] ?? []) as $rrset) {
            $actual[strtolower((string) $rrset['name']) . '|' . strtoupper((string) $rrset['type'])] = $rrset;
        }
        return array_values(array_filter($desired, function (array $rrset) use ($actual): bool {
            $key = strtolower((string) $rrset['name']) . '|' . strtoupper((string) $rrset['type']);
            if (($rrset['changetype'] ?? 'REPLACE') === 'DELETE') {
                return isset($actual[$key]);
            }
            if (!isset($actual[$key])) {
                return true;
            }
            $wanted = array_map(static fn(array $r): string => (string) $r['content'], $rrset['records']);
            $found = array_map(static fn(array $r): string => (string) $r['content'], (array) $actual[$key]['records']);
            sort($wanted);
            sort($found);
            return $wanted !== $found || (int) $actual[$key]['ttl'] !== (int) $rrset['ttl'];
        }));
    }

    private function orderedPatches(array $rrsets): array
    {
        $deletes = [];
        $replacements = [];
        foreach ($rrsets as $rrset) {
            if (($rrset['changetype'] ?? 'REPLACE') === 'DELETE') {
                $deletes[] = $rrset;
                continue;
            }
            $replacements[] = $rrset;
        }

        return array_values(array_filter([$deletes, $replacements]));
    }

    private function storedIdentities(): array
    {
        $rows = Database::pdo()->query(
            "SELECT zone_name, rrset_name, rrset_type FROM desired_dns_rrsets WHERE owner = 'cdnlite'"
        )->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$this->identity($row)] = $row;
        }
        return $result;
    }

    private function identities(array $rrsets): array
    {
        $result = [];
        foreach ($rrsets as $rrset) {
            $result[$this->identity($rrset)] = true;
        }
        return $result;
    }

    private function desiredZones(array $rrsets): array
    {
        $result = [];
        foreach ($rrsets as $rrset) {
            $result[(string) $rrset['zone_name']] = true;
        }
        return $result;
    }

    private function onlyDeletes(array $rrsets): bool
    {
        if ($rrsets === []) {
            return false;
        }
        foreach ($rrsets as $rrset) {
            if (($rrset['changetype'] ?? 'REPLACE') !== 'DELETE') {
                return false;
            }
        }
        return true;
    }

    private function identity(array $rrset): string
    {
        return strtolower((string) $rrset['zone_name']) . '|' .
            strtolower((string) $rrset['rrset_name']) . '|' .
            strtoupper((string) $rrset['rrset_type']);
    }
}
