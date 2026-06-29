<?php

namespace App\Services\ControlPlane;

use Illuminate\Support\Facades\DB;
use Throwable;

final class DnsPowerDnsReconciler
{
    private const LOCK_NAME = 'cdnlite_dns_reconciler';

    public function __construct(
        private DnsDesiredStateService $desiredState,
        private PowerDnsClient $powerDns,
    ) {
    }

    public function forceSync(): array
    {
        if (!$this->powerDns->enabled()) {
            return $this->persistOnly('powerdns_disabled');
        }
        if (!$this->powerDns->configured()) {
            return $this->persistOnly('powerdns_missing_config');
        }

        if (!$this->acquireLock()) {
            return ['ok' => true, 'queued' => true, 'reason' => 'dns_reconciler_busy'];
        }

        try {
            return DB::transaction(function (): array {
                $previous = $this->storedIdentities();
                $desired = $this->desiredState->build();
                $generationId = $this->desiredState->persistGeneration($desired);
                $this->desiredState->refreshSyncState($desired, $generationId);

                $desiredIdentities = $this->identities($desired);
                $desiredZones = $this->desiredZones($desired);
                $zones = $this->byZone($desired);

                foreach ($previous as $identity => $rrset) {
                    if (!isset($desiredIdentities[$identity])) {
                        $zones[(string) $rrset['zone_name']][] = [
                            'name' => (string) $rrset['rrset_name'],
                            'type' => (string) $rrset['rrset_type'],
                            'changetype' => 'DELETE',
                        ];
                    }
                }

                $zoneSummaries = collect($this->desiredState->zoneSummaries($desired))->keyBy('zone_name')->all();
                $changes = 0;
                $failures = [];

                foreach ($zones as $zone => $rrsets) {
                    $summary = $zoneSummaries[$zone] ?? null;
                    $desiredHash = is_array($summary) ? (string) $summary['desired_hash'] : null;
                    $pending = count($rrsets);
                    $this->markSyncing($zone, $desiredHash, $generationId, $pending);

                    if (!isset($desiredZones[$zone]) && $this->onlyDeletes($rrsets)) {
                        $result = $this->powerDns->deleteZone($zone);
                        if (($result['ok'] ?? false) === true) {
                            $changes++;
                            $this->markConverged($zone, $desiredHash, $generationId, (int) ($result['status'] ?? 0), 'delete_zone');
                            continue;
                        }

                        $failures[] = $this->failure($zone, null, null, $result);
                        $this->markFailed($zone, $desiredHash, $generationId, $result, 'delete_zone');
                        continue;
                    }

                    $zoneResult = $this->powerDns->ensureZone($zone);
                    if (($zoneResult['ok'] ?? false) !== true) {
                        $failures[] = $this->failure($zone, null, null, $zoneResult);
                        $this->markFailed($zone, $desiredHash, $generationId, $zoneResult, 'ensure_zone');
                        continue;
                    }

                    $patches = $this->changes($zone, $rrsets);
                    if ($patches === []) {
                        $this->markConverged($zone, $desiredHash, $generationId, (int) ($zoneResult['status'] ?? 200), 'verify_zone');
                        continue;
                    }

                    foreach ($this->orderedPatches($patches) as $batch) {
                        $result = $this->powerDns->patchRrsets($zone, $batch);
                        if (($result['ok'] ?? false) !== true) {
                            $first = $batch[0] ?? [];
                            $failures[] = $this->failure($zone, $first['name'] ?? null, $first['type'] ?? null, $result);
                            $this->markFailed($zone, $desiredHash, $generationId, $result, 'patch_rrsets', $first);
                            continue 2;
                        }

                        $changes += count($batch);
                    }

                    $this->markConverged($zone, $desiredHash, $generationId, 200, 'patch_rrsets');
                }

                if ($failures === []) {
                    $this->desiredState->pruneGeneration($generationId);
                }

                return [
                    'ok' => $failures === [],
                    'mode' => 'powerdns_reconciled',
                    'generation_id' => $generationId,
                    'zones' => count($zones),
                    'changes' => $changes,
                    'failures' => $failures,
                    'error' => $failures === [] ? null : 'powerdns_reconcile_partial_failure',
                ];
            });
        } finally {
            $this->releaseLock();
        }
    }

    private function persistOnly(string $reason): array
    {
        $persisted = $this->desiredState->persistDesiredState();

        return $persisted + [
            'powerdns_enabled' => $this->powerDns->enabled(),
            'powerdns_configured' => $this->powerDns->configured(),
            'powerdns_skipped_reason' => $reason,
        ];
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
                    static fn (string $content): array => ['content' => $content, 'disabled' => false],
                    $rrset['records'],
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
            $actual[strtolower((string) ($rrset['name'] ?? '')).'|'.strtoupper((string) ($rrset['type'] ?? ''))] = $rrset;
        }

        return array_values(array_filter($desired, function (array $rrset) use ($actual): bool {
            $key = strtolower((string) $rrset['name']).'|'.strtoupper((string) $rrset['type']);
            if (($rrset['changetype'] ?? 'REPLACE') === 'DELETE') {
                return isset($actual[$key]);
            }
            if (!isset($actual[$key])) {
                return true;
            }

            $wanted = array_map(static fn (array $record): string => (string) $record['content'], (array) $rrset['records']);
            $found = array_map(static fn (array $record): string => (string) $record['content'], (array) ($actual[$key]['records'] ?? []));
            sort($wanted);
            sort($found);

            return $wanted !== $found || (int) ($actual[$key]['ttl'] ?? 0) !== (int) ($rrset['ttl'] ?? 0);
        }));
    }

    private function orderedPatches(array $rrsets): array
    {
        $deletes = [];
        $replacements = [];
        foreach ($rrsets as $rrset) {
            if (($rrset['changetype'] ?? 'REPLACE') === 'DELETE') {
                $deletes[] = $rrset;
            } else {
                $replacements[] = $rrset;
            }
        }

        return array_values(array_filter([$deletes, $replacements]));
    }

    private function storedIdentities(): array
    {
        $result = [];
        foreach (DB::table('desired_dns_rrsets')->where('owner', 'cdnlite')->get() as $row) {
            $rrset = (array) $row;
            $result[$this->identity($rrset)] = $rrset;
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
        return strtolower((string) $rrset['zone_name']).'|'
            .strtolower((string) $rrset['rrset_name']).'|'
            .strtoupper((string) $rrset['rrset_type']);
    }

    private function acquireLock(): bool
    {
        $row = DB::selectOne('SELECT pg_try_advisory_lock(hashtext(?)) AS locked', [self::LOCK_NAME]);

        return (bool) ($row->locked ?? false);
    }

    private function releaseLock(): void
    {
        try {
            DB::selectOne('SELECT pg_advisory_unlock(hashtext(?))', [self::LOCK_NAME]);
        } catch (Throwable) {
        }
    }

    private function markSyncing(string $zone, ?string $desiredHash, int $generationId, int $pending): void
    {
        $now = UnixTime::now();
        DB::table('dns_sync_state')->upsert([[
            'zone_name' => $zone,
            'desired_hash' => $desiredHash,
            'applied_hash' => DB::table('dns_sync_state')->where('zone_name', $zone)->value('applied_hash'),
            'generation_id' => $generationId,
            'status' => 'syncing',
            'last_attempt_at' => $now,
            'last_success_at' => DB::table('dns_sync_state')->where('zone_name', $zone)->value('last_success_at'),
            'last_error' => null,
            'last_status_code' => null,
            'pending_changes' => $pending,
            'in_progress' => true,
            'updated_at' => $now,
        ]], ['zone_name'], [
            'desired_hash',
            'generation_id',
            'status',
            'last_attempt_at',
            'last_error',
            'last_status_code',
            'pending_changes',
            'in_progress',
            'updated_at',
        ]);
        $this->event($zone, null, null, 'sync_zone', 'attempt', null, null, $desiredHash, null, $generationId);
    }

    private function markConverged(string $zone, ?string $desiredHash, int $generationId, int $statusCode, string $action): void
    {
        $now = UnixTime::now();
        DB::table('dns_sync_state')->where('zone_name', $zone)->update([
            'applied_hash' => $desiredHash,
            'generation_id' => $generationId,
            'status' => 'ok',
            'last_success_at' => $now,
            'last_error' => null,
            'last_status_code' => $statusCode,
            'pending_changes' => 0,
            'in_progress' => false,
            'updated_at' => $now,
        ]);
        $this->event($zone, null, null, $action, 'success', $statusCode, null, $desiredHash, $desiredHash, $generationId);
    }

    private function markFailed(string $zone, ?string $desiredHash, int $generationId, array $result, string $action, array $rrset = []): void
    {
        $now = UnixTime::now();
        $error = (string) ($result['error'] ?? 'powerdns_sync_failed');
        $statusCode = (int) ($result['status'] ?? 0);
        DB::table('dns_sync_state')->where('zone_name', $zone)->update([
            'status' => 'failed',
            'last_error' => $error,
            'last_status_code' => $statusCode,
            'in_progress' => false,
            'updated_at' => $now,
        ]);
        $this->event($zone, $rrset['name'] ?? null, $rrset['type'] ?? null, $action, 'failed', $statusCode, $error, $desiredHash, null, $generationId);
    }

    private function event(
        string $zone,
        ?string $rrsetName,
        ?string $rrsetType,
        string $action,
        string $status,
        ?int $statusCode,
        ?string $error,
        ?string $desiredHash,
        ?string $appliedHash,
        int $generationId,
    ): void {
        DB::table('dns_sync_events')->insert([
            'zone_name' => $zone,
            'rrset_name' => $rrsetName,
            'rrset_type' => $rrsetType,
            'action' => $action,
            'status' => $status,
            'status_code' => $statusCode,
            'error' => $error,
            'desired_hash' => $desiredHash,
            'applied_hash' => $appliedHash,
            'generation_id' => $generationId,
            'created_at' => UnixTime::now(),
        ]);
    }

    private function failure(string $zone, ?string $rrsetName, ?string $rrsetType, array $result): array
    {
        return [
            'zone' => $zone,
            'rrset_name' => $rrsetName,
            'rrset_type' => $rrsetType,
            'status' => (int) ($result['status'] ?? 0),
            'error' => (string) ($result['error'] ?? 'powerdns_sync_failed'),
        ];
    }
}
