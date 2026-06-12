# CDNLite

[![CI](https://github.com/vaheed/CDNLite/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/vaheed/CDNLite/actions/workflows/ci.yml)
[![Docker](https://img.shields.io/badge/docker-compose-blue)](docker-compose.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

CDNLite is a lightweight CDN platform with a PHP control plane, PostgreSQL database, Vue admin dashboard, OpenResty/Lua edge proxy, and signed shell-based edge agent. It is useful for CDN learning, local labs, small private edge deployments, and testing CDN operations workflows.

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
- Vue dashboard for operations, domains, edge network, analytics, snapshots, events, audit log, and settings.
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
```

See the [User Guide](docs/usage/user.md), [Admin Guide](docs/usage/admin.md), and [API Reference](docs/api/api.md).

## Documentation

The documentation site is built with VitePress from `docs/`.

- [Documentation Home](docs/index.md)
- [CDN In A Minute](docs/cdn-in-a-minute.md)
- [Setup](docs/setup.md)
- [OpenAPI YAML](docs/public/api/openapi.yaml)
- [Architecture](docs/architecture.md)
- [Extensions](docs/extensions.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Security](docs/security.md)
- [Operations Runbooks](docs/runbooks/index.md)
- [Examples](docs/examples/index.md)
- [Use Cases](docs/use-cases/index.md)
- [Best Practices](docs/best-practices/index.md)

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
```

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
