<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class DnsPublishingPlanner
{
    public function settings(string $domainId): ?array
    {
        $domain = $this->domain($domainId);
        if ($domain === null) {
            return null;
        }

        $stmt = Database::pdo()->prepare('SELECT * FROM domain_routing_settings WHERE domain_id = :domain_id LIMIT 1');
        $stmt->execute(['domain_id' => $domainId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $now = time();
            Database::pdo()->prepare(
                "INSERT INTO domain_routing_settings
                 (domain_id, routing_mode, geo_health_port, geo_selector, created_at, updated_at)
                 VALUES (:domain_id, 'geo', 443, 'pickclosest', :created_at, :updated_at)"
            )->execute(['domain_id' => $domainId, 'created_at' => $now, 'updated_at' => $now]);
            return $this->settings($domainId);
        }

        return $this->castSettings((array) $row);
    }

    public function updateSettings(string $domainId, array $input): ?array
    {
        $current = $this->settings($domainId);
        if ($current === null) {
            return null;
        }
        $next = array_merge($current, $input);
        $stmt = Database::pdo()->prepare(
            'UPDATE domain_routing_settings SET routing_mode = :routing_mode, geo_health_port = :geo_health_port,
             geo_selector = :geo_selector, anycast_ipv4 = :anycast_ipv4, anycast_ipv6 = :anycast_ipv6,
             anycast_cname = :anycast_cname, updated_at = :updated_at WHERE domain_id = :domain_id'
        );
        $stmt->execute([
            'domain_id' => $domainId,
            'routing_mode' => (string) $next['routing_mode'],
            'geo_health_port' => (int) $next['geo_health_port'],
            'geo_selector' => (string) $next['geo_selector'],
            'anycast_ipv4' => $this->nullable($next['anycast_ipv4'] ?? null),
            'anycast_ipv6' => $this->nullable($next['anycast_ipv6'] ?? null),
            'anycast_cname' => $this->nullable($next['anycast_cname'] ?? null),
            'updated_at' => time(),
        ]);
        return $this->settings($domainId);
    }

    public function plan(array $domain, array $record, ?array $settings = null): array
    {
        $origin = [
            'type' => strtoupper((string) ($record['origin_type'] ?? $record['type'])),
            'content' => (string) ($record['origin_content'] ?? $record['content']),
        ];
        $settings ??= $this->settings((string) $domain['id']);
        $mode = (string) ($settings['routing_mode'] ?? 'geo');
        if (($record['proxied'] ?? false) !== true || $mode === 'dns_only') {
            return $this->result($origin['type'], $origin['content'], $mode);
        }

        // For proxied records (except dns_only which we handled above), use ALIAS for apex and CNAME for non-apex
        $isApex = $this->isApex((string) $record['name'], (string) $domain['domain']);
        $baseDomain = rtrim(strtolower((string) (getenv('CDNLITE_EDGE_BASE_DOMAIN') ?: 'vaheed.net')), '.');
        $zonePrefix = strtolower((string) (getenv('CDNLITE_EDGE_ZONE_PREFIX') ?: 'edge'));
        $geoTarget = 'geo.' . $zonePrefix . '.' . $baseDomain . '.';

        if ($isApex) {
            // Apex records use ALIAS when proxied
            return $this->result('ALIAS', $geoTarget, $mode);
        }
        return $this->result('CNAME', $geoTarget, $mode);
    }

    private function result(string $type, string $content, string $mode, ?string $warning = null): array
    {
        return [
            'type' => $type,
            'content' => $content,
            'routing_mode' => $mode,
            'powerdns' => $type . ' ' . $content,
            'warning' => $warning,
        ];
    }

    private function activeEdgeIps(string $type): array
    {
        $column = $type === 'AAAA' ? 'public_ipv6' : 'COALESCE(NULLIF(public_ipv4, \'\'), public_ip)';
        $stmt = Database::pdo()->query(
            "SELECT {$column} AS ip FROM edge_nodes WHERE status = 'online' AND is_enabled = true ORDER BY ip"
        );
        $flag = $type === 'AAAA' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        return array_values(array_filter(array_map(
            static fn(array $row): string => trim((string) ($row['ip'] ?? '')),
            $stmt->fetchAll()
        ), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, $flag) !== false));
    }

    private function domain(string $domainId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT id, domain FROM domains WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $domainId]);
        $row = $stmt->fetch();
        return $row === false ? null : (array) $row;
    }

    private function castSettings(array $row): array
    {
        $row['geo_health_port'] = (int) $row['geo_health_port'];
        $row['created_at'] = (int) $row['created_at'];
        $row['updated_at'] = (int) $row['updated_at'];
        return $row;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function isApex(string $name, string $domain): bool
    {
        $name = strtolower(rtrim(trim($name), '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));
        return $name === '' || $name === '@' || $name === $domain;
    }
}
