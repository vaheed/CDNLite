# Configuration

[Back to docs index](index.md)

Configuration is defined by environment templates, `docker-compose.yml`, CI job environment variables, and a few code-level fallbacks.

## Environment Templates

| File | Purpose |
|---|---|
| `.env.example` | Local quickstart defaults for root Docker Compose. Equivalent to the dev template for users who expect the traditional filename. |
| `.env.dev.example` | Explicit local development defaults, including the default dashboard admin `admin` / `admin`. |
| `.env.production.example` | Production-oriented root Compose template. Bootstrap admin and edge token bootstrap are disabled, and secrets use `CHANGE_ME` placeholders. |
| `dash/.env.example` | Dashboard-only Vite template for running or building `dash/` outside the root Compose flow. |

Root Compose reads `.env`, so choose one root template and copy it:

```bash
cp .env.dev.example .env
```

For production:

```bash
cp .env.production.example .env
```

For the bundled PostgreSQL service, keep `POSTGRES_PASSWORD` and `DB_PASSWORD`
identical. They may differ only when `DB_HOST` points to an externally managed
database with separately provisioned application credentials.

For dashboard-only work:

```bash
cp dash/.env.example dash/.env
```

## Variables

| Variable | Default | Used by | Required | Explanation | Security |
|---|---|---|---|---|---|
| `GITHUB_REPOSITORY_OWNER` | `local` | Compose | No | Image tag owner. | Not secret. |
| `POSTGRES_DB` | `cdnlite` | `postgres` | Yes | Bootstrap DB name. | Not secret. |
| `POSTGRES_USER` | `cdnlite` | `postgres` | Yes | Bootstrap DB user. | Credential. |
| `POSTGRES_PASSWORD` | `cdnlite` | `postgres` | Yes | Bootstrap DB password. | Secret. |
| `DB_HOST` | `postgres` | core, tests | Yes | PostgreSQL host for PDO. | Not secret. |
| `DB_PORT` | `5432` | core, tests | Yes | PostgreSQL port. | Not secret. |
| `DB_DATABASE` | `cdnlite` | core, tests | Yes | Core DB name. | Not secret. |
| `DB_USERNAME` | `cdnlite` | core, tests | Yes | Core DB user. | Credential. |
| `DB_PASSWORD` | `cdnlite` | core, tests | Yes | Core DB password. | Secret. |
| `APP_LOG_ENABLED` | enabled if unset | core | No | Truthy values enable JSON stderr logs. | Logs may expose paths/errors. |
| `APP_LOG_LEVEL` | `info` | core | No | `debug`, `info`, `warn`, or `error`. | Use debug carefully. |
| `APP_DEBUG` | `0` | core | No | Truthy values include exception details in 500 responses. | Keep off outside dev. |
| `EDGE_ID` | `edge-local-1` | edge, agent | Yes | Edge identity. | Not secret. |
| `DEV_MODE` | `0` | edge, agent | No | Allows startup without `EDGE_ID` only when set to `1`; responses and metrics then use `unknown`. | Development only. |
| `EDGE_TOKEN` | `edge-dev-token` | agent | Yes | Bearer token and HMAC secret source. | Secret; rotate. |
| `CDNLITE_BOOTSTRAP_EDGE_TOKEN` | `1` | core | No | When truthy, core auto-upserts one edge token at startup using bootstrap values. Useful after `docker compose down -v` resets the database. | Keep disabled if you require strict external token provisioning. |
| `CDNLITE_BOOTSTRAP_EDGE_ID` | empty (`EDGE_ID` fallback) | core | No | Edge ID used by token bootstrap when enabled. | Not secret. |
| `CDNLITE_BOOTSTRAP_EDGE_TOKEN_VALUE` | empty (`EDGE_TOKEN` fallback) | core | No | Token value used by bootstrap when enabled. | Secret. |
| `EDGE_HOSTNAME` | `edge-local-1` | agent | No | Registration hostname. | Not secret. |
| `EDGE_PUBLIC_IP` | `auto` | agent, edge DNS | No | `auto` lets the agent detect its public IPv4 address during register and heartbeat; set a concrete IPv4 to override. | Public data. |
| `EDGE_REGION` | `local` | edge, agent | No | Region slug used for platform edge hostnames such as `ir.edge.example.com`. | Not secret. |
| `EDGE_VERSION` | `v1` | agent | No | Registration version string. | Not secret. |
| `CDNLITE_CACHE_DEFAULT_TTL` | `60s` | edge | No | Default NGINX proxy-cache TTL for 200, 301, and 302 responses. Supports NGINX time units such as `30s`, `5m`, or `1h`. | Not secret. |
| `CDNLITE_API_TOKEN` | empty | core, dashboard users | No | Optional static bearer token for non-edge control-plane API routes. Admin sessions are also accepted when admin users exist. | Secret. |
| `CDNLITE_SSL_SECRET_KEY` | dev secret in Compose | core | Yes for SSL import/ACME | Encrypts stored private keys and ACME account keys. | Secret; rotate with care because existing encrypted keys depend on it. |
| `CDNLITE_ACME_DIRECTORY_URL` | Let's Encrypt staging in dev, production in prod template | core | Yes for ACME | ACME directory endpoint used by `POST /ssl/acme/issue`. | Public endpoint. |
| `CDNLITE_ACME_CONTACT_EMAIL` | `admin@example.com` in dev | core | Yes for first ACME issue | Contact email registered with the ACME account. | Personal/contact data. |
| `CDNLITE_ACME_DNS_PROPAGATION_SECONDS` | `0` dev, `30` prod template | core | No | Delay after writing the DNS-01 TXT record before asking ACME to validate. | Not secret. |
| `CDNLITE_ACME_POLL_ATTEMPTS` | `10` dev, `30` prod template | core | No | Number of one-second ACME authorization/order polling attempts. | Not secret. |
| `CDNLITE_ADMIN_SESSION_TTL_SECONDS` | `28800` | core | No | Dashboard admin session lifetime after `/api/v1/admin/login`. | Not secret. |
| `CDNLITE_CORS_ALLOWED_ORIGINS` | `http://localhost:8082,http://127.0.0.1:8082` | core | No | Comma-separated browser origins allowed to call the core API. Set this to your dashboard URL when accessing CDNLite from another host. | Public endpoint; restrict in production. |
| `CDNLITE_BOOTSTRAP_ADMIN_USER` | `1` in dev, `0` in production template | core | No | When truthy, core auto-creates or updates one local dashboard admin at startup. Useful for quickstart and volume resets. | Disable outside local development. |
| `CDNLITE_BOOTSTRAP_ADMIN_USERNAME` | `admin` | core | No | Username for the local bootstrap dashboard admin. | Not secret. |
| `CDNLITE_BOOTSTRAP_ADMIN_PASSWORD` | `admin` in dev, empty in production template | core | No | Password for the local bootstrap dashboard admin. | Secret; local quickstart only. |
| `CDNLITE_BOOTSTRAP_ADMIN_DISPLAY_NAME` | `Local Admin` | core | No | Display name for the local bootstrap dashboard admin. | Not secret. |
| `CORE_HOST_PORT` | `8080` | Compose | No | Host port for core. | Not secret. |
| `EDGE_HOST_PORT` | `8081` | Compose | No | Host port for edge. | Not secret. |
| `POSTGRES_HOST_PORT` | `5432` | Compose | No | Host port for PostgreSQL. | Do not expose publicly. |
| `DASHBOARD_PORT` | `8082` | Compose | No | Host port for the static Vue admin dashboard. | Protect behind auth in production. |
| `VITE_CDNLITE_CORE_URL` | `http://localhost:8080` | dashboard build | Yes | Browser-reachable core API URL compiled into the Vite dashboard bundle. Do not use internal Compose hostnames unless browsers can resolve them. | Public endpoint; may imply environment. |
| `VITE_CDNLITE_EDGE_URL` | `http://localhost:8081` | dashboard build | Yes | Browser-reachable edge URL compiled into the Vite dashboard bundle. | Public endpoint; may imply environment. |
| `VITE_CDNLITE_APP_NAME` | `CDNLite Admin` | dashboard build | No | Dashboard display name. | Not secret. |
| `VITE_CDNLITE_API_TOKEN` | empty | dashboard build | No | Optional bearer token sent by the browser to control-plane API requests. | Secret if set, but exposed to browser assets; prefer external auth in production. |
| `VITE_ENABLE_EDGE_DEV_TOOLS` | `false` | dashboard build | No | Enables signed edge developer tools. Edge tokens entered there stay in session memory only. | Avoid enabling broadly. |
| `VITE_ENABLE_USAGE_SIMULATOR` | `false` | dashboard build | No | Enables usage collector simulator tools. | Operationally sensitive. |
| `VITE_ENABLE_SSL_TOOLS` | `true` | dashboard build | No | Shows SSL certificate tooling. | Certificate material must not be logged. |
| `VITE_ENABLE_SECURITY_EVENT_VIEWER` | `true` | dashboard build | No | Shows security event viewer pages. | Events may contain request metadata. |
| `VITE_ENABLE_LOG_VIEWER` | `true` | dashboard build | No | Shows log-oriented dashboard affordances where available. | Logs may contain diagnostics. |
| `VITE_DEFAULT_USAGE_BUCKET` | `minute` | dashboard build | No | Default usage analytics bucket: `minute`, `hour`, or `day`. | Not secret. |
| `VITE_DASHBOARD_REFRESH_SECONDS` | `15` | dashboard build | No | Dashboard polling interval. | Not secret. |
| `VITE_REQUEST_TIMEOUT_MS` | `15000` | dashboard build | No | Browser API request timeout. | Not secret. |
| `CORE_URL` | `http://core:8080` | agent | Yes | Core base URL. | Use trusted network. |
| `EDGE_CONFIG_DIR` | `./edge/config` | Compose | No | Host directory mounted into edge and edge-agent at `/var/lib/cdnlite`. | Contains config and metrics. |
| `EDGE_CONFIG_PATH` | `/var/lib/cdnlite/config.json` | agent | Yes | Snapshot write path. | Agent-writable. |
| `EDGE_CONFIG_CACHE_PATH` | `EDGE_CONFIG_PATH` | agent | No | Last-known-good snapshot cache path used for offline startup fallback. | Agent-writable. |
| `EDGE_CONFIG_MAX_STALE_SECONDS` | `0` (disabled) | edge | No | Optional stale threshold. `/ready` remains 200 with valid config but reports warning when stale age exceeds this value. | Not secret. |
| `EDGE_SYNC_STATUS_PATH` | `/var/lib/cdnlite/edge-sync-status.json` | agent | No | Sync metadata file with version/source/core reachability and last successful sync time. | Not secret. |
| `METRIC_PATH` | `/var/lib/cdnlite/metrics.ndjson` | agent | Yes | Metric file read/truncate path. | Traffic metadata. |
| `SECURITY_EVENT_PATH` | `/var/lib/cdnlite/security-events.ndjson` | edge, agent | No | Edge security event queue file path consumed by agent push loop. | Contains request metadata (IP/path/method). |
| `CDNLITE_READINESS_SNAPSHOT_MAX_AGE_SECONDS` | `900` | core | No | Maximum config snapshot age before readiness reports a warning. | Not secret. |
| `EDGE_AGENT_IDLE` | `0` | agent, CI | No | Set to `1` to keep the agent container alive without starting its register/heartbeat/config loop. Used by CI so `ci/e2e.sh` can run agent scripts deterministically after token provisioning. | Test-only. |
| `PDNS_API_KEY` | `test-key` | PowerDNS mock | No | API key accepted by the optional local mock. It is not the platform PowerDNS credential. | Test-only. |
| `POWERDNS_PUBLIC_API_URL` | `http://localhost:8089` | CI scripts | No | Host-reachable URL for the optional mock PowerDNS service. | Test-only. |
| `POWERDNS_HOST_PORT` | `8089` | Compose | No | Host port for the mock PowerDNS service when the `powerdns` profile is enabled. | Test-only. |

## Database-backed platform settings

The Settings dashboard owns operational defaults without restarting core. Platform settings use
code defaults until they are saved in `platform_settings`; they are not read from core environment
variables. Environment variables are reserved for process bootstrap and infrastructure wiring.

PowerDNS URL, API key, server ID, zone kind, enable/strict flags, and authoritative nameservers are
not root runtime environment variables. Configure them after login through the Settings dashboard
or `/api/v1/settings/*`; changes are effective for subsequent PowerDNS operations. API keys are stored as secret settings:
GET responses and audit entries expose only whether a value is configured, never the plaintext.
| `CDNLITE_EDGE_BASE_DOMAIN` | `vaheed.net` | core | Yes for edge DNS | Platform-owned DNS base zone. Customer zones point into this zone. | Public DNS data. |
| `CDNLITE_EDGE_ZONE_PREFIX` | `edge` | core | No | Prefix for edge hostnames below the base domain. | Public DNS data. |
| `CDNLITE_EDGE_DEFAULT_TARGET` | `geo` | core | No | Default customer policy target label. | Public DNS data. |
| `CDNLITE_EDGE_TTL` | `60` | core | No | TTL for platform edge records. | Not secret. |
| `CDNLITE_EDGE_HEALTH_MODE` | `ifportup` | core | No | Edge LUA health mode: `ifportup`, `ifurlup`, or `static`. | Not secret. |
| `CDNLITE_EDGE_HEALTH_PORT` | `80` | core | No | TCP/HTTP health port. | Not secret. |
| `CDNLITE_EDGE_HEALTH_URL` | `/cdn-health` | core | No | HTTP health path for `ifurlup`. | Not secret. |
| `CDNLITE_EDGE_HEALTH_TIMEOUT` | `1` | core | No | PowerDNS health timeout seconds. | Not secret. |
| `CDNLITE_EDGE_HEALTH_INTERVAL` | `10` | core | No | PowerDNS health interval seconds. | Not secret. |
| `CDNLITE_EDGE_HEALTH_MIN_FAILURES` | `2` | core | No | Failures before an edge is considered down. | Not secret. |
| `CDNLITE_EDGE_SELECTOR` | `pickclosest` | core | No | PowerDNS selector: `pickclosest`, `hashed`, `random`, or `all`. | Not secret. |
| `CDNLITE_EDGE_BACKUP_SELECTOR` | `empty` | core | No | Backup selector used when health checks fail. | Not secret. |
| `CDNLITE_EDGE_APEX_MODE` | `ALIAS` | core | No | Public record type for proxied apex records. | Not secret. |
| `CDNLITE_GEO_DEFAULT_POLICY` | `auto` | core | No | Default geo policy mode. | Not secret. |
| `CDNLITE_GEO_ENABLE_COUNTRY_RULES` | `true` | core | No | Enables country policy generation. | Not secret. |
| `CDNLITE_GEO_ENABLE_CONTINENT_RULES` | `true` | core | No | Enables continent policy generation. | Not secret. |
| `CDNLITE_GEO_ENABLE_REGION_RULES` | `true` | core | No | Enables region policy generation. | Not secret. |
| `CDNLITE_NS1_IP`, `CDNLITE_NS2_IP` | empty | core | No | Optional A records for platform nameservers. | Public DNS data. |
| `CDNLITE_BOOTSTRAP_EDGE_DNS` | `1` | core | No | Operational flag for bootstrapping edge DNS. | Not secret. |
| `PDNS_API_KEY`, `PDNS_HOST`, `PDNS_PORT` | `test-key`, `0.0.0.0`, `8081` | CI mock | No | Mock PowerDNS settings. `PDNS_PORT` also controls the container port exposed by Compose. | Test-only. |
| `CORE_URL`, `EDGE_URL`, `CI_ENV_NAME`, `REPORT_DIR`, `REPORT_MD`, `REPORT_JSON`, `REPORT_JUNIT` | script defaults | CI scripts | No | Test endpoints and report files. | Reports may contain diagnostics. |

## Volumes

| Mount | Purpose |
|---|---|
| `pgdata:/var/lib/postgresql/data` | PostgreSQL data. |
| `${EDGE_CONFIG_DIR:-./edge/config}:/var/lib/cdnlite` | Edge config and metrics shared with agent. |
| `./edge/logs:/var/log/openresty` | OpenResty logs. |

The edge container also uses `/var/cache/cdnlite` inside the container for the OpenResty proxy cache.

Compose starts `core` only after the PostgreSQL healthcheck passes, so edge-agent startup should not emit transient config-pull 500s from a database that is still booting.

The dashboard is a Vite static SPA. Its `VITE_*` values are build-time values baked into the generated assets by `docker compose build dashboard`.
