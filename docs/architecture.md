# Architecture

CDNLite is split into a control plane, dashboard, data-plane edge, and agent loop.

## System Overview

```text
Operator Browser
      |
      v
Vue Dashboard -----> Core PHP API -----> PostgreSQL
      |                    |
      |                    +----> PowerDNS API or local mock
      |                    |
      |                    +----> Config snapshots
      |
      v
OpenResty Edge <---- Edge Agent <---- signed config/heartbeat endpoints
      |
      v
Customer Origins
```

## Components

| Component | Technology | Responsibility |
| --- | --- | --- |
| Core API | PHP 8.3, custom router | Domain, DNS, rules, SSL, settings, analytics, admin auth, edge auth. |
| Database | PostgreSQL 16 | Domains, records, rules, snapshots, usage, events, audits, admins. |
| Dashboard | Vue 3, TypeScript, Vite, Pinia, TanStack Query, Tailwind, ECharts | Browser admin console. |
| Edge runtime | OpenResty, Nginx, Lua | Host routing, caching, rule enforcement, TLS serving, metric queues. |
| Edge agent | POSIX shell, curl, OpenSSL | Register, heartbeat, pull config, push metrics, push security events. |
| CI and mocks | Bash, Python, Docker Compose | Smoke/e2e validation, origin mocks, PowerDNS mock. |

## Request Flow

```text
Client request
  -> OpenResty listens on 8081 or 8443
  -> Lua router reads /var/lib/cdnlite/config.json
  -> host/domain lookup chooses origin and rules
  -> redirects, WAF, rate limit, IP, cache, and headers are evaluated
  -> request proxies to primary origin or backup origin
  -> metrics/security events are appended to local queue files
  -> edge agent later pushes queues to core
```

## Config Flow

```text
Admin/API change
  -> Core validates and writes PostgreSQL
  -> ConfigService builds a snapshot
  -> Snapshot version is stored
  -> Edge agent signs GET /api/v1/edge/config
  -> Agent writes config.json atomically
  -> OpenResty Lua modules read fresh config
```

## Data Flow

| Data | Producer | Consumer |
| --- | --- | --- |
| Domain and rule state | Dashboard, API, CLI | Core services and config snapshot builder. |
| Config snapshot JSON | Core `ConfigService` | Edge agent and OpenResty runtime. |
| Metrics NDJSON | OpenResty edge | Edge agent, collector API, usage aggregates. |
| Security events NDJSON | OpenResty edge | Edge agent, collector API, dashboard. |
| Origin health | Scheduler/CLI | Readiness service and edge backup routing config. |
| Audit records | Core services | Audit log dashboard and API. |

## Deployment Topology

The root `docker-compose.yml` is the supported local and CI stack:

```text
postgres
core
ssl-scheduler
origin-health-scheduler
edge
edge-agent
dashboard
origin-http
origin-tls
powerdns profile
```

CI intentionally uses this root Compose file. Do not add CI-only override files; use environment variables and profiles for job-specific behavior.

## Storage

PostgreSQL is the supported backend. The edge uses mounted local files under `/var/lib/cdnlite` for config, sync status, metrics queues, and security-event queues. Logs are written under OpenResty log paths and surfaced through Compose logs.

## Security Boundaries

- Browser admin sessions are short-lived bearer tokens from `/api/v1/admin/login`.
- API token auth protects control-plane endpoints when `CDNLITE_API_TOKEN` is configured.
- Edge endpoints require edge ID, bearer token, timestamp, nonce, and HMAC signature.
- SSL material depends on `CDNLITE_SSL_SECRET_KEY`; keep it stable and private.
- Dashboard Vite variables are compiled into browser assets and must not contain production secrets unless the deployment is private and separately protected.
