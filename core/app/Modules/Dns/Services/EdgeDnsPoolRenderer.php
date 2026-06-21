<?php

namespace App\Modules\Dns\Services;

use App\Support\Database;

class EdgeDnsPoolRenderer
{
    public function __construct(
        private EdgeHealthRecordBuilder $health = new EdgeHealthRecordBuilder()
    ) {
    }

    public function pool(): array
    {
        $rows = Database::pdo()->query(
            'SELECT * FROM edge_state ORDER BY anycast DESC, region ASC, edge_id ASC, ip_family ASC, ip ASC'
        )->fetchAll();
        $nodes = [];
        $warnings = [];
        $anycast = ['ipv4' => [], 'ipv6' => []];
        $unicast = ['ipv4' => [], 'ipv6' => []];
        $seen = ['anycast' => ['ipv4' => [], 'ipv6' => []], 'unicast' => ['ipv4' => [], 'ipv6' => []]];

        foreach ($rows as $row) {
            if (!(bool) $row['healthy']) {
                $warnings[] = ['edge_id' => (string) $row['edge_id'], 'error' => 'edge_not_healthy'];
                continue;
            }
            $ip = trim((string) $row['ip']);
            $family = (string) $row['ip_family'] === 'AAAA' ? 'ipv6' : 'ipv4';
            $bucket = (bool) $row['anycast'] ? 'anycast' : 'unicast';
            if (!isset($seen[$bucket][$family][$ip])) {
                $seen[$bucket][$family][$ip] = true;
                $target = [
                    'ip' => $ip,
                    'country' => (string) $row['country'],
                    'continent' => (string) $row['continent'],
                ];
                if ($bucket === 'anycast') {
                    $anycast[$family][] = $target;
                } else {
                    $unicast[$family][] = $target;
                }
            }
            $nodes[] = [
                'edge_id' => (string) $row['edge_id'],
                'ip' => $ip,
                'ip_family' => (string) $row['ip_family'],
                'region' => (string) $row['region'],
                'country' => (string) $row['country'],
                'continent' => (string) $row['continent'],
                'anycast' => (bool) $row['anycast'],
                'healthy' => true,
                'last_check_at' => (int) $row['last_check_at'],
            ];
        }

        return [
            'nodes' => $nodes,
            'warnings' => $warnings,
            'anycast' => $anycast,
            'unicast' => $unicast,
        ];
    }

    public function luaRecord(string $type): ?string
    {
        $family = strtoupper($type) === 'AAAA' ? 'ipv6' : 'ipv4';
        $pool = $this->pool();
        $targets = array_merge($pool['anycast'][$family], $pool['unicast'][$family]);
        return $this->health->luaRecord($type, $targets);
    }

    public function luaRecords(): array
    {
        $records = [];
        foreach (['A', 'AAAA'] as $type) {
            $content = $this->luaRecord($type);
            if ($content !== null) {
                $records[$type] = $content;
            }
        }
        return $records;
    }

    public function stateHash(): string
    {
        return hash('sha256', json_encode($this->pool()['nodes'], JSON_UNESCAPED_SLASHES) ?: '[]');
    }
}
