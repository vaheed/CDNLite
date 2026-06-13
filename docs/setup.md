# Setup

This guide covers local development, dashboard development, production-oriented Compose deployment, validation, and GitHub Pages docs rendering.

## Prerequisites

| Area | Requirement |
| --- | --- |
| OS | Linux or macOS with Docker support. Windows works best through WSL2. |
| Containers | Docker Engine and Docker Compose v2. |
| Backend | PHP 8.3 with `pdo_pgsql` for host-side lint/tests. |
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

The local `.env.example` enables admin bootstrap:

```text
CDNLITE_BOOTSTRAP_ADMIN_USER=1
CDNLITE_BOOTSTRAP_ADMIN_USERNAME=admin
CDNLITE_BOOTSTRAP_ADMIN_PASSWORD=admin
```

Create a deliberate admin account when bootstrap is disabled:

```bash
docker compose exec core php artisan cdn:admin:create \
  --username=admin \
  --password='replace-with-a-long-password'
```

## Backend Setup

The core image runs PHP from `core/public_index.php` and CLI commands from `core/artisan`. Fresh installations apply the single canonical schema in `core/database/schema.sql`; upgrade migrations are intentionally unsupported.

Useful commands:

```bash
docker compose exec core php artisan cdn:migrate
docker compose exec core php artisan cdn:dns:reconcile
docker compose exec core php artisan cdn:domain:list
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:edge:list
```

`cdn:migrate` is retained as an informational fresh-install command; it does not apply
historical upgrades. The canonical schema is applied automatically. Durable DNS state
is reconciled after mutations and by the `dns-reconciler` service every
`CDNLITE_SYNC_INTERVAL_SECONDS` seconds (default `30`).

Fresh local reset:

```bash
docker compose down -v
docker compose up -d --build
```

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
| `CDNLITE_ACME_*` | ACME directory, contact email, propagation delay, and polling. |
| `CDNLITE_BOOTSTRAP_ADMIN_*` | Local/admin bootstrap behavior. |
| `CDNLITE_BOOTSTRAP_EDGE_*`, `EDGE_ID`, `EDGE_TOKEN` | Local edge token bootstrap. |
| `CDNLITE_EDGE_*`, `CDNLITE_GEO_*`, `CDNLITE_NS*` | Edge DNS, health, anycast, and Geo DNS defaults. |
| `PDNS_REPLICATION_PASSWORD` | Password for the TLS-protected PowerDNS PostgreSQL streaming-replication role. |
| `CDNLITE_CDN_ZONE` | Authoritative zone containing stable site targets and the shared proxy record. |
| `CDNLITE_CDN_PROXY_HOST` | Shared Lua A/AAAA hostname, and must be inside `CDNLITE_CDN_ZONE`. |

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
| `EDGE_AGENT_IDLE` | CI flag to keep agent idle while scripts drive flow manually. |

Dashboard variables:

| Variable | Purpose |
| --- | --- |
| `VITE_CDNLITE_CORE_URL` | Browser URL for core. |
| `VITE_CDNLITE_EDGE_URL` | Browser URL for edge. |
| `VITE_CDNLITE_APP_NAME` | Dashboard name. |
| `VITE_CDNLITE_API_TOKEN` | Optional local/private API token compiled into assets. |
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

```bash
docker compose exec core php artisan cdn:powerdns:doctor
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
curl -fsS http://localhost:8080/cdn-health
```

`dry-run` builds the current DNS projection without writing PowerDNS.
`force-sync` republishes customer and edge records and verifies the result.

## Testing

Host-side checks:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
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

The DNS acceptance flow verifies Core-created zones, raw ALIAS/CNAME/LUA
records, ALIAS expansion with `dig`, edge health reconciliation, stale record
deletion, persisted failure state, and recovery. Static Lua answers are used
only for deterministic documentation-range CI fixtures.

Dashboard validation uses typechecking, unit tests, a production build, and
manual operator QA. Browser automation is intentionally outside the release
gate.

## Deployment

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

The API contract is published as [OpenAPI YAML](/api/openapi.yaml). The source file is `docs/public/api/openapi.yaml`; keep it updated with route additions and request/response shape changes so developers can generate clients or load the spec into API tools.

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
