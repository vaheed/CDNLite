---
title: Extensions And Integrations
description: Runtime extensions, libraries, and integration points used by the CDNLite PHP control plane, Vue dashboard, OpenResty edge, and PowerDNS stack.
---

# Extensions And Integrations

This page lists the external libraries, runtime extensions, and integrations used by CDNLite.

## Backend

| Extension | Purpose |
| --- | --- |
| PHP 8.3 | Core runtime and CLI command execution. |
| `pdo_pgsql` | PostgreSQL access from PHP. |
| PostgreSQL 16 | Persistent control-plane data. |
| OpenSSL | HMAC signing and certificate workflows. |
| ACME directory API | Automated certificate issuance through DNS-01 flows. |
| PowerDNS API | Optional authoritative DNS publishing integration. |

## Frontend

| Package | Purpose |
| --- | --- |
| Vue 3 | Dashboard UI framework. |
| TypeScript | Typed dashboard application code. |
| Vite | Development server and production build. |
| Vue Router | Dashboard routes. |
| Pinia | Client state stores. |
| TanStack Query for Vue | API query caching and refresh. |
| Tailwind CSS | Styling primitives. |
| Headless UI Vue | Accessible UI component patterns. |
| ECharts and Vue ECharts | Analytics charts. |
| Vee Validate and Zod | Form validation. |
| Lucide Vue Next | Dashboard icons. |
| Vitest, Testing Library | Dashboard unit and component test tooling. |

## Edge Runtime

| Extension | Purpose |
| --- | --- |
| OpenResty | Nginx plus Lua runtime. |
| Lua modules in `edge/openresty/lua` | Config loading, routing, proxying, TLS, metrics, WAF/rules, error pages. |
| Nginx cache | Edge cache behavior and purge logic. |
| POSIX shell agent | Portable edge sync loop. |
| `curl` and `openssl` | Agent HTTP calls and HMAC signatures. |

## CI And Local Mocks

| Tool | Purpose |
| --- | --- |
| Docker Compose | Product stack and CI smoke/e2e substrate. |
| Bash CI scripts | Smoke, e2e, release, DNS, and edge flow checks. |
| Python `pytest` | Core contract tests. |
| DNSGeo/PowerDNS | PostgreSQL-backed authoritative DNS, Lua GeoDNS, MMDB updates, direct apex LUA answers, and Poweradmin. |
| Nginx origin mocks | HTTP and TLS origin behavior for proxy tests. |

## Configuration

PowerDNS settings are stored as platform settings and can be tested through:

```bash
docker compose exec core php artisan cdn:settings:test-powerdns
```

ACME settings come from `CDNLITE_ACME_*` environment variables. Dashboard extensions are controlled with `VITE_ENABLE_*` flags at build time.

## Adding New Integrations

1. Add configuration to `.env.example` and `docker-compose.yml`.
2. Add service code and tests.
3. Add dashboard controls only when operators need to change or inspect the integration.
4. Update this page, [Setup](setup.md), [Security](security.md), and [API Reference](api/api.md) when the integration changes behavior.
