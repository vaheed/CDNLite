<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsReconciler
{
    private const LOCK_NAME = 'cdnlite_dns_reconciler';

    public function __construct(
        private DnsDesiredStateBuilder $builder = new DnsDesiredStateBuilder(),
        private PowerDnsService $powerDns = new PowerDnsService(),
        private DnsSyncStateService $syncState = new DnsSyncStateService()
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
        return ['rrsets' => $desired, 'zones' => count($this->byZone($desired)), 'changes' => count($desired)];
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
