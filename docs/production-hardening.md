---
title: Production Hardening
description: Hardening checklist for running CDNLite as a self-hosted private CDN control plane with secure DNS, edge, dashboard, and core services.
---

# Production Hardening

Production hardening for CDNLite means treating the control plane, dashboard, PostgreSQL, PowerDNS API, DNSGeo, edge nodes, and secrets as sensitive infrastructure. CDNLite can support controlled production experiments, but operators must add the right external controls.

## Network Segmentation

- Keep PostgreSQL private to the core services that need it.
- Keep the PowerDNS API private; expose authoritative DNS only where required.
- Keep Recursor and DNSGeo on trusted networks.
- Allow edge nodes to reach the core API endpoints required for registration, heartbeat, config polling, metrics, and security-event ingest.
- Put dashboard and core API behind TLS and trusted ingress.

## Secret Rotation

Rotate these before shared use:

- `CDNLITE_API_TOKEN`
- Edge node tokens.
- PostgreSQL credentials.
- PowerDNS API key.
- ACME DNS credentials.
- TLS private keys and imported certificates.
- Any signing or bootstrap secrets in `.env`.

Store production secrets outside git.

## Dashboard Authentication

Native enterprise SSO/RBAC is not implemented yet. Put the dashboard behind an identity-aware proxy, VPN, SSO gateway, or trusted private access system before production use.

## TLS And Certificates

Use TLS for public dashboard/API access and public edge traffic. For split deployments, use TLS or trusted private networking between core, edge, and DNS components.

## Backup And Restore

Back up PostgreSQL on a schedule and test restores. Include configuration, `.env` secret inventory, imported certificates, and deployment manifests in your disaster recovery plan.

## Upgrade And Rollback

CDNLite is pre-1.0 and fresh-install-only. Before upgrading a running environment:

- Snapshot PostgreSQL.
- Export deployment configuration and secrets inventory.
- Run validation in a staging environment.
- Keep a rollback image or commit available.
- Verify health, DNS sync, edge heartbeats, SSL renewal, and dashboard login after changes.

## Monitoring

Monitor:

- `http://localhost:8080/health` for core.
- `http://localhost:8081/health` for edge in the local stack.
- Edge heartbeat age.
- PowerDNS sync status and errors.
- SSL expiry and ACME renewal failures.
- Security events, WAF blocks, rate-limit activity, and audit logs.
- Disk, memory, CPU, container restarts, and database growth.

## Validation Commands

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd docs && npm ci && npm run docs:build
```

## Next Steps

- [Security Model](./security.md)
- [Enterprise Readiness](./enterprise-readiness.md)
- [Deployment](./deployment.md)
- [Troubleshooting](./troubleshooting.md)
