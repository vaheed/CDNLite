---
title: Setup
description: Local development, Docker Compose setup, dashboard development, validation commands, and GitHub Pages documentation build instructions for CDNLite.
---

# Setup

This guide covers local development, dashboard development, production-oriented Compose deployment, validation, and GitHub Pages docs rendering.

## Prerequisites

| Area | Requirement |
| --- | --- |
| OS | Linux or macOS with Docker support. Windows works best through WSL2. |
| Containers | Docker Engine and Docker Compose v2. |
| Backend | PHP 8.4.2 or newer with `pdo_pgsql` for host-side lint/tests. |
| Tests | Python 3.12 and `pytest`. |
| Frontend | Node.js 22 and npm. |
| Optional docs render | Node.js 22 and npm for VitePress local preview/build. |

## Local Stack

```bash
cp .env.example .env
docker compose up -d --build
docker compose ps
```

Default URLs:

| Service | URL |
| --- | --- |
| Core API | `http://localhost:8080` |
| Edge proxy | `http://localhost:8081` |
| Edge TLS proxy | `https://localhost:8443` |
| Dashboard | `http://localhost:8082` |
| PostgreSQL | `localhost:5432` |
| PowerDNS API, loopback only | `http://localhost:8089` |
| PowerDNS authoritative DNS | `127.0.0.1:5353` |
| Poweradmin, loopback only | `http://localhost:8084` |

Health checks:

```bash
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8080/cdn-health
curl -fsS http://localhost:8080/ready
curl -fsS http://localhost:8081/health
```

## Dashboard Login

Fresh installs create a local-only admin through Laravel seeders:

```text
CDNLITE_DEV_ADMIN_USERNAME=admin@example.test
CDNLITE_DEV_ADMIN_PASSWORD=cdnlite-local-admin
```

Create a deliberate admin account for non-local environments after rotating the
seeded password:

```bash
docker compose exec core php artisan cdn:admin:create \
  --username=admin \
  --password='replace-with-a-long-password'
```

Admin maintenance commands:

```bash
docker compose exec core php artisan cdn:admin:list --format=table
docker compose exec core php artisan cdn:admin:password --username=admin --password='replace-with-a-new-long-password'
docker compose exec core php artisan cdn:admin:delete --username=old-admin
```

## Backend Setup

The core image serves Laravel from `core/public/index.php` and CLI commands from
Laravel's `core/artisan`. Fresh installs run Laravel migrations and seeders:

```bash
docker compose exec core php artisan migrate --seed
```

`core/database/schema.sql` is the authoritative fresh-install PostgreSQL schema
loaded by the initial Laravel migration. CDNLite is pre-1.0 and does not ship
old-data upgrade migrations or compatibility shims.

Useful commands:

```bash
docker compose exec core php artisan cdn:scheduler:run --force
docker compose exec core php artisan cdn:dns:reconcile
docker compose exec core php artisan cdn:domains:verify-all
docker compose exec core php artisan cdn:ssl:renew-due
docker compose exec core php artisan cdn:origins:health-check
docker compose exec core php artisan cdn:domain:list
docker compose exec core php artisan migrate --seed
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:edge:list
docker compose exec core php artisan cdn:usage:prune --dry-run
```

Migrations are applied automatically before the Core web process starts when
auto-migration is enabled. Durable DNS state is reconciled after mutations and by the
supervised core scheduler every `CDNLITE_SYNC_INTERVAL_SECONDS` seconds
(default `30`).

Runtime process ownership:

- Nginx owns public HTTP on `:8080` and forwards to PHP-FPM.
- PHP-FPM owns `/health`, `/ready`, and `/api/v1/*` request execution.
- Supervisor owns `php-fpm`, `nginx`, and `cdnlite-scheduler`.
- `cdnlite-scheduler` runs `php /app/artisan cdn:scheduler:run` in a bounded loop.
- There are no standalone `dns-reconciler`, `nameserver-scheduler`,
  `ssl-scheduler`, `origin-health-scheduler`, or `retention-scheduler`
  containers in the root Compose topology.
- `core/artisan` is Laravel's CLI entrypoint. Remaining `cdn:*` commands are
  converted phase-by-phase and should not add new compatibility aliases.

## Horizon Web Panel

CDNLite does not install or run Horizon, and there is no local or production
Horizon dashboard URL. The path `/horizon` is intentionally not served.

| Question | CDNLite answer |
| --- | --- |
| Local access URL | None. There is no `/horizon` panel in the core API container. |
| Production protection | Not applicable because no Horizon route is exposed. Keep Core itself behind authenticated HTTPS or private ingress as usual. |
| Enabled by environment | No Horizon enable flag exists. `QUEUE_CONNECTION`, `CACHE_STORE`, `REDIS_HOST`, and `HORIZON_PREFIX` are reserved compatibility/runtime knobs, but they do not start a Horizon dashboard. |
| Queues monitored | None through Horizon. Current durable work is tracked in PostgreSQL tables and API feeds. |
| Verify Horizon is running | It should not be running. Verify the CDNLite scheduler instead with `docker compose logs core` and `docker compose exec core php artisan cdn:scheduler:run --force`. |

Use these CDNLite-native views instead:

```bash
curl -H "Authorization: Bearer $CDNLITE_API_TOKEN" \
  "$CORE_URL/api/v1/jobs?status=failed"

curl -H "Authorization: Bearer $CDNLITE_API_TOKEN" \
  "$CORE_URL/api/v1/reports/operations"

docker compose exec core php artisan cdn:ssl:list
docker compose exec core php artisan cdn:db:status
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:scheduler:run --force
```

The dashboard Job Queue and Operations views use `/api/v1/jobs` and
`/api/v1/reports/operations` for failed-job timeline, recent jobs, and
operational status. Failed SSL jobs are retried by correcting the reported
cause and submitting a new managed SSL request, or by running:

```bash
docker compose exec core php artisan cdn:ssl:request --domain_id="$DOMAIN_ID"
docker compose exec core php artisan cdn:ssl:renew-due
```

DNS reconciliation failures are visible through readiness, DNS status, and
`dns_sync_state`; retry after fixing PowerDNS/settings with:

```bash
docker compose exec core php artisan cdn:dns:reconcile
```

Analytics aggregate jobs are stored in `analytics_rollup_jobs`. Re-run a
recalculation through the dashboard/API or:

```bash
docker compose exec core php artisan cdn:usage:recalculate
```

If an existing development volume logs a missing `powerdns_zone_serials` table
or Activity shows request country but an unknown client IP, run
`docker compose exec core php artisan cdn:db:migrate`. That applies the runtime
schema reconciliation migration that restores durable PowerDNS SOA serial state
and the Activity `client_ip` diagnostics column.

The supervised core scheduler runs `php artisan cdn:domains:verify-all` every
`CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS` seconds (default `86400`). A verified
domain activates automatically and queues managed ACME DNS-01 SSL for the apex
hostname and wildcard hostname. If its authoritative nameservers later move
away, CDNLite marks it pending and withdraws its DNS records and edge config.
The PowerDNS zone itself is created as soon as the domain is saved and remains
present with only platform `NS` and `SOA` records while nameserver verification
is pending or lost. User DNS records remain stored in Core and are republished
after nameserver verification succeeds again. Domain mutations return after
saving Core state and the scheduled reconciler converges PowerDNS; run
`php artisan cdn:dns:reconcile` when you need to force immediate convergence.
The dashboard domain detail page also has **Refresh nameservers now**, which
does not wait for the scheduler and shows expected, observed, matched, missing,
and resolver error details. Operators logged in with an admin session can use
**Force verify as admin** with a reason; the override is audited, activates the
domain, invalidates edge config, and reconciles DNS.
If you update `platform.nameservers` after domains already exist, use **Re-seed
expected NS** on each affected domain or call
`POST /api/v1/domains/{domainId}/nameservers/reseed-expected` with an admin
session token. This updates expected delegation rows from current settings,
preserves already observed overlaps, invalidates edge config, reconciles DNS,
and writes an audit event without deleting the domain.

Fresh local reset:

```bash
docker compose down -v
docker compose up -d --build
```

For upgrade and backup details, see
[Database Migrations](operations/database-migrations.md).

The root Compose topology does not assign registry tags to locally built CDNLite
services. Core, schedulers, Edge, and the Edge agent are built from the currently
checked-out branch and use Compose project-scoped image names.

## Frontend Setup

For dashboard-only development:

```bash
cd dash
npm ci
npm run dev
```

Open `http://localhost:5173`. The dashboard reads Vite build-time variables such as `VITE_CDNLITE_CORE_URL` and `VITE_CDNLITE_EDGE_URL`; use browser-reachable URLs, not internal Compose names.

Production dashboard image builds happen through the root `docker-compose.yml`:

```bash
docker compose build dashboard
```

## Environment Variables

Core settings:

| Variable | Purpose |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL connection. |
| `APP_ENV`, `APP_DEBUG`, `APP_LOG_ENABLED`, `APP_LOG_LEVEL` | Runtime and logging behavior. |
| `CDNLITE_API_TOKEN` | Optional bearer token for non-edge `/api/v1/*` endpoints. |
| `CDNLITE_CORS_ALLOWED_ORIGINS` | Browser origins allowed to call the API. |
| `CDNLITE_SSL_SECRET_KEY` | Secret used for stored SSL material handling. |
| `CDNLITE_ORIGIN_SHIELD_SECRET` | Default origin shield secret. |
| `CDNLITE_CONFIG_SNAPSHOT_KEEP_LAST` | Count of latest published config snapshots to retain in addition to the active version; default `2`. |
| `CDNLITE_CONFIG_SNAPSHOT_PRUNE_BATCH_SIZE` | Bounded delete batch used after publish and by `cdn:config-snapshots:prune`; default `5000`. |
| `CDNLITE_CONFIG_SNAPSHOT_HISTORY_ENABLED` | Enables legacy snapshot payload, diff, and rollback endpoints for development; default `false`. |
| `CDNLITE_ANALYTICS_RETENTION_DAYS` | Detailed edge request/activity retention window for `cdn:usage:prune`; default `30`. |
| `CDNLITE_SECURITY_EVENT_RETENTION_DAYS` | High-volume WAF, rate-limit, bot, and Geo security-event retention for `cdn:usage:prune --all`; default `90`. |
| `CDNLITE_DNS_EVENT_RETENTION_DAYS` | Successful DNS sync event retention for `cdn:usage:prune --all`; default `30`. Failed DNS sync events are retained for troubleshooting. |
| `CDNLITE_SSL_JOB_RETENTION_DAYS` | Terminal SSL job retention for `cdn:usage:prune --all`; default `180`. Active jobs are never pruned. |
| `CDNLITE_INGEST_KEY_RETENTION_DAYS` | Edge ingest idempotency-key retention for `cdn:usage:prune --all`; default `7`. |
| `CDNLITE_RETENTION_PRUNE_ENABLED` | Enables scheduled retention pruning when set to `true`; default `false` for upgrade safety. |
| `CDNLITE_RETENTION_INTERVAL_SECONDS`, `CDNLITE_RETENTION_BATCH_SIZE` | Retention scheduler interval and bounded delete batch size; defaults `86400` and `5000`. |
| `CDNLITE_STORE_FULL_CLIENT_IP` | Store full client IPs in security-event audit details only when explicitly `true`; default stores a SHA-256 hash. |
| `CDNLITE_ACME_*` | ACME directory, contact email, DNS propagation delay, optional public DNS TXT precheck, and polling for automatic apex and wildcard certificates. |
| `CDNLITE_SSL_JOB_STALE_RETRY_SECONDS` | Age after which an in-progress SSL job can be reclaimed by the scheduler and retried. |
| `CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS` | Seconds between SSL scheduler loops for queued issuance and renewals; default `30`. |
| `CDNLITE_BOOTSTRAP_ADMIN_*` | Local/admin bootstrap behavior. |
| `CDNLITE_BOOTSTRAP_EDGE_*`, `CDNLITE_BOOTSTRAP_EDGE_EXTRA_TOKENS`, `EDGE_ID`, `EDGE_TOKEN`, `EDGE_2_*` | Local edge token bootstrap and the bundled two-edge test topology. |
| `CDNLITE_EDGE_*`, `CDNLITE_GEO_*`, `CDNLITE_NS*` | Edge DNS, health, anycast, and Geo DNS defaults. |
| `PDNS_REPLICATION_PASSWORD` | Password for the TLS-protected PowerDNS PostgreSQL streaming-replication role. |
| `CDNLITE_CDN_ZONE` | Authoritative zone containing stable site targets and the shared proxy record. |
| `CDNLITE_CDN_PROXY_HOST` | Shared proxy hostname, and must be inside `CDNLITE_CDN_ZONE`. |

The recommendation engine has no dedicated environment variables. Run
`php artisan cdn:recommendations:generate` manually or from your scheduler to
refresh proactive security, performance, reliability, and SSL suggestions from
recent telemetry.

Static proxy anycast IPs are configured in the admin dashboard under
Settings -> Edge DNS / Anycast as `anycast_ipv4` and `anycast_ipv6`. These are
database-backed settings, not environment variables. Enter one or more IPs per
family separated by commas, spaces, or new lines. When set, the shared proxy
host publishes plain A/AAAA records containing all configured addresses for the
configured families and bypasses DNSGeo Lua, country routing, and continent
routing for those families.

The DNS initializer creates only the PowerDNS/Poweradmin schemas and service
roles. It does not create sample zones. GeoIP bootstrap uses only the reserved
`geoip-bootstrap.invalid` backend-initialization zone, so Core remains the
owner of all routable authoritative DNS data. See
[DNSGeo and PowerDNS](dns.md).

Edge and agent settings:

| Variable | Purpose |
| --- | --- |
| `CORE_URL` | Agent target core URL, normally `http://core:8080` in Compose. |
| `EDGE_CONFIG_DIR` | Host directory mounted into `/var/lib/cdnlite`. |
| `EDGE_CONFIG_PATH` | Runtime config path, default `/var/lib/cdnlite/config.json`. |
| `EDGE_CONFIG_MAX_STALE_SECONDS` | Maximum acceptable config staleness before edge readiness fails. |
| `METRIC_PATH` | Metrics queue file for the agent. |
| `SECURITY_EVENT_PATH` | Security event queue file for the agent. |
| `CDNLITE_CACHE_DEFAULT_TTL` | Default OpenResty cache TTL. |
| `CDNLITE_EDGE_DEBUG_HEADERS` | Set to `1` to expose sanitized cache key and bypass reason headers during diagnosis. |
| `CDNLITE_EDGE_WORKER_PROCESSES`, `CDNLITE_EDGE_WORKER_CONNECTIONS` | OpenResty worker capacity. Defaults are `auto` and `4096`. |
| `CDNLITE_EDGE_LIMITS_DICT_SIZE`, `CDNLITE_EDGE_REQUEST_CONTEXT_DICT_SIZE`, `CDNLITE_EDGE_METRIC_QUEUE_DICT_SIZE`, `CDNLITE_EDGE_SECURITY_EVENT_QUEUE_DICT_SIZE` | Shared memory budgets for rate limits, request context, metrics, and security-event queues. |
| `CDNLITE_EDGE_WAITING_ROOM_DICT_SIZE`, `CDNLITE_EDGE_WAITING_ROOM_SECRET` | Shared-memory budget and signing secret for local waiting-room queue tickets and admission cookies. Initial queues are local to each edge node and are not globally fair across nodes. |
| `CDNLITE_EDGE_CONFIG_MAX_BYTES`, `CDNLITE_EDGE_CONFIG_REFRESH_SECONDS` | Maximum accepted edge snapshot size and worker config refresh interval. |
| `CDNLITE_EDGE_TELEMETRY_BATCH_SIZE`, `CDNLITE_EDGE_TELEMETRY_FLUSH_INTERVAL_SECONDS`, `CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS`, `CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES` | Bounded edge telemetry queue and flush controls. Drops are counted and visible on `/ready`. |
| `CDNLITE_EDGE_RESOLVER`, `CDNLITE_EDGE_CLIENT_*`, `CDNLITE_EDGE_PROXY_*` | DNS resolver, header/body buffer, request body, and upstream timeout tuning for OpenResty. |
| `CDNLITE_EDGE_LOG_FORMAT` | Edge access log format selector; `json` is the default and writes to stdout. |
| `CDNLITE_EDGE_LOG_LEVEL` | Edge diagnostic log level: `debug`, `info`, `warn`, or `error`; default `info`. |
| `CDNLITE_EDGE_LOG_REQUEST_BODY` | Reserved for future strict-redaction body logging; keep `false`. |
| `CDNLITE_EDGE_DEBUG_HEADERS` | Reserved for future debug header logging; keep `false` unless a runbook explicitly enables it. |
| `CDNLITE_EDGE_MMDB_FILE` | GeoIP MMDB used by the edge for country WAF/origin decisions; default `/var/lib/cdnlite/mmdb/GeoLite2-City.mmdb`. |

Waiting-room shared memory stores rolling counters, queue population, active
origin counts, and short-lived ticket/admission state. Increase
`CDNLITE_EDGE_WAITING_ROOM_DICT_SIZE` only when `/ready`, edge logs, or queue
status indicate sustained local state pressure. Use the dashboard policy fields
to tune traffic behavior first: lower `admission_rate_per_minute` protects
origins more, lower `queue_limit` bounds memory more tightly, and higher polling
jitter spreads queue status checks.
| `CDNLITE_EDGE_CLEARANCE_SECRET` | Shared edge secret for signed challenge and clearance cookies. Set the same strong value on every edge; rotation invalidates existing clearances. |
| `CDNLITE_EDGE_CHALLENGE_DIFFICULTY` | Default self-hosted edge challenge difficulty, from `1` to `6`; default `3`. WAF and rate-limit challenge rules can override this per path or pattern with `challenge_difficulty`. Level `1` performs a lightweight browser check without proof-of-work. Levels `2` through `6` require increasing SHA-256 proof-of-work before origin routing. |
| `EDGE_AGENT_IDLE` | CI flag to keep agent idle while scripts drive flow manually. |

Recommended starting values:

| Variable | Development | Production starting point |
| --- | --- | --- |
| `CDNLITE_EDGE_WORKER_PROCESSES` | `1` | `auto` |
| `CDNLITE_EDGE_WORKER_CONNECTIONS` | `1024` | `8192` |
| `CDNLITE_EDGE_LIMITS_DICT_SIZE` | `10m` | `50m` |
| `CDNLITE_EDGE_REQUEST_CONTEXT_DICT_SIZE` | `5m` | `20m` |
| `CDNLITE_EDGE_METRIC_QUEUE_DICT_SIZE` | `5m` | `32m` |
| `CDNLITE_EDGE_SECURITY_EVENT_QUEUE_DICT_SIZE` | `5m` | `32m` |
| `CDNLITE_EDGE_CONFIG_MAX_BYTES` | `1048576` | `5242880` |
| `CDNLITE_EDGE_CONFIG_REFRESH_SECONDS` | `1` | `1` |
| `CDNLITE_EDGE_TELEMETRY_BATCH_SIZE` | `50` | `500` |
| `CDNLITE_EDGE_TELEMETRY_FLUSH_INTERVAL_SECONDS` | `1` | `1` |
| `CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_ITEMS` | `5000` | `100000` |
| `CDNLITE_EDGE_TELEMETRY_QUEUE_MAX_BYTES` | `524288` | `16777216` |
| `CDNLITE_EDGE_CLIENT_HEADER_BUFFER_SIZE` | `4k` | `8k` |
| `CDNLITE_EDGE_LARGE_CLIENT_HEADER_BUFFERS` | `4 8k` | `8 16k` |
| `CDNLITE_EDGE_CLIENT_BODY_BUFFER_SIZE` | `64k` | `256k` |
| `CDNLITE_EDGE_CLIENT_MAX_BODY_SIZE` | `10m` | `100m` |
| `CDNLITE_EDGE_PROXY_CONNECT_TIMEOUT` | `3s` | `5s` |
| `CDNLITE_EDGE_PROXY_READ_TIMEOUT` | `30s` | `60s` |
| `CDNLITE_EDGE_PROXY_SEND_TIMEOUT` | `30s` | `60s` |

The development profile keeps memory use small and deterministic for local
tests. The production profile is a safe starting point for a single edge host;
increase queue sizes or shared dictionaries only after `/ready` shows drops,
corruptions, or sustained high queue depth under real traffic.

The normal root Compose stack mounts the MMDB updater volume into the edge
container read-only. Standalone edge deployments run their own
`edge-mmdb-updater` sidecar, using the same downloader as DNSGeo, and mount its
database into `/var/lib/cdnlite/mmdb`. Country-based WAF rules and country
origin selection use `X-CDNLITE-Country` or `CF-IPCountry` when a trusted
upstream sets one, otherwise the edge resolves `remote_addr` through the
mounted MMDB.

OpenResty writes edge access logs to stdout and diagnostics to stderr. Runtime
metrics and security events are first stored in bounded shared-memory queues and
then flushed in batches to the existing agent files, so collector or disk
outages do not create unbounded memory growth. Live operations can use:

```bash
docker compose logs -f edge
curl -s http://localhost:8081/ready
docker compose exec edge tail -f /var/lib/cdnlite/metrics.ndjson
```

Access logs include request id, host, method, path, status, selected origin id,
upstream status/time, cache status, and byte counts. Query parameters with names
such as `token`, `key`, `secret`, `password`, `auth`, or `signature` are
redacted in structured diagnostics and metrics.

Detailed request/activity rows are retained until an operator prunes them. Run
a dry run first, then prune rows older than `CDNLITE_ANALYTICS_RETENTION_DAYS`
or an explicit `--days` value:

```bash
docker compose exec core php artisan cdn:usage:prune --dry-run
docker compose exec core php artisan cdn:usage:prune --days=30
```

Config snapshots are a published edge cache, not the source of truth. The core
keeps the active snapshot plus the latest two snapshots by default and prunes
after successful publishes. Operators with a large historical table can prune in
batches without deleting the active snapshot:

```bash
docker compose exec core php artisan cdn:config-snapshots:prune --keep=2 --batch=5000 --dry-run
docker compose exec core php artisan cdn:config-snapshots:prune --keep=2 --batch=5000
```

Use the wider retention pass for high-volume operational rows after reviewing
the dry run. It prunes raw request rows, high-volume security events, successful
DNS sync events, terminal SSL jobs, expired edge nonces, and old ingest
idempotency keys in bounded batches:

```bash
docker compose exec core php artisan cdn:usage:prune --all --dry-run
docker compose exec core php artisan cdn:usage:prune --all
```

Scheduled retention pruning ships disabled by default. Set
`CDNLITE_RETENTION_PRUNE_ENABLED=true` only after confirming the dry-run counts
match your operational policy. Longer-term rollups and dashboard summaries
should use aggregate views and exports, not indefinite raw request retention.
Security-event ingest hashes client IPs by default; set
`CDNLITE_STORE_FULL_CLIENT_IP=true` only when your privacy policy and retention
process explicitly allow it.

Dashboard variables:

| Variable | Purpose |
| --- | --- |
| `VITE_CDNLITE_CORE_URL` | Browser URL for core. |
| `VITE_CDNLITE_EDGE_URL` | Browser URL for edge. |
| `VITE_CDNLITE_APP_NAME` | Dashboard name. |
| `VITE_CDNLITE_API_TOKEN` | Optional local/private API token compiled into assets. |
| `TELEMETRY_MAX_BATCH_ITEMS` | Maximum collector items per telemetry request; default `1000`. |
| `TELEMETRY_MAX_PAYLOAD_BYTES` | Maximum collector telemetry payload size; default `1048576`. |
| `CDNLITE_SCHEDULER_TICK_SECONDS` | Supervisor scheduler loop sleep between `cdn:scheduler:run` passes; default `60`. |
| `VITE_ENABLE_EDGE_DEV_TOOLS` | Enables signed edge request tools. |
| `VITE_ENABLE_USAGE_SIMULATOR` | Enables usage simulation tools. |
| `VITE_ENABLE_SSL_TOOLS` | Shows SSL tooling. |
| `VITE_ENABLE_SECURITY_EVENT_VIEWER` | Shows security event screens. |
| `VITE_ENABLE_LOG_VIEWER` | Shows event/log viewer. |

## PowerDNS Operations

PowerDNS writes are verified by reading the affected zone back after each
successful PATCH. Temporary connection failures, HTTP 429 responses, and HTTP
5xx responses are retried with exponential backoff. Configure this behavior
with `CDNLITE_POWERDNS_VERIFY_AFTER_WRITE`,
`CDNLITE_POWERDNS_RETRIES`, `CDNLITE_POWERDNS_RETRY_SLEEP_MS`, and
`CDNLITE_POWERDNS_TIMEOUT_SECONDS`.

Validate DNS publishing against the bundled stack:

```bash
docker compose up -d --build
curl -fsS -H "X-API-Key: $PDNS_API_KEY" \
  http://localhost:8089/api/v1/servers/localhost
dig @127.0.0.1 -p "${PDNS_DNS_HOST_PORT:-5353}" example.net SOA
```

Tests mutate only the local PostgreSQL-backed PowerDNS instance.

Core stores every zone write attempt in `dns_sync_events` and keeps the latest
per-zone result in `dns_sync_state`.

Managed zones use platform SOA authority settings:
`CDNLITE_DNS_PRIMARY_NS=ns1.faratar.ir.`,
`CDNLITE_DNS_HOSTMASTER=hostmaster.faratar.ir.`,
`CDNLITE_DNS_SOA_REFRESH=7200`, `CDNLITE_DNS_SOA_RETRY=3600`,
`CDNLITE_DNS_SOA_EXPIRE=1209600`, `CDNLITE_DNS_SOA_MINIMUM=60`, and
`CDNLITE_DNS_SOA_TTL=60` by default. The sync keeps exactly one apex SOA and
uses a stored monotonic serial that changes only when zone content changes.

```bash
docker compose exec core php artisan cdn:powerdns:doctor
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
curl -fsS http://localhost:8080/cdn-health
```

`doctor` reports SOA validity for each zone, `dry-run` builds the current DNS
projection and SOA repairs without writing PowerDNS, and `force-sync`
republishes customer and edge records, repairs SOA, and verifies the result.

## Testing

Host-side checks:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
docker compose run --rm core-test
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
```

Shell syntax checks:

```bash
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh
bash -n ci/smoke.sh
bash -n ci/e2e.sh
bash -n ci/dns_e2e.sh
```

Smoke and e2e:

```bash
docker compose up -d --build --wait
./ci/smoke.sh

docker compose up -d --build
EDGE_AGENT_IDLE=1 CDNLITE_CACHE_DEFAULT_TTL=1s ./ci/e2e.sh
CDNLITE_EDGE_HEALTH_MODE=static ./ci/dns_e2e.sh
```

Production DNS scale qualification is destructive and must run on a disposable
fresh-install stack:

```bash
./ci/stress-dns.sh
```

Its defaults are the full target: 10,000 domains, 1,000 records per domain,
10 edge nodes across three regions, ten health flaps, and a 10-second maximum
for the edge-only reconciliation. It resets both Core and PowerDNS data, runs a
full verified reconciliation, changes one edge IP, exercises concurrent user
record changes during edge health flaps, and writes JSON/Markdown reports to
`ci/reports/`.

For a mechanics-only local check, reduce the dataset explicitly:

```bash
STRESS_DOMAINS=10 STRESS_RECORDS_PER_DOMAIN=20 \
STRESS_EDGE_NODES=6 STRESS_FLAP_ITERATIONS=2 ./ci/stress-dns.sh
```

Only the default 10,000 x 1,000 run qualifies the full load model. GitHub Actions exposes
the same default run through the manual `run_dns_stress` workflow input.
See [DNS Stress Testing](stress-testing.md) for the complete destructive-run
procedure, configuration variables, assertions, reports, and recovery steps.

The DNS acceptance flow verifies Core-created zones, raw CNAME/LUA
records, apex LUA answers with `dig`, edge health reconciliation, stale record
deletion, persisted failure state, and recovery. Static Lua answers are used
only for deterministic documentation-range CI fixtures.

Core and the DNS reconciler receive the same CDN zone, proxy hostname, and TTL
settings. By default, shared edge-pool Lua records use edge IP, country, and
continent data from Core and fall back to the first eligible edge IP. Static
proxy anycast settings replace those Lua records with plain A/AAAA records for
the configured families. Recreate both services after changing CDN DNS values.

Dashboard validation uses typechecking, unit tests, a production build, and
manual operator QA. Browser automation is intentionally outside the release
gate.

## Deployment

For production topology selection, immutable image tags, security, backup,
upgrade, rollback, and release qualification, use the
[Production Deployment](deployment.md) guide.

1. Copy `.env.example` to `.env` and replace every local secret.
2. Set `CDNLITE_BOOTSTRAP_ADMIN_USER=0` after creating durable admin credentials.
3. Set `CDNLITE_BOOTSTRAP_EDGE_TOKEN=0` after registering production edge tokens.
4. Set `CDNLITE_API_TOKEN` for control-plane API protection.
5. Set dashboard `VITE_*` URLs to public browser-reachable hosts and rebuild the dashboard image.
6. Put core and dashboard behind TLS and production authentication at the platform or reverse proxy layer.
7. Run `docker compose up -d --build`.
8. Run readiness, smoke, DNS, edge, and SSL checks before sending traffic.

## GitHub Pages Rendering

The docs use VitePress. Source files live under `docs/`, the VitePress config lives at `docs/.vitepress/config.mts`, and the static build is emitted to `docs/.vitepress/dist`.

The API contract is published at
`https://vaheed.github.io/CDNLite/api/openapi.yaml`. The source file is
`docs/public/api/openapi.yaml`; keep it updated with route additions and
request/response shape changes so developers can generate clients or load the
spec into API tools.

Local preview:

```bash
cd docs
npm ci
npm run docs:dev
```

Production build:

```bash
cd docs
npm ci
npm run docs:build
npm run docs:preview
```

After a build, confirm the OpenAPI file is included in the static output:

```bash
test -f docs/.vitepress/dist/api/openapi.yaml
```

GitHub Pages deployment is handled by `.github/workflows/docs.yml`. The workflow installs docs dependencies, builds VitePress, uploads `docs/.vitepress/dist`, and deploys through GitHub Pages Actions.

The default VitePress base path is `/CDNLite/`. The Pages workflow overrides it with the repository name:

```text
VITEPRESS_BASE=/${{ github.event.repository.name }}/
```

For a custom domain or a different Pages path, set `VITEPRESS_BASE` before running the build.

If dependencies are not installed, validate links and Markdown file presence with:

```bash
find docs -name '*.md' -print
rg -n '\\[[^]]+\\]\\(([^)#][^)]+\\.md)\\)' docs README.md
```
`CDNLITE_POWERADMIN_URL` controls the operator link shown by the DNS Operations
page and defaults to `http://localhost:9191`. It does not change the Poweradmin
listener or expose it publicly.
