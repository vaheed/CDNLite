<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $domain = (array) $this->resource;

        return [
            'id' => (string) $domain['id'],
            'user_id' => (string) $domain['user_id'],
            'name' => (string) $domain['name'],
            'domain' => (string) $domain['domain'],
            'status' => (string) $domain['status'],
            'origin_shield_header_name' => $domain['origin_shield_header_name'] ?? null,
            'nameserver_status' => (string) ($domain['nameserver_status'] ?? 'unknown'),
            'verification_token' => $domain['verification_token'] ?? null,
            'last_ns_check_at' => $domain['last_ns_check_at'] ?? null,
            'powerdns_zone_created' => (bool) ($domain['powerdns_zone_created'] ?? false),
            'created_at' => (int) $domain['created_at'],
            'updated_at' => (int) $domain['updated_at'],
            'nameservers' => $domain['nameservers'] ?? [],
        ];
    }
}
