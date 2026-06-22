---
title: Private CDN
description: How a private CDN, self-hosted CDN, internal CDN, OpenResty CDN, and PowerDNS CDN can help companies run controlled edge routing.
---

# Private CDN

A private CDN is an edge delivery layer operated by your own company, hosting platform, lab, or infrastructure team. CDNLite provides a self-hosted CDN control plane for private edge networks built with OpenResty, PowerDNS, DNSGeo, cache rules, WAF rules, SSL workflows, and signed edge sync.

## Why A Company May Want A Private CDN

Companies may choose a private CDN when they need:

- More control over edge routing, logs, and policy.
- Internal CDN behavior for private applications.
- A company CDN for regulated or isolated infrastructure.
- Hosting provider edge services without building every workflow from scratch.
- OpenResty CDN and PowerDNS CDN learning environments.
- A private edge network that can run in a lab, private cloud, or controlled data center.

## Where CDNLite Fits

CDNLite gives operators a private CDN foundation:

- Dashboard and API for domains, origins, DNS, cache, WAF, SSL, and analytics.
- OpenResty/Lua edge proxy for request handling.
- PowerDNS and DNSGeo for private edge routing.
- Signed edge agent for heartbeat, config, metrics, and security events.

It is more structured than DIY scripts, but it is still self-operated software. You own hardening, monitoring, backups, TLS, secret rotation, and authentication boundaries.

## Example Topologies

**Single-host lab:** core, dashboard, PostgreSQL, PowerDNS, DNSGeo, Recursor, and edge run through Docker Compose on one host.

**Private company deployment:** core/dashboard/database run on an internal management network, PowerDNS is exposed only where DNS requires it, and edge nodes sit near applications or users.

**Hosting provider lab:** provider runs central core/DNS services and registers multiple edge nodes for customer domain experiments. Full tenant isolation and billing are roadmap items, not current claims.

## Limitations

CDNLite does not yet provide native enterprise RBAC, OIDC/SAML SSO, full tenant isolation, billing, signed release artifacts, Helm packaging, or HA control plane automation.

For production, place the dashboard behind external authentication, restrict internal services, rotate secrets, back up PostgreSQL, and monitor DNS and edge health.

## Getting Started

- [Quickstart](./quickstart.md)
- [Deployment](./deployment.md)
- [Production Hardening](./production-hardening.md)
- [Enterprise Readiness](./enterprise-readiness.md)
