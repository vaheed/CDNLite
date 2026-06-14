# CDNLite


[![CI](https://github.com/vaheed/CDNLite/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/vaheed/CDNLite/actions/workflows/ci.yml)
[![Docker](https://img.shields.io/badge/docker-compose-blue)](docker-compose.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

CDNLite is a lightweight CDN platform with a PHP control plane, PostgreSQL database, Vue admin dashboard, OpenResty/Lua edge proxy, and signed shell-based edge agent. It is useful for CDN learning, local labs, small private edge deployments, and testing CDN operations workflows.

Edge HTTP and HTTPS responses remove the OpenResty/Nginx `Server` header and
suppress version tokens, including on generated 5xx error pages.

![CDNLite screenshot](docs/ScreenShot.png)

## Table Of Contents

- [Features](#features)
- [Installation And Setup](#installation-and-setup)
- [Usage](#usage)
- [Documentation](#documentation)
- [Development And Validation](#development-and-validation)
- [Contribution Guidelines](#contribution-guidelines)
- [Security Guidelines](#security-guidelines)
- [License](#license)
- [Contact And Support](#contact-and-support)

## Features

- Domain lifecycle, nameserver verification, activation, and deletion.
- DNS records with proxy toggles, anycast, DNS-only mode, GeoDNS routes, and bundled DNSGeo/PowerDNS publishing.
- Origin management with primary/backup origins and scheduled health checks.
- Cache settings, cache rules, purge workflows, and cache analytics.
- Redirects, page rules, WAF rules, rate limits, response headers, and IP access rules.
- SSL settings, ACME DNS-01 issuance, renewal scheduling, and manual certificate import.
- Edge node registration, heartbeat, config polling, metrics ingest, and security-event ingest with HMAC replay protection.
- Successful edge-agent heartbeats mark the node healthy for the shared DNS edge pool.
- Vue dashboard for operations, domains, edge network, analytics, snapshots, events, audit log, and settings.
- Consistent searchable pagination for collection views and a per-domain
  Activity viewer for security events and change history.
- Docker Compose stack plus CI smoke/e2e checks.

## Installation And Setup

```bash
cp .env.example .env
docker compose up -d --build
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
```

Open the dashboard at `http://localhost:8082`. Local bootstrap credentials are `admin` / `admin`.

Full setup, environment, testing, deployment, VitePress, and GitHub Pages instructions live in [docs/setup.md](docs/setup.md).

## Usage

Use the dashboard to add domains, configure DNS and origins, define traffic/security rules, request SSL certificates, inspect config snapshots, and review analytics or security events. Use `core/artisan` commands for scripted operations:

```bash
docker compose exec core php artisan cdn:domain:list
docker compose exec core php artisan cdn:edge:list
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:db:status
docker compose exec core php artisan cdn:powerdns:doctor
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
```

See the [User Guide](docs/usage/user.md), [Admin Guide](docs/usage/admin.md), and [API Reference](docs/api/api.md).

## Documentation

The documentation site is built with VitePress from `docs/`.

- [Documentation Home](docs/index.md)
- [CDN In A Minute](docs/cdn-in-a-minute.md)
- [Setup](docs/setup.md)
- [Production Deployment](docs/deployment.md)
- [Split Deployment Generator](docs/deployment.md#generate-a-split-deployment)
- [Published OpenAPI YAML](https://vaheed.github.io/CDNLite/api/openapi.yaml)
- [OpenAPI source](docs/public/api/openapi.yaml)
- [Architecture](docs/architecture.md)
- [DNS Stress Testing](docs/stress-testing.md)
- [Extensions](docs/extensions.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Security](docs/security.md)
- [Operations Runbooks](docs/runbooks/index.md)
- [Examples](docs/examples/index.md)
- [Use Cases](docs/use-cases/index.md)
- [Best Practices](docs/best-practices/index.md)

## Domain Nameserver Operations

Use the domain detail page or API to run an immediate delegation check without
waiting for the scheduler:

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/nameservers/verify" \
  -H "Authorization: Bearer $TOKEN"
```

The response includes expected, observed, matched, and missing nameservers,
resolver errors, and `checked_at`. Admin-session tokens can force verification
with an audit reason when an operator intentionally overrides DNS observation:

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/nameservers/force-verify" \
  -H "Authorization: Bearer $ADMIN_SESSION" \
  -H 'Content-Type: application/json' \
  -d '{"reason":"registrar glue verified manually"}'
```

Force verification activates the domain, invalidates the edge snapshot, triggers
DNS reconciliation, and writes `domain.nameserver.force_verify` to audit history.

## Development And Validation

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd docs && npm ci && npm run docs:build
```

Smoke/e2e scripts use the root Compose stack:

```bash
docker compose up -d --build --wait
./ci/smoke.sh
./ci/e2e.sh
CDNLITE_EDGE_HEALTH_MODE=static ./ci/dns_e2e.sh
```

The DNS acceptance script uses the live bundled DNSGeo/PowerDNS stack.
Dashboard behavior is covered by typechecking, unit tests, a production build,
and operator-run manual QA.

The destructive production DNS qualification defaults to 10,000 domains with
1,000 records each and writes reports under `ci/reports/`:

```bash
./ci/stress-dns.sh
```

Use it only against a disposable stack.
See [DNS Stress Testing](docs/stress-testing.md) for prerequisites, reduced and
full commands, environment controls, pass criteria, reports, and recovery.

## Contribution Guidelines

- Create focused branches such as `feature/domain-routing` or `fix/edge-heartbeat-auth`.
- Keep code, tests, docs, examples, Compose, and CI aligned.
- Add focused tests for behavior changes.
- Update docs for API, CLI, config, environment, dashboard, edge, or operational behavior changes.
- Open pull requests with a clear summary, validation commands, and screenshots for dashboard changes.

## Security Guidelines

Do not use local defaults in shared deployments. Set `CDNLITE_API_TOKEN`, disable bootstrap admin/edge tokens, rotate edge tokens, protect `.env`, and place core/dashboard behind TLS and production authentication. See [docs/security.md](docs/security.md).

## License

CDNLite is released under the [MIT License](LICENSE).

## Contact And Support

Use GitHub Issues for bugs and support requests. Use GitHub Discussions if enabled for design questions, deployment ideas, and community help.
