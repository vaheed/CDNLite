# CLI Reference

[Back to docs index](index.md)

Run inside Compose as `docker compose exec core php artisan <command>`. From the host, use `php core/artisan <command>` only when PHP can reach PostgreSQL and has `pdo_pgsql`.

Options are parsed only as `--key=value` or bare `--flag`; there is no short-option parser.

## Command List

```bash
php core/artisan list
```

Registered commands include `cdn:ssl:renew-due`, which renews eligible ACME certificates.

Commands output JSON by default. Add `--format=table` to print a human-readable table for list and object payloads.

### cdn:ssl:renew-due

Attempts renewal for non-revoked ACME certificates whose `renewal_due_at` is within 14 days and whose domain has auto-renew enabled.

```bash
docker compose exec core php artisan cdn:ssl:renew-due
```

The command exits non-zero when any attempted renewal fails.

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

### cdn:origins:list

Purpose: list primary and backup origins for a domain. Equivalent API: `GET /api/v1/domains/{domainId}/origins`.

Required: `--domain_id`.

```bash
php core/artisan cdn:origins:list --domain_id=11111111-1111-4111-8111-111111111111
```

### cdn:origins:health-check

Purpose: run due origin health checks. Compose runs this command every 30 seconds through the `origin-health-scheduler` service.

```bash
php core/artisan cdn:origins:health-check
```

### cdn:domain:list

Purpose: list domains. Equivalent API: `GET /api/v1/domains`.

```json
{"data":[{"id":"11111111-1111-4111-8111-111111111111","domain":"demo.local"}]}
```

### cdn:domain:show

Purpose: show one domain. Equivalent API: `GET /api/v1/domains/{id}`.

Required: `--id`.

### cdn:domain:activate

Purpose: mark a domain active after nameserver verification.

Required: `--id`. Optional: `--force` to bypass the nameserver verification guard.

### cdn:domain:verify-ns

Purpose: run nameserver verification for a domain.

Required: `--id`.

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

Alias: `cdn:dns:create`.

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

Alias: `cdn:dns:list`.

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

Required: `--domain_id`, `--record_id` or `--id`. Equivalent API: `DELETE /api/v1/domains/{id}/dns/records/{recordId}`.

Alias: `cdn:dns:delete`.

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

Reprojects customer DNS records. Standard proxied apex records publish healthy edge A/AAAA rrsets; proxied subdomains publish canonical CNAME targets.

```bash
php core/artisan cdn:dns:rebuild-customer-zones
```

### cdn:dns:validate-routing

Prints edge base-domain settings, active edge nodes, generated edge hostnames, customer public targets, and invalid edge-node state.

```bash
php core/artisan cdn:dns:validate-routing
```

## Header Rule Commands

### cdn:header:create

Equivalent API: `POST /api/v1/domains/{id}/headers`.

Required: `--domain_id`, `--operation=set|append|remove`, `--header_name`. Required for `set` and `append`: `--header_value`.

Optional: `--enabled=0|1`, `--priority=100`, `--path_pattern=/*`.

```bash
php core/artisan cdn:header:create --domain_id=11111111-1111-4111-8111-111111111111 --operation=set --header_name=Strict-Transport-Security --header_value='max-age=31536000; includeSubDomains'
```

### cdn:header:list

Required: `--domain_id`. Equivalent API: `GET /api/v1/domains/{id}/headers`.

### cdn:header:update

Required: `--domain_id`, `--id`, plus at least one update option.

Optional update fields: `--enabled=0|1`, `--operation`, `--header_name`, `--header_value`, `--path_pattern`, `--priority`.

### cdn:header:delete

Required: `--domain_id`, `--id`. Equivalent API: `DELETE /api/v1/domains/{id}/headers/{ruleId}`.

## IP Access Commands

### cdn:ip-rule:create

Equivalent API: `POST /api/v1/domains/{id}/ip-rules`.

Required: `--domain_id`, `--rule_type=allow|block`, `--cidr=<ipv4-cidr>`.

Optional: `--enabled=0|1`, `--description`.

```bash
php core/artisan cdn:ip-rule:create --domain_id=11111111-1111-4111-8111-111111111111 --rule_type=block --cidr=192.0.2.0/24
```

### cdn:ip-rule:list

Required: `--domain_id`. Equivalent API: `GET /api/v1/domains/{id}/ip-rules`.

### cdn:ip-rule:update

Required: `--domain_id`, `--id`, plus at least one update option.

Optional update fields: `--enabled=0|1`, `--rule_type`, `--cidr`, `--description`.

### cdn:ip-rule:delete

Required: `--domain_id`, `--id`. Equivalent API: `DELETE /api/v1/domains/{id}/ip-rules/{ruleId}`.

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

### cdn:edge:show

Purpose: show one edge node by database `id` or `edge_id`.

Required: `--id`.

### cdn:edge:disable

Purpose: disable one edge node by database `id` or `edge_id`.

Required: `--id`.

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

Alias: `cdn:analytics:summary`.

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

## Settings Commands

### cdn:settings:get

Purpose: print all settings or one settings group.

Optional: `--group=platform.powerdns`.

### cdn:settings:set

Purpose: set one platform setting.

Required: `--key=<group.name>`, `--value=<json-or-string>`.

### cdn:settings:test-powerdns

Purpose: run the PowerDNS health check and print the result.

## Cache Commands

### cdn:cache:purge

Purpose: create a cache purge request.

Required: `--domain_id`, `--type=all|url|prefix`. Required for `url` and `prefix`: `--value`.

### cdn:cache:settings

Purpose: print domain cache settings.

Required: `--domain_id`.

## SSL Commands

### cdn:ssl:list

Purpose: list SSL certificates for a domain.

Required: `--domain_id`.

### cdn:ssl:request

Purpose: request SSL certificate provisioning.

Required: `--domain_id`. Optional: `--hostnames=example.com,www.example.com`.

## Readiness And Bootstrap Commands

### cdn:readiness:check

Purpose: print the same readiness payload returned by `GET /api/v1/readiness`.

### cdn:db:fresh

Purpose: drop and recreate the configured database schema.

Required: `--force`.

### cdn:bootstrap:fresh

Purpose: confirm fresh bootstrap mode. The current `dev` setting seed is installed by schema defaults.

Optional: `--seed-settings=dev`.
