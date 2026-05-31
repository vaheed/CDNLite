# Configuration

[Back to docs index](index.md)

Configuration is defined by `.env.example`, `docker-compose.yml`, CI overrides, and a few code-level fallbacks.

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
| `EDGE_PUBLIC_IP` | `127.0.0.1` | agent, PowerDNS proxied A | No | Edge public IP. | Public data. |
| `EDGE_REGION` | `local` | edge, agent | No | Region/country marker. Two-letter uppercase values can drive PowerDNS geo LUA. | Not secret. |
| `EDGE_VERSION` | `v1` | agent | No | Registration version string. | Not secret. |
| `CORE_HOST_PORT` | `8080` | Compose | No | Host port for core. | Not secret. |
| `EDGE_HOST_PORT` | `8081` | Compose | No | Host port for edge. | Not secret. |
| `POSTGRES_HOST_PORT` | `5432` | Compose | No | Host port for PostgreSQL. | Do not expose publicly. |
| `CORE_URL` | `http://core:8080` | agent | Yes | Core base URL. | Use trusted network. |
| `EDGE_CONFIG_PATH` | `/var/lib/cdnlite/config.json` | agent | Yes | Snapshot write path. | Agent-writable. |
| `METRIC_PATH` | `/var/lib/cdnlite/metrics.ndjson` | agent | Yes | Metric file read/truncate path. | Traffic metadata. |
| `POWERDNS_ENABLED` | `0` | core | No | Enables PowerDNS sync. | Requires API key. |
| `POWERDNS_STRICT` | `0` | core | No | Fail local operations if PowerDNS sync fails. | Operational choice. |
| `POWERDNS_API_URL` | empty in Compose | core | If enabled | PowerDNS API base URL. | Prefer private/TLS network. |
| `POWERDNS_API_KEY` | empty in Compose | core | If enabled | Sent as `X-API-Key`. | Secret. |
| `POWERDNS_SERVER_ID` | `localhost` | core | No | PowerDNS server ID path segment. | Not secret. |
| `POWERDNS_ZONE_KIND` | `NATIVE` | core | No | Zone kind: `NATIVE`, `MASTER`, or `SLAVE`. | Not secret. |
| `POWERDNS_ZONE_NAMESERVERS` | `ns1.local.` in example | core | No | Comma-separated nameservers. | Public DNS data. |
| `POWERDNS_DEFAULT_BASE_DOMAIN` | `local.` | core fallback | No | Used only when nameservers are unset. | Public DNS data. |
| `CORE_POWERDNS_API_URL` | CI only | CI override | No | Container URL for PowerDNS mock. | Not secret. |
| `PDNS_API_KEY`, `PDNS_HOST`, `PDNS_PORT` | `test-key`, `0.0.0.0`, `8081` | CI mock | No | Mock PowerDNS settings. | Test-only. |
| `CORE_URL`, `EDGE_URL`, `CI_ENV_NAME`, `REPORT_DIR`, `REPORT_MD`, `REPORT_JSON`, `REPORT_JUNIT` | script defaults | CI scripts | No | Test endpoints and report files. | Reports may contain diagnostics. |

## Volumes

| Mount | Purpose |
|---|---|
| `pgdata:/var/lib/postgresql/data` | PostgreSQL data. |
| `./edge/config:/var/lib/cdnlite` | Edge config and metrics shared with agent. |
| `./edge/logs:/var/log/openresty` | OpenResty logs. |
