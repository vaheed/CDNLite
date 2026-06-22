---
title: What Is CDNLite?
description: Learn how CDNLite works as a self-hosted CDN control plane, private CDN platform, OpenResty edge proxy, and PowerDNS/DNSGeo DNS workflow.
---

# What Is CDNLite?

CDNLite is a self-hosted private CDN control plane and edge platform. It gives operators a dashboard, API, DNS publishing workflow, edge proxy runtime, cache controls, WAF rules, SSL flows, analytics, and signed edge synchronization without relying fully on a public CDN vendor.

## The Short Version

CDNLite is for teams that want to run a private CDN or internal edge network with visible control over DNS, origins, edge nodes, security rules, and cache behavior.

It is built from:

- PHP control plane and CLI.
- PostgreSQL state.
- Vue admin dashboard.
- OpenResty/Lua edge proxy.
- Signed POSIX shell edge agent.
- PowerDNS and DNSGeo support.
- Docker Compose deployment.

## Where CDNLite Fits

CDNLite fits between raw Nginx/OpenResty configuration and a managed public CDN. It gives you more product structure than DIY scripts while keeping the deployment self-hosted and inspectable.

Good use cases include:

- Private company CDN for internal or controlled public services.
- Hosting provider edge service prototypes.
- DevOps and platform team edge routing.
- CDN, DNS, WAF, OpenResty, and PowerDNS labs.
- Controlled production experiments where operators own the hardening work.

## What It Does Not Replace

CDNLite is not a hyperscale public CDN replacement. It does not yet include native enterprise SSO/RBAC, full tenant isolation, billing, global managed infrastructure, or compliance certifications.

## Core Workflow

1. Add a domain.
2. Add origin servers.
3. Publish DNS records through PowerDNS/DNSGeo.
4. Register edge nodes.
5. Configure cache rules, purge workflows, WAF rules, rate limits, redirects, headers, and SSL.
6. Observe edge health, analytics, security events, and audit logs.

## Next Steps

- [Quickstart](./quickstart.md)
- [Private CDN](./private-cdn.md)
- [Architecture](./architecture.md)
- [Enterprise Readiness](./enterprise-readiness.md)
