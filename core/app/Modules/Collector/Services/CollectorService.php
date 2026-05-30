<?php

namespace App\Modules\Collector\Services;

use App\Support\Database;

class CollectorService
{
    public function ingest(array $items): int
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO usage_rollups (ts, site_id, edge_node_id, requests_count, bytes_in, bytes_out, status)
             VALUES (:ts, :site_id, :edge_node_id, :requests_count, :bytes_in, :bytes_out, :status)'
        );

        $count = 0;
        foreach ($items as $item) {
            $stmt->execute([
                ':ts' => (int) ($item['ts'] ?? time()),
                ':site_id' => (int) ($item['site_id'] ?? 0),
                ':edge_node_id' => (string) ($item['edge_node_id'] ?? ''),
                ':requests_count' => (int) ($item['requests_count'] ?? 0),
                ':bytes_in' => (int) ($item['bytes_in'] ?? 0),
                ':bytes_out' => (int) ($item['bytes_out'] ?? 0),
                ':status' => (int) ($item['status'] ?? 0),
            ]);
            $count++;
        }

        return $count;
    }

    public function summary(?int $siteId = null): array
    {
        $pdo = Database::pdo();
        if ($siteId !== null) {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(requests_count),0) requests_count,
                        COALESCE(SUM(bytes_in),0) bytes_in,
                        COALESCE(SUM(bytes_out),0) bytes_out,
                        COUNT(*) records
                 FROM usage_rollups WHERE site_id = :site_id'
            );
            $stmt->execute([':site_id' => $siteId]);
        } else {
            $stmt = $pdo->query(
                'SELECT COALESCE(SUM(requests_count),0) requests_count,
                        COALESCE(SUM(bytes_in),0) bytes_in,
                        COALESCE(SUM(bytes_out),0) bytes_out,
                        COUNT(*) records
                 FROM usage_rollups'
            );
        }

        $row = (array) $stmt->fetch();
        return [
            'requests_count' => (int) $row['requests_count'],
            'bytes_in' => (int) $row['bytes_in'],
            'bytes_out' => (int) $row['bytes_out'],
            'records' => (int) $row['records'],
        ];
    }
}
