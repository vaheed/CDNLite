<?php

namespace App\Modules\Health\Services;

use App\Modules\Dns\Services\PowerDnsService;
use App\Modules\Dns\Services\DnsSyncStateService;
use App\Modules\Edge\Services\EdgeHealthService;
use App\Support\Database;

class ReadinessService
{
    public function __construct(
        private ?PowerDnsService $powerDns = null,
        private ?EdgeHealthService $edgeHealth = null
    ) {
        $this->powerDns ??= new PowerDnsService();
        $this->edgeHealth ??= new EdgeHealthService();
    }

    public function check(): array
    {
        $coreChecks = [$this->postgresCheck(), $this->powerDnsConfigCheck(), $this->powerDnsReachabilityCheck(), $this->snapshotCheck()];
        $domainChecks = [$this->certificateExpiryCheck(), $this->originHealthCheck()];
        $edgeChecks = [$this->heartbeatCheck(), $this->identityCheck()];

        $powerDns = [
            'enabled' => $this->powerDns->isEnabled(),
            'configured' => $this->powerDns->isConfigured(),
            'api' => $this->powerDns->isEnabled() ? $this->powerDns->healthCheck() : ['ok' => true, 'disabled' => true],
            'sync' => (new DnsSyncStateService())->summary(),
        ];
        return [
            'core' => ['status' => $this->groupStatus($coreChecks), 'checks' => $coreChecks],
            'domain' => ['status' => $this->groupStatus($domainChecks), 'checks' => $domainChecks],
            'edge' => ['status' => $this->groupStatus($edgeChecks), 'checks' => $edgeChecks],
            'powerdns' => $powerDns,
            'checked_at' => time(),
        ];
    }

    private function postgresCheck(): array
    {
        try {
            Database::pdo()->query('SELECT 1');
            return $this->result('postgres', 'ok', 'PostgreSQL reachable');
        } catch (\Throwable) {
            return $this->result('postgres', 'error', 'PostgreSQL is not reachable', 'Check the database service and credentials', '/troubleshooting');
        }
    }

    private function powerDnsConfigCheck(): array
    {
        if (!$this->powerDns->isEnabled()) {
            return $this->result('powerdns_config', 'ok', 'PowerDNS integration is disabled');
        }
        if ($this->powerDns->isConfigured()) {
            return $this->result('powerdns_config', 'ok', 'PowerDNS configuration is present');
        }
        return $this->result('powerdns_config', 'warning', 'PowerDNS API URL or key is not set', 'Add the PowerDNS API URL and key', '/settings');
    }

    private function powerDnsReachabilityCheck(): array
    {
        if (!$this->powerDns->isEnabled()) {
            return $this->result('powerdns_reachable', 'ok', 'PowerDNS reachability check is not required');
        }
        if (!$this->powerDns->isConfigured()) {
            return $this->result('powerdns_reachable', 'warning', 'PowerDNS cannot be checked until it is configured', 'Complete the PowerDNS settings', '/settings');
        }
        $result = $this->powerDns->healthCheck();
        if (($result['ok'] ?? false) === true) {
            return $this->result('powerdns_reachable', 'ok', 'PowerDNS API reachable');
        }
        return $this->result('powerdns_reachable', 'warning', 'PowerDNS API is not reachable', 'Verify the API URL, key, and service', '/settings');
    }

    private function heartbeatCheck(): array
    {
        $cutoff = time() - 300;
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM edge_nodes
             WHERE is_enabled = true AND COALESCE(last_heartbeat_at, last_heartbeat) >= :cutoff'
        );
        $stmt->execute([':cutoff' => $cutoff]);
        $count = (int) $stmt->fetchColumn();
        if ($count > 0) {
            return $this->result('heartbeat', 'ok', sprintf('%d active edge node%s', $count, $count === 1 ? '' : 's'));
        }
        return $this->result('heartbeat', 'warning', 'No edge heartbeat received in the last 5 minutes', 'Check the edge agent and its token', '/edge-nodes');
    }

    private function identityCheck(): array
    {
        $rows = Database::pdo()->query('SELECT edge_id FROM edge_nodes WHERE is_enabled = true')->fetchAll();
        $warnings = 0;
        foreach ($rows as $row) {
            if ($this->edgeHealth->identityStatus((string) ($row['edge_id'] ?? '')) === 'warning') {
                $warnings++;
            }
        }
        if ($warnings === 0) {
            return $this->result('identity', 'ok', 'All enabled edge identities are valid');
        }
        return $this->result('identity', 'warning', sprintf('%d edge node%s use a default identity', $warnings, $warnings === 1 ? '' : 's'), 'Set a unique EDGE_ID on each node', '/edge-nodes');
    }

    private function snapshotCheck(): array
    {
        $row = Database::pdo()->query(
            'SELECT cs.active_snapshot_version, cs.dirty, cs.published_at, cs.dirty_at, cs.last_publish_error, s.generated_at, s.payload_json
             FROM config_state cs
             LEFT JOIN config_snapshots s ON s.version = cs.active_snapshot_version
             WHERE cs.id = 1'
        )->fetch();
        if (!$row || $row['active_snapshot_version'] === null) {
            return $this->result('config_snapshot', 'error', 'No active edge configuration has been published', 'Run a manual config publish or allow the first edge pull to publish', '/edge-nodes');
        }
        $details = [
            'active_version' => (int) $row['active_snapshot_version'],
            'dirty' => in_array($row['dirty'] ?? false, [true, 1, '1', 't', 'true'], true),
            'published_at' => $row['published_at'] === null ? null : (int) $row['published_at'],
            'dirty_at' => $row['dirty_at'] === null ? null : (int) $row['dirty_at'],
        ];
        if ($row['generated_at'] === null) {
            return $this->result('config_snapshot', 'error', 'Active edge configuration row is missing', 'Publish a new edge configuration', '/edge-nodes', $details);
        }
        $payloadBytes = is_string($row['payload_json'] ?? null) ? strlen((string) $row['payload_json']) : 0;
        $maxBytes = max(0, (int) config('cdnlite.edge.config_max_bytes', 1048576));
        $details['active_snapshot_bytes'] = $payloadBytes;
        $details['max_snapshot_bytes'] = $maxBytes;
        if ($maxBytes > 0 && $payloadBytes > $maxBytes) {
            return $this->result('config_snapshot', 'error', 'Active edge configuration is larger than the edge limit', 'Reduce config size or raise CDNLITE_EDGE_CONFIG_MAX_BYTES on core and edge', '/edge-nodes', $details);
        }
        if ($row['last_publish_error'] !== null && (string) $row['last_publish_error'] !== '') {
            $details['last_publish_error'] = (string) $row['last_publish_error'];
            return $this->result('config_snapshot', 'warning', 'Last edge configuration publish failed', 'Review the publish error and publish again', '/edge-nodes', $details);
        }
        if ($details['dirty']) {
            return $this->result('config_snapshot', 'warning', 'Edge configuration has unpublished changes', 'Publish edge configuration when ready', '/edge-nodes', $details);
        }
        return $this->result('config_snapshot', 'ok', 'Active edge configuration is published', null, null, $details);
    }

    private function certificateExpiryCheck(): array
    {
        $now = time();
        $stmt = Database::pdo()->prepare(
            "SELECT domain_id,hostname,not_after FROM ssl_certificates
             WHERE status<>'revoked' AND not_after IS NOT NULL AND not_after<:cutoff
             ORDER BY not_after ASC LIMIT 1"
        );
        $stmt->execute([':cutoff' => $now + 14 * 86400]);
        $certificate = $stmt->fetch();
        if (!$certificate) {
            return $this->result('ssl_expiry', 'ok', 'No certificates expire within 14 days');
        }
        return $this->result(
            'ssl_expiry',
            'warning',
            sprintf('Certificate for %s expires within 14 days', (string) $certificate['hostname']),
            'Renew the certificate or verify automatic renewal',
            '/domains/' . rawurlencode((string) $certificate['domain_id']) . '/ssl'
        );
    }

    private function originHealthCheck(): array
    {
        $stmt = Database::pdo()->query(
            "SELECT COUNT(*) FROM domain_origins
             WHERE enabled=true AND health_check_enabled=true AND health_status='unhealthy'"
        );
        $unhealthy = (int) $stmt->fetchColumn();
        if ($unhealthy === 0) {
            return $this->result('origin_health', 'ok', 'All enabled origins are healthy or waiting for checks');
        }
        return $this->result(
            'origin_health',
            'warning',
            sprintf('%d enabled origin%s unhealthy', $unhealthy, $unhealthy === 1 ? ' is' : 's are'),
            'Check the origin or review the origin pool',
            '/domains'
        );
    }

    private function result(string $key, string $status, string $message, ?string $fix = null, ?string $link = null, array $details = []): array
    {
        $result = ['key' => $key, 'status' => $status, 'message' => $message];
        if ($fix !== null) {
            $result['fix'] = $fix;
        }
        if ($link !== null) {
            $result['link'] = $link;
        }
        if ($details !== []) {
            $result['details'] = $details;
        }
        return $result;
    }

    private function groupStatus(array $checks): string
    {
        $statuses = array_column($checks, 'status');
        if (in_array('error', $statuses, true)) {
            return 'error';
        }
        return in_array('warning', $statuses, true) ? 'warning' : 'ok';
    }
}
