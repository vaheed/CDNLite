# CLI Reference

[Back to docs index](index.md)

Run inside Compose as `docker compose exec core php artisan <command>`. From the host, use `php core/artisan <command>` only when PHP can reach PostgreSQL and has `pdo_pgsql`.

Options are parsed only as `--key=value` or bare `--flag`; there is no short-option parser.

## Command List

```bash
php core/artisan list
```

Registered commands: `cdn:site:create`, `cdn:site:list`, `cdn:site:update`, `cdn:site:delete`, `cdn:dns:add-record`, `cdn:dns:list-records`, `cdn:dns:update-record`, `cdn:dns:delete-record`, `cdn:dns:bootstrap-edge-domain`, `cdn:dns:sync-edge-domain`, `cdn:dns:rebuild-customer-zones`, `cdn:dns:validate-routing`, `cdn:redirect:create`, `cdn:redirect:list`, `cdn:redirect:update`, `cdn:redirect:delete`, `cdn:edge:list`, `cdn:edge:register-token`, `cdn:edge:rotate-token`, `cdn:edge:sync-config`, `cdn:usage:ingest`, `cdn:usage:summary`, `cdn:usage:recalculate`.

CI release validation uses `./ci/release_check.sh`, which runs `./ci/smoke.sh` then `./ci/e2e.sh` in sequence.

Help output is available with:

```bash
php core/artisan help
php core/artisan --help
```

Example output:

```text
Usage: php artisan <command> [--key=value]

Commands:
  cdn:site:create
  cdn:site:list
```

## Site Commands

### cdn:site:create

Purpose: create a site. Equivalent API: `POST /api/v1/sites`.

Required: `--name`, `--domain`, `--origin_host`.

Optional: `--origin_port=8080`, `--origin_scheme=http`, `--geo_origins_json='{...}'`, `--proxy_enabled=1`, `--user_id=<id>`.

```bash
php core/artisan cdn:site:create --name=Demo --domain=demo.local --origin_host=core --origin_port=8080
```

Example output:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"Demo","domain":"demo.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}}
```

Common error: `Missing --name`, `Missing --domain`, or `Missing --origin_host` on stderr with exit code 1.

### cdn:site:list

Purpose: list sites. Equivalent API: `GET /api/v1/sites`.

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","domain":"demo.local"}]}
```

### cdn:site:update

Purpose: patch a site. Equivalent API: `PATCH /api/v1/sites/{id}`.

Required: `--id`.

Optional: `--name`, `--domain`, `--origin_scheme`, `--origin_host`, `--origin_port`, `--geo_origins_json`, `--proxy_enabled=0|1`, `--status`.

```bash
php core/artisan cdn:site:update --id=11111111-1111-4111-8111-111111111111 --name=Updated --proxy_enabled=0
```

Common errors: `Missing --id`, `Site not found`.

### cdn:site:delete

Purpose: delete a site. Equivalent API: `DELETE /api/v1/sites/{id}`.

Required: `--id`.

Success: `{"ok":true}`. Common errors: `Missing --id`, `Site not found`.

## DNS Commands

### cdn:dns:add-record

Equivalent API: `POST /api/v1/sites/{id}/dns/records`.

Required: `--site_id`, `--type`, `--name`, `--content`.

Optional: `--ttl=300`, `--priority`, `--proxied=0|1`, `--geo_policy_id=<id>`, `--edge_target=<hostname>`.

```bash
php core/artisan cdn:dns:add-record --site_id=11111111-1111-4111-8111-111111111111 --type=A --name=@ --content=127.0.0.1 --proxied=1
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active"}}
```

Common errors: `Missing --type`, `Missing --name`, `Missing --content`, `Missing --site_id`, `site_not_found`.

### cdn:dns:list-records

Required: `--site_id`. Equivalent API: `GET /api/v1/sites/{id}/dns/records`.

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","type":"A","name":"@"}]}
```

### cdn:dns:update-record

Required: `--site_id`, `--record_id`, plus at least one update option. Equivalent API: `PATCH /api/v1/sites/{id}/dns/records/{recordId}`.

Optional update fields: `--type`, `--name`, `--content`, `--ttl`, `--priority`, `--proxied=0|1`, `--geo_policy_id`, `--edge_target`, `--status`.

```bash
php core/artisan cdn:dns:update-record --site_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222 --content=127.0.0.2 --ttl=120
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","site_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","ttl":120,"priority":null,"proxied":true,"status":"active"}}
```

Common errors: `Missing --site_id or --record_id`, `Missing update options`, `Record not found`.

### cdn:dns:delete-record

Required: `--site_id`, `--record_id`. Equivalent API: `DELETE /api/v1/sites/{id}/dns/records/{recordId}`.

Success: `{"ok":true}`. Common error: `Missing --site_id or --record_id`, `Record not found`.

### cdn:dns:bootstrap-edge-domain

Ensures the CDNLite-owned edge base zone exists, writes SOA/NS and optional `ns1`/`ns2` A records, then generates platform edge LUA records.

```bash
php core/artisan cdn:dns:bootstrap-edge-domain
```

### cdn:dns:sync-edge-domain

Recomputes edge records from `edge_nodes` and updates only the platform edge base zone.

```bash
php core/artisan cdn:dns:sync-edge-domain
```

### cdn:dns:rebuild-customer-zones

Reprojects customer DNS records with the CNAME/ALIAS design. This does not write edge IPs or customer LUA records.

```bash
php core/artisan cdn:dns:rebuild-customer-zones
```

### cdn:dns:validate-routing

Prints edge base-domain settings, active edge nodes, generated edge hostnames, customer public targets, and invalid edge-node state.

```bash
php core/artisan cdn:dns:validate-routing
```

## Edge Commands

## Redirect Commands

### cdn:redirect:create

Equivalent API: `POST /api/v1/sites/{id}/redirects`.

Required: `--site_id`, `--source_path`, `--target_url`.

Optional: `--enabled=0|1`, `--status_code=301|302|307|308` (default `302`).

### cdn:redirect:list

Equivalent API: `GET /api/v1/sites/{id}/redirects`.

Required: `--site_id`.

### cdn:redirect:update

Equivalent API: `PATCH /api/v1/sites/{id}/redirects/{redirectId}`.

Required: `--site_id`, `--id`.

Optional update fields: `--enabled=0|1`, `--source_path`, `--target_url`, `--status_code=301|302|307|308`.

### cdn:redirect:delete

Equivalent API: `DELETE /api/v1/sites/{id}/redirects/{redirectId}`.

Required: `--site_id`, `--id`.

Success: `{"ok":true}`.

### cdn:edge:list

Equivalent API: `GET /api/v1/edge/nodes`.

```json
{"data":[{"edge_id":"edge-local-1","status":"online"}]}
```

### cdn:edge:register-token

Purpose: create or replace the bcrypt token hash for an edge ID. There is no public API equivalent.

Required: `--edge_id`, `--token`.

```bash
php core/artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
```

```json
{"ok":true,"edge_id":"edge-local-1"}
```

### cdn:edge:rotate-token

Purpose: generate a new random 16-byte hex token and store its hash.

Required: `--edge_id`.

```json
{"ok":true,"edge_id":"edge-local-1","token":"0123456789abcdef0123456789abcdef","rotated_at":1710000000}
```

The returned token is shown once; save it in the agent environment.

### cdn:edge:sync-config

Purpose: build a config snapshot. Equivalent API: `GET /api/v1/edge/config` without HTTP auth because it runs locally.

Optional: `--if_version=<int>`.

```json
{"version":1,"generated_at":1710000000,"hosts":{"demo.local":{"site_id":"11111111-1111-4111-8111-111111111111","upstream":"http://core:8080","geo_upstreams":{},"headers":{"X-CDNLITE-Site":"11111111-1111-4111-8111-111111111111"},"dns_records":[]}},"cache_rules":[{"id":"44444444-4444-4444-8444-444444444444","site_id":"11111111-1111-4111-8111-111111111111","enabled":true,"path_prefix":"/api/v1/sites","ttl_seconds":60,"created_at":1710000000,"updated_at":1710000000,"host":"demo.local"}]}
```

Unchanged `--if_version=1` can return `{"not_modified":true,"version":1}`.

## Usage Commands

### cdn:usage:ingest

Equivalent API: `POST /api/v1/collector/usage` without HTTP edge auth because it runs locally.

Required: `--site_id`, `--edge_node_id`, `--requests_count`, `--bytes_in`, `--bytes_out`, `--status`.

Optional: `--ts=<unix>`, `--idempotency_key=<key>`.

```bash
php core/artisan cdn:usage:ingest --site_id=11111111-1111-4111-8111-111111111111 --edge_node_id=edge-local-1 --requests_count=10 --bytes_in=1000 --bytes_out=5000 --status=200 --idempotency_key=batch-1
```

```json
{"ingested":1,"duplicate":false,"idempotency_key":"batch-1"}
```

### cdn:usage:summary

Equivalent API: `GET /api/v1/usage/summary`.

Optional: `--site_id`, `--bucket=minute|hour|day`.

```json
{"data":{"requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

### cdn:usage:recalculate

Equivalent API: `POST /api/v1/usage/recalculate`.

Optional: `--site_id`.

```json
{"ok":true,"site_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1},"summary":{"requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"aggregates":{"minute":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"hour":{"bucket":"hour","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"day":{"bucket":"day","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}}
```
