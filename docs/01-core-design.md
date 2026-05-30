# Core Design (Laravel)

## Module layout
app/Modules/
- Core
- Sites
- Dns
- Edge
- Proxy
- Collector

Per module:
- routes/api.php
- Http/Controllers
- Services
- DTOs or FormRequests
- Policies (as needed)
- Models (or shared in app/Models)
- database/migrations (module-owned)
- Console/Commands
- config/module.php
- tests/Feature and tests/Unit

## Database tables (v1)

### sites
- id (uuid or bigint)
- user_id
- name
- domain (unique)
- origin_scheme (http|https)
- origin_host
- origin_port
- origin_path_prefix (nullable)
- proxy_enabled (bool)
- status (active|disabled|pending_verification)
- created_at, updated_at

### site_verifications
- id
- site_id
- method (dns_txt)
- token
- verified_at (nullable)
- expires_at
- created_at, updated_at

### dns_zones
- id
- site_id
- provider (powerdns)
- zone_name
- provider_zone_id (nullable)
- status
- last_synced_at (nullable)
- created_at, updated_at

### dns_records
- id
- site_id
- zone_id
- type (A|AAAA|CNAME|TXT|MX)
- name
- content
- ttl
- priority (nullable)
- proxied (bool)
- status
- provider_record_ref (nullable)
- created_at, updated_at

### edge_nodes
- id (uuid)
- hostname
- public_ip
- region
- version
- status (online|offline|degraded)
- auth_token_hash
- last_heartbeat_at (nullable)
- last_seen_ip (nullable)
- metadata_json (nullable)
- created_at, updated_at

### edge_pools
- id
- name
- region
- created_at, updated_at

### edge_pool_nodes
- id
- edge_pool_id
- edge_node_id
- weight
- created_at, updated_at

### config_snapshots
- id
- version (monotonic bigint)
- checksum
- payload_json
- created_by
- created_at

### edge_node_configs
- id
- edge_node_id
- snapshot_id
- assigned_at

### usage_rollups
- id
- bucket_start (datetime)
- bucket_granularity (minute|hour|day)
- site_id
- user_id
- edge_node_id
- requests_count
- bytes_in
- bytes_out
- status_1xx
- status_2xx
- status_3xx
- status_4xx
- status_5xx
- cache_hits
- cache_misses
- created_at, updated_at

### usage_ingest_events (optional audit)
- id
- edge_node_id
- idempotency_key
- received_at
- payload_json
- validation_error (nullable)

## API routes (v1)

Prefix: /api/v1

Sites
- POST /sites
- GET /sites
- GET /sites/{id}
- PATCH /sites/{id}
- DELETE /sites/{id}
- POST /sites/{id}/proxy/enable
- POST /sites/{id}/proxy/disable

DNS
- POST /sites/{id}/dns/records
- GET /sites/{id}/dns/records
- PATCH /sites/{id}/dns/records/{recordId}
- DELETE /sites/{id}/dns/records/{recordId}

Edge
- POST /edge/register
- POST /edge/heartbeat
- GET /edge/nodes
- GET /edge/config (edge-auth)

Collector
- POST /collector/usage
- GET /usage/summary
- GET /usage/timeseries

## Artisan commands (v1)
- php artisan cdn:site:create
- php artisan cdn:site:list
- php artisan cdn:site:update
- php artisan cdn:site:delete
- php artisan cdn:dns:add-record
- php artisan cdn:dns:list-records
- php artisan cdn:dns:delete-record
- php artisan cdn:edge:list
- php artisan cdn:edge:register-token
- php artisan cdn:edge:sync-config
- php artisan cdn:usage:summary
- php artisan cdn:usage:recalculate

## Security baseline
- Core user API via Laravel Sanctum tokens
- Edge API via separate edge token in Authorization header
- Store edge tokens hashed in DB
- Validate usage payload schema + allowed ranges
- PowerDNS credentials only in core env/config
- Optional config response signature: HMAC(snapshot_json, shared_edge_secret)
