# Configuration

[Back to docs index](index.md)

Configuration is defined by `.env.example`, `docker-compose.yml`, CI job environment variables, and a few code-level fallbacks.

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
| `EDGE_TOKEN` | `edge-dev-token` | agent | Yes | Bearer token and HMAC secret source. | Secret; rotate. |
| `EDGE_HOSTNAME` | `edge-local-1` | agent | No | Registration hostname. | Not secret. |
| `EDGE_PUBLIC_IP` | `auto` | agent, edge DNS | No | `auto` lets the agent detect its public IPv4 address during register and heartbeat; set a concrete IPv4 to override. | Public data. |
| `EDGE_REGION` | `local` | edge, agent | No | Region slug used for platform edge hostnames such as `ir.edge.example.com`. | Not secret. |
| `EDGE_VERSION` | `v1` | agent | No | Registration version string. | Not secret. |
| `CDNLITE_CACHE_DEFAULT_TTL` | `60s` | edge | No | Default NGINX proxy-cache TTL for 200, 301, and 302 responses. Supports NGINX time units such as `30s`, `5m`, or `1h`. | Not secret. |
| `CORE_HOST_PORT` | `8080` | Compose | No | Host port for core. | Not secret. |
| `EDGE_HOST_PORT` | `8081` | Compose | No | Host port for edge. | Not secret. |
| `POSTGRES_HOST_PORT` | `5432` | Compose | No | Host port for PostgreSQL. | Do not expose publicly. |
| `CORE_URL` | `http://core:8080` | agent | Yes | Core base URL. | Use trusted network. |
| `EDGE_CONFIG_DIR` | `./edge/config` | Compose | No | Host directory mounted into edge and edge-agent at `/var/lib/cdnlite`. | Contains config and metrics. |
| `EDGE_CONFIG_PATH` | `/var/lib/cdnlite/config.json` | agent | Yes | Snapshot write path. | Agent-writable. |
| `EDGE_CONFIG_CACHE_PATH` | `EDGE_CONFIG_PATH` | agent | No | Last-known-good snapshot cache path used for offline startup fallback. | Agent-writable. |
| `EDGE_CONFIG_MAX_STALE_SECONDS` | `0` (disabled) | edge | No | Optional stale threshold. `/ready` remains 200 with valid config but reports warning when stale age exceeds this value. | Not secret. |
| `EDGE_SYNC_STATUS_PATH` | `/var/lib/cdnlite/edge-sync-status.json` | agent | No | Sync metadata file with version/source/core reachability and last successful sync time. | Not secret. |
| `METRIC_PATH` | `/var/lib/cdnlite/metrics.ndjson` | agent | Yes | Metric file read/truncate path. | Traffic metadata. |
| `SECURITY_EVENT_PATH` | `/var/lib/cdnlite/security-events.ndjson` | edge, agent | No | Edge security event queue file path consumed by agent push loop. | Contains request metadata (IP/path/method). |
| `EDGE_AGENT_IDLE` | `0` | agent, CI | No | Set to `1` to keep the agent container alive without starting its register/heartbeat/config loop. Used by CI so `ci/e2e.sh` can run agent scripts deterministically after token provisioning. | Test-only. |
| `POWERDNS_ENABLED` | `0` | core | No | Enables PowerDNS sync. | Requires API key. |
| `POWERDNS_STRICT` | `0` | core | No | Fail local operations if PowerDNS sync fails. | Operational choice. |
| `POWERDNS_API_URL` | empty in Compose | core | If enabled | PowerDNS API base URL. | Prefer private/TLS network. |
| `POWERDNS_PUBLIC_API_URL` | `POWERDNS_API_URL` | CI scripts | No | Host-reachable PowerDNS URL for e2e checks when core uses an internal Compose URL. | Test-only. |
| `POWERDNS_HOST_PORT` | `8089` | Compose | No | Host port for the mock PowerDNS service when the `powerdns` profile is enabled. | Test-only. |
| `POWERDNS_API_KEY` | empty in Compose | core | If enabled | Sent as `X-API-Key`. | Secret. |
| `POWERDNS_SERVER_ID` | `localhost` | core | No | PowerDNS server ID path segment. | Not secret. |
| `POWERDNS_ZONE_KIND` | `NATIVE` | core | No | Zone kind: `NATIVE`, `MASTER`, or `SLAVE`. | Not secret. |
| `POWERDNS_ZONE_NAMESERVERS` | `ns1.local.` in example | core | No | Comma-separated nameservers. | Public DNS data. |
| `POWERDNS_DEFAULT_BASE_DOMAIN` | `local.` | core fallback | No | Used only when nameservers are unset. | Public DNS data. |
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
