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
        $coreChecks = [$this->postgresCheck(), $this->powerDnsConfigCheck(), $this->powerDnsReachabilityCheck(), $this->snapshotCheck(), $this->certificateExpiryCheck(), $this->originHealthCheck()];
        $edgeChecks = [$this->heartbeatCheck(), $this->identityCheck()];

        $powerDns = [
            'enabled' => $this->powerDns->isEnabled(),
            'configured' => $this->powerDns->isConfigured(),
            'api' => $this->powerDns->isEnabled() ? $this->powerDns->healthCheck() : ['ok' => true, 'disabled' => true],
            'sync' => (new DnsSyncStateService())->summary(),
        ];
        return [
            'core' => ['status' => $this->groupStatus($coreChecks), 'checks' => $coreChecks],
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
        $generatedAt = Database::pdo()->query('SELECT MAX(generated_at) FROM config_snapshots')->fetchColumn();
        if ($generatedAt === false || $generatedAt === null) {
            return $this->result('config_snapshot', 'warning', 'No config snapshot has been generated', 'Generate or pull the edge configuration', '/config-snapshot');
        }
        $maxAge = max(60, (int) (getenv('CDNLITE_READINESS_SNAPSHOT_MAX_AGE_SECONDS') ?: 900));
        $age = max(0, time() - (int) $generatedAt);
        if ($age <= $maxAge) {
            return $this->result('config_snapshot', 'ok', 'Config snapshot is fresh');
        }
        return $this->result('config_snapshot', 'warning', sprintf('Config snapshot is %d minutes old', (int) floor($age / 60)), 'Review config generation and edge pulls', '/config-snapshot');
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
            "SELECT o.domain_id,d.domain,o.host,o.last_error
             FROM domain_origins o JOIN domains d ON d.id=o.domain_id
             WHERE o.enabled=true AND o.is_primary=true AND o.health_status='unhealthy'
             ORDER BY o.last_check_at DESC NULLS LAST LIMIT 1"
        );
        $origin = $stmt->fetch();
        if (!$origin) {
            return $this->result('origin_health', 'ok', 'Primary origins are healthy or pending first check');
        }
        return $this->result(
            'origin_health',
            'warning',
            sprintf('Primary origin %s for %s is unhealthy', (string) $origin['host'], (string) $origin['domain']),
            'Check the origin or configure a backup origin',
            '/domains/' . rawurlencode((string) $origin['domain_id']) . '/origins'
        );
    }

    private function result(string $key, string $status, string $message, ?string $fix = null, ?string $link = null): array
    {
        $result = ['key' => $key, 'status' => $status, 'message' => $message];
        if ($fix !== null) {
            $result['fix'] = $fix;
        }
        if ($link !== null) {
            $result['link'] = $link;
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
