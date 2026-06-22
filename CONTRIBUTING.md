# Contributing To CDNLite

Thanks for helping improve CDNLite. The project is a self-hosted private CDN control plane with a PHP API, PostgreSQL database, Vue dashboard, OpenResty/Lua edge proxy, signed edge agent, and PowerDNS/DNSGeo support.

CDNLite is pre-1.0 and fresh-install-only. Keep changes honest, focused, and aligned across code, tests, docs, examples, Compose, and CI when behavior changes.

## Development Setup

```bash
cp .env.example .env
docker compose up -d --build
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
```

Open the local dashboard at `http://localhost:8082`. Local bootstrap credentials are only for local development.

## Branch Naming

Use short, scoped branch names:

- `feature/cache-rule-presets`
- `fix/edge-heartbeat-signature`
- `docs/private-cdn-guide`
- `test/powerdns-reconcile`

## Commit Style

- Keep commits focused on one logical change.
- Use imperative summaries, for example `Add edge token rotation docs`.
- Include tests and docs with the behavior they cover.
- Avoid unrelated formatting churn.

## Validation Commands

Run the commands relevant to your change:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd docs && npm ci && npm run docs:build
```

For runtime, deployment, DNS, or edge behavior, also consider:

```bash
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
sh -n edge/agent/push_metrics.sh
sh -n edge/agent/run.sh
bash -n ci/smoke.sh
bash -n ci/e2e.sh
bash -n ci/dns_e2e.sh
bash -n ci/powerdns_dns_checks.sh
```

Run destructive DNS stress tests only against disposable environments.

## Documentation Requirement

Update docs in the same change when you alter user-visible behavior, API contracts, CLI commands, environment variables, deployment topology, dashboard workflows, DNS behavior, edge behavior, or security posture.

Useful docs entry points:

- [README.md](README.md)
- [docs/index.md](docs/index.md)
- [docs/quickstart.md](docs/quickstart.md)
- [docs/deployment.md](docs/deployment.md)
- [docs/security.md](docs/security.md)
- [docs/examples/index.md](docs/examples/index.md)

## Pull Request Checklist

- The summary explains what changed and why.
- Validation commands are listed with pass/fail results.
- Dashboard changes include screenshots or a short note explaining why screenshots are not needed.
- Security and deployment impact are documented.
- Docs and examples are updated where behavior changed.
- Breaking changes are called out clearly.
- New limitations are documented instead of hidden.

## Issue Reporting

For bugs, include:

- Expected behavior.
- Actual behavior.
- Reproduction steps.
- Relevant service logs with secrets removed.
- Environment details such as Compose topology, browser, PHP version, or edge OS when relevant.

For feature requests, describe the use case, target user, operational impact, and any security concerns.

For security vulnerabilities, follow [SECURITY.md](SECURITY.md) and do not include exploit details in public issues.

## Areas Where Help Is Welcome

- Documentation and examples.
- Test coverage and CI reliability.
- OpenResty/Lua edge proxy behavior.
- Vue dashboard workflows.
- PHP control plane and PostgreSQL modeling.
- Security hardening and threat modeling.
- Deployment examples for split environments.
- Kubernetes, Helm, and Terraform.
- Prometheus, Grafana, and operational dashboards.
- RBAC, scoped API keys, and SSO.
