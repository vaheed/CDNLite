# CDNLite Documentation

CDNLite is a compact CDN platform for learning, local experimentation, and small controlled deployments. It combines a PHP control plane, PostgreSQL state, a Vue admin dashboard, an OpenResty/Lua edge proxy, and a signed shell-based edge agent.

![CDNLite dashboard screenshot](ScreenShot.png)

## Navigation

- [Setup](setup.md)
- [CDN In A Minute](cdn-in-a-minute.md)
- [User Guide](usage/user.md)
- [Admin Guide](usage/admin.md)
- [API Reference](api/api.md)
- [OpenAPI YAML](/api/openapi.yaml)
- [Architecture](architecture.md)
- [Extensions And Integrations](extensions.md)
- [Troubleshooting](troubleshooting.md)
- [Security](security.md)
- [Operations Runbooks](runbooks/index.md)
- [Examples](examples/index.md)
- [Use Cases](use-cases/index.md)
- [Best Practices](best-practices/index.md)

## What It Does

CDNLite lets operators register domains, manage DNS records, define origins, configure cache and traffic rules, issue or import SSL certificates, publish config snapshots, and observe edge traffic. The edge proxy reads generated JSON config and handles host-based routing, caching, redirect decisions, WAF/rate-limit/IP/header rules, origin failover, TLS material, metrics, and security events.

## Key Features

- Domain lifecycle management with nameserver verification and activation.
- DNS records with proxy toggles, DNS-only records, anycast routing, and Geo DNS route previews.
- Multi-origin support with primary/backup origins and scheduled health checks.
- Cache settings, cache rules, and purge request history.
- Redirects, page rules, WAF rules, rate limits, custom headers, and IP access rules.
- SSL automation, ACME DNS-01 issuance, renewal scheduling, and manual certificate import.
- Edge registration, heartbeat, config polling, usage ingest, and security-event ingest with HMAC replay protection.
- Vue dashboard for operations, domain management, edge status, analytics, events, audit logs, settings, and optional edge developer tools.
- Docker Compose stack with PostgreSQL, core, edge, edge agent, dashboard, origin mocks, and bundled DNSGeo/PowerDNS.

## Repository Map

| Path | Purpose |
| --- | --- |
| `core/` | PHP control plane, API router, CLI commands, services, canonical fresh-install schema, and contract tests. |
| `dash/` | Vue 3, TypeScript, Vite, Pinia, TanStack Query, Tailwind, and ECharts dashboard. |
| `edge/openresty/` | OpenResty Nginx config and Lua runtime modules. |
| `edge/agent/` | POSIX shell agent that signs edge calls and syncs config/metrics/events. |
| `ci/` | Bash smoke/e2e scripts and controlled origin services. |
| `docs/` | GitHub Pages-compatible documentation. |

## Fast Path

```bash
cp .env.example .env
docker compose up -d --build
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
```

Open the dashboard at `http://localhost:8082` and sign in with the local bootstrap account from `.env.example`: `admin` / `admin`.

## Current Limits

CDNLite does not implement enterprise RBAC, billing, multi-tenant isolation, or production-grade identity federation. API bearer auth is optional unless `CDNLITE_API_TOKEN` is set; edge endpoints always require registered edge credentials and signed headers. Treat the default credentials and `edge-dev-token` as local-only secrets.

## Recommended Reading Paths

If you are new to the project, read [Setup](setup.md), then [User Guide](usage/user.md), then [Examples](examples/index.md). That path gets a local stack running before it asks you to understand every moving part.

If you are integrating with the API, read [API Reference](api/api.md), download [OpenAPI YAML](/api/openapi.yaml), then use the domain and DNS examples in [Examples](examples/index.md). The OpenAPI document is intentionally pragmatic: it covers the implemented route families and reusable schemas so client generators and API explorers have a stable contract to consume.

If you are operating CDNLite, read [Admin Guide](usage/admin.md), [Security](security.md), [Troubleshooting](troubleshooting.md), [Operations Runbooks](runbooks/index.md), and [Best Practices](best-practices/index.md). Keep the architecture page nearby during incidents because it shows where config, metrics, and edge decisions flow.

## Mental Model

CDNLite has three loops:

1. Operators change desired state through the dashboard, API, or CLI.
2. Core validates and stores that state, then builds edge config snapshots.
3. Edge agents pull config and push back heartbeats, metrics, and security decisions.

When debugging, locate which loop is broken. A domain problem is often desired state. A stale edge is often config pull. Empty analytics are often metrics push or aggregation. This framing keeps investigations short and avoids random restarts.
