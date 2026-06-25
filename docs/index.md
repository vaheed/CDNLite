---
title: CDNLite Documentation
description: Documentation for CDNLite, a self-hosted private CDN control plane with OpenResty, PowerDNS, DNSGeo, WAF rules, cache rules, SSL, analytics, and signed edge sync.
---

# CDNLite Documentation

CDNLite is a self-hosted private CDN control plane and edge platform for companies, hosting providers, internal infrastructure teams, labs, and controlled production deployments. It combines a PHP/PostgreSQL core, Vue dashboard, OpenResty/Lua edge proxy, signed edge agent, PowerDNS/DNSGeo, cache rules, WAF rules, rate limits, SSL workflows, analytics, and audit logs.

![CDNLite dashboard showing private CDN operations](./ScreenShot.png)

## New User Path

Start here when you want to understand the product and run your first private CDN workflow.

- [What is CDNLite?](./what-is-cdnlite.md)
- [CDN in a Minute](./cdn-in-a-minute.md)
- [Quickstart](./quickstart.md)
- [First domain, origin, cache rule, WAF rule, SSL, and edge examples](./examples/)

## Operator Path

Use these guides when you operate CDNLite in a lab, private network, or controlled production experiment.

- [Deployment](./deployment.md)
- [Production Hardening](./production-hardening.md)
- [DNS and Nameservers](./dns.md)
- [SSL and ACME](./security.md#tls-and-certificate-guidance)
- [Edge Nodes](./quickstart.md#register-an-edge-node)
- [Backup and Restore](./production-hardening.md#backup-and-restore)
- [Upgrade and Rollback](./production-hardening.md#upgrade-and-rollback)
- [Troubleshooting](./troubleshooting.md)
- [Runbooks](./runbooks/)

## Developer Path

Use these pages when you are changing the control plane, dashboard, edge runtime, or API.

- [Architecture](./architecture.md)
- [API Reference](./api/api.md)
- [OpenAPI YAML](./public/api/openapi.yaml)
- [Local Development](./setup.md)
- [Testing](./setup.md#validation)
- [Extending the Edge](./extensions.md)
- [Dashboard Development](./setup.md#dashboard)
- [Control Plane Development](./setup.md#core-api-and-cli)

## Enterprise And Private Deployment Path

These pages are written for teams evaluating CDNLite as a private CDN foundation.

- [Private CDN Use Cases](./private-cdn.md)
- [Security Model](./security.md)
- [Deployment Topologies](./deployment.md#deployment-topologies)
- [Network Segmentation](./production-hardening.md#network-segmentation)
- [External Authentication](./enterprise-readiness.md#external-controls-required)
- [Secret Rotation](./production-hardening.md#secret-rotation)
- [Current Enterprise Limitations](./enterprise-readiness.md#not-implemented-yet)
- [Enterprise Roadmap](https://github.com/vaheed/CDNLite/blob/main/docs/ROADMAP.md)

## What CDNLite Includes Today

- CDN control plane for domains, origins, DNS records, cache rules, purges, SSL, security rules, and operations.
- OpenResty CDN edge proxy with signed configuration sync through the edge agent.
- PowerDNS CDN publishing with DNSGeo support for private edge routing.
- WAF rules, rate limits, redirects, response headers, security events, and audit logs.
- Docker Compose deployment for the normal local topology and split deployment documentation.

## Current Limits

CDNLite does not yet include native enterprise RBAC, OIDC/SAML SSO, full multi-tenant isolation, billing, signed release artifacts, Helm packaging, or HA control plane automation. Use external controls and review [Enterprise Readiness](./enterprise-readiness.md) before production use.

## Next Steps

- Run [Quickstart](./quickstart.md).
- Read [Private CDN](./private-cdn.md) for positioning and topologies.
- Review [Production Hardening](./production-hardening.md) before shared deployment.
- Check the [Roadmap](https://github.com/vaheed/CDNLite/blob/main/docs/ROADMAP.md) for planned enterprise features.
