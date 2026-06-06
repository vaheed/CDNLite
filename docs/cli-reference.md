# CLI Reference

[Back to docs index](index.md)

Run inside Compose as `docker compose exec core php artisan <command>`. From the host, use `php core/artisan <command>` only when PHP can reach PostgreSQL and has `pdo_pgsql`.

Options are parsed only as `--key=value` or bare `--flag`; there is no short-option parser.

## Command List

```bash
php core/artisan list
```

Registered commands: `cdn:admin:create`, `cdn:domain:create`, `cdn:domain:list`, `cdn:domain:update`, `cdn:domain:delete`, `cdn:dns:add-record`, `cdn:dns:list-records`, `cdn:dns:update-record`, `cdn:dns:delete-record`, `cdn:dns:bootstrap-edge-domain`, `cdn:dns:sync-edge-domain`, `cdn:dns:rebuild-customer-zones`, `cdn:dns:validate-routing`, `cdn:redirect:create`, `cdn:redirect:list`, `cdn:redirect:update`, `cdn:redirect:delete`, `cdn:edge:list`, `cdn:edge:register-token`, `cdn:edge:rotate-token`, `cdn:edge:sync-config`, `cdn:usage:ingest`, `cdn:usage:summary`, `cdn:usage:recalculate`, `cdn:migrate`.

### cdn:migrate

Purpose: apply SQL files from `core/database/migrations` in lexicographic order, recording each filename in `schema_migrations` so reruns are idempotent.

```bash
php core/artisan cdn:migrate
```

Example output:

```json
{"ok":true,"applied":2}
```

CI release validation uses `./ci/release_check.sh`, which runs `./ci/smoke.sh` then `./ci/e2e.sh` in sequence.

### cdn:admin:create

Purpose: create or update a dashboard admin user. Passwords are stored as PHP password hashes, not plaintext.

Required: `--username`, `--password`.

Optional: `--display_name`.

```bash
php core/artisan cdn:admin:create --username=admin --password='replace-with-a-long-password'
```

Example output:

```json
{"ok":true,"user":{"id":"11111111-1111-4111-8111-111111111111","username":"admin","status":"active"}}
```

Common errors: `Missing --username or --password`, `password_min_12`.

Help output is available with:

```bash
php core/artisan help
php core/artisan --help
```

Example output:

```text
Usage: php artisan <command> [--key=value]

Commands:
  cdn:domain:create
  cdn:domain:list
```

## Domain Commands

### cdn:domain:create

Purpose: create a domain. Equivalent API: `POST /api/v1/domains`.

Required: `--name`, `--domain`.

Optional: `--user_id=<id>`. Configure origin options with `cdn:dns:add-record`.

For proxied DNS records, use `--origin_host=<host>`, `--origin_tls_verify=verify|ignore`, and `--geo_origins_json='{"IR":{"host":"ir-origin.example.com","tls_verify":"ignore"}}'`. Ports are always autodetected as HTTPS/443 with HTTP/80 fallback.

```bash
php core/artisan cdn:domain:create --name=Demo --domain=demo.local
```

Example output:

```json
{"data":{"id":"11111111-1111-4111-8111-111111111111","name":"Demo","domain":"demo.local","status":"pending_nameserver"}}
```

Common error: `Missing --name` or `Missing --domain` on stderr with exit code 1.

### cdn:domain:list

Purpose: list domains. Equivalent API: `GET /api/v1/domains`.

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","domain":"demo.local"}]}
```

### cdn:domain:update

Purpose: patch a domain. Equivalent API: `PATCH /api/v1/domains/{id}`.

Required: `--id`.

Optional: `--name`, `--domain`, `--status`.

```bash
php core/artisan cdn:domain:update --id=11111111-1111-4111-8111-111111111111 --name=Updated --proxy_enabled=0
```

Common errors: `Missing --id`, `Domain not found`.

### cdn:domain:delete

Purpose: delete a domain. Equivalent API: `DELETE /api/v1/domains/{id}`.

Required: `--id`.

Success: `{"ok":true}`. Common errors: `Missing --id`, `Domain not found`.

## DNS Commands

### cdn:dns:add-record

Equivalent API: `POST /api/v1/domains/{id}/dns/records`.

Required: `--domain_id`, `--type`, `--name`, `--content`.

Optional: `--ttl=300`, `--priority`, `--proxied=0|1`, `--geo_policy_id=<id>`, `--edge_target=<hostname>`.

```bash
php core/artisan cdn:dns:add-record --domain_id=11111111-1111-4111-8111-111111111111 --type=A --name=@ --content=127.0.0.1 --proxied=1
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.1","origin_type":"A","origin_content":"127.0.0.1","public_type":"ALIAS","public_content":"geo.edge.vaheed.net.","ttl":300,"priority":null,"proxied":true,"status":"active"}}
```

Common errors: `Missing --type`, `Missing --name`, `Missing --content`, `Missing --domain_id`, `domain_not_found`.

### cdn:dns:list-records

Required: `--domain_id`. Equivalent API: `GET /api/v1/domains/{id}/dns/records`.

```json
{"data":[{"id":"22222222-2222-4222-8222-222222222222","type":"A","name":"@"}]}
```

### cdn:dns:update-record

Required: `--domain_id`, `--record_id`, plus at least one update option. Equivalent API: `PATCH /api/v1/domains/{id}/dns/records/{recordId}`.

Optional update fields: `--type`, `--name`, `--content`, `--ttl`, `--priority`, `--proxied=0|1`, `--geo_policy_id`, `--edge_target`, `--status`.

```bash
php core/artisan cdn:dns:update-record --domain_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222 --content=127.0.0.2 --ttl=120
```

```json
{"data":{"id":"22222222-2222-4222-8222-222222222222","domain_id":"11111111-1111-4111-8111-111111111111","type":"A","name":"@","content":"127.0.0.2","ttl":120,"priority":null,"proxied":true,"status":"active"}}
```

Common errors: `Missing --domain_id or --record_id`, `Missing update options`, `Record not found`.

### cdn:dns:delete-record

Required: `--domain_id`, `--record_id`. Equivalent API: `DELETE /api/v1/domains/{id}/dns/records/{recordId}`.

Success: `{"ok":true}`. Common error: `Missing --domain_id or --record_id`, `Record not found`.

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

Equivalent API: `POST /api/v1/domains/{id}/redirects`.

Required: `--domain_id`, `--source_path`, `--target_url`.

Optional: `--enabled=0|1`, `--status_code=301|302|307|308` (default `302`).

### cdn:redirect:list

Equivalent API: `GET /api/v1/domains/{id}/redirects`.

Required: `--domain_id`.

### cdn:redirect:update

Equivalent API: `PATCH /api/v1/domains/{id}/redirects/{redirectId}`.

Required: `--domain_id`, `--id`.

Optional update fields: `--enabled=0|1`, `--source_path`, `--target_url`, `--status_code=301|302|307|308`.

### cdn:redirect:delete

Equivalent API: `DELETE /api/v1/domains/{id}/redirects/{redirectId}`.

Required: `--domain_id`, `--id`.

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
{"version":1,"generated_at":1710000000,"hosts":{"demo.local":{"domain_id":"11111111-1111-4111-8111-111111111111","upstream":"http://core:8080","geo_upstreams":{},"headers":{"X-CDNLITE-Domain":"11111111-1111-4111-8111-111111111111"},"dns_records":[]}},"cache_rules":[{"id":"44444444-4444-4444-8444-444444444444","domain_id":"11111111-1111-4111-8111-111111111111","enabled":true,"path_prefix":"/api/v1/domains","ttl_seconds":60,"created_at":1710000000,"updated_at":1710000000,"host":"demo.local"}]}
```

Unchanged `--if_version=1` can return `{"not_modified":true,"version":1}`.

## Usage Commands

### cdn:usage:ingest

Equivalent API: `POST /api/v1/collector/usage` without HTTP edge auth because it runs locally.

Required: `--domain_id`, `--edge_node_id`, `--requests_count`, `--bytes_in`, `--bytes_out`, `--status`.

Optional: `--ts=<unix>`, `--idempotency_key=<key>`, `--cache_status=HIT|MISS|EXPIRED|STALE|BYPASS|UNKNOWN`.

```bash
php core/artisan cdn:usage:ingest --domain_id=11111111-1111-4111-8111-111111111111 --edge_node_id=edge-local-1 --requests_count=10 --bytes_in=1000 --bytes_out=5000 --status=200 --cache_status=HIT --idempotency_key=batch-1
```

```json
{"ingested":1,"duplicate":false,"idempotency_key":"batch-1"}
```

### cdn:usage:summary

Equivalent API: `GET /api/v1/usage/summary`.

Optional: `--domain_id`, `--bucket=minute|hour|day`.

```json
{"data":{"requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

### cdn:usage:recalculate

Equivalent API: `POST /api/v1/usage/recalculate`.

Optional: `--domain_id`.

```json
{"ok":true,"domain_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1},"summary":{"requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"aggregates":{"minute":{"bucket":"minute","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"hour":{"bucket":"hour","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1},"day":{"bucket":"day","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}}
```
