---
title: Enterprise Readiness
description: Honest readiness guidance for using CDNLite as a private CDN foundation in company, hosting provider, and controlled production environments.
---

# Enterprise Readiness

CDNLite is a production-oriented private CDN foundation, but it is not yet a complete enterprise CDN platform. This page explains what is ready today, what needs external controls, and what is still on the roadmap.

## Ready Today

- Self-hosted CDN control plane with domains, origins, DNS records, proxy toggles, cache settings, cache rules, purges, WAF rules, rate limits, redirects, response headers, SSL workflows, analytics, and audit logs.
- OpenResty/Lua edge proxy and signed edge-agent sync.
- PowerDNS and DNSGeo support for private edge routing.
- Docker Compose topology for local and controlled deployments.
- Health APIs, readiness commands, troubleshooting docs, and runbooks.

## External Controls Required

Use external controls for production-like deployments:

- Put dashboard access behind SSO, VPN, identity-aware proxy, or another trusted external auth layer.
- Segment PostgreSQL, PowerDNS API, Recursor, DNSGeo, and core internal ports.
- Use TLS for dashboard, API, edge, and internal service boundaries where traffic crosses untrusted networks.
- Store secrets outside the repository and rotate them operationally.
- Use infrastructure monitoring for host health, disk usage, service restarts, DNS behavior, and certificate expiry.

## Not Implemented Yet

- Native RBAC.
- OIDC/SAML SSO.
- Full multi-tenant isolation.
- Billing and reseller workflows.
- Scoped API keys.
- Signed release artifacts.
- Helm chart and Kubernetes production guide.
- HA control plane automation.
- Built-in Prometheus and Grafana dashboards.

## Recommended Production Architecture

- Run core API, dashboard, and PostgreSQL on a protected management network.
- Expose only required dashboard/API entry points through TLS and external authentication.
- Keep PowerDNS API private; expose authoritative DNS only as needed.
- Run edge nodes on separate hosts or networks with per-edge tokens.
- Use firewalls so edge nodes can reach required core endpoints, but databases and DNS admin APIs remain private.
- Back up PostgreSQL and test restore procedures.

## Private Company Deployment Checklist

- [ ] Replace all local bootstrap credentials.
- [ ] Rotate API, edge, database, PowerDNS, ACME, and signing secrets.
- [ ] Put dashboard behind external authentication.
- [ ] Enable TLS for public and internal service boundaries.
- [ ] Restrict PostgreSQL and PowerDNS API network access.
- [ ] Configure backups and restore drills.
- [ ] Monitor health endpoints, edge heartbeats, DNS sync, SSL renewals, and security events.
- [ ] Review current limitations with stakeholders before routing critical traffic.

## Security Checklist

- [ ] Use per-edge tokens.
- [ ] Rotate tokens after exposure or role changes.
- [ ] Keep `.env` and certificates out of git.
- [ ] Review WAF and rate-limit rules in staging first.
- [ ] Keep host OS, Docker, PHP, npm, OpenResty, and PowerDNS patched.

## Operational Checklist

- [ ] Document ownership for core, DNS, edge, and dashboard.
- [ ] Define rollback and incident response steps.
- [ ] Test domain onboarding and offboarding.
- [ ] Test cache purge and SSL renewal.
- [ ] Run smoke/e2e checks after deployment changes.

## Enterprise Roadmap

Planned and desired work includes RBAC, OIDC/SAML, scoped API keys, tenant isolation, audit export, Prometheus metrics, Grafana dashboards, Helm charts, backup/restore automation, HA control plane docs, policy-as-code, provider integrations, WAF/rate-limit presets, and signed release artifacts.

## Next Steps

- [Production Hardening](./production-hardening.md)
- [Security Model](./security.md)
- [Deployment](./deployment.md)
- [Roadmap](./ROADMAP.md)
