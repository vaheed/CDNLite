---
title: CDNLite Roadmap
description: Roadmap for CDNLite private CDN, self-hosted CDN, enterprise hardening, observability, deployment, security, and community work.
---

# CDNLite Roadmap

CDNLite is evolving as a self-hosted private CDN control plane and edge platform. This roadmap is directional and does not promise specific release dates.

## Current Focus

- Reliable Docker Compose development and lab deployment.
- DNS reconciliation, PowerDNS visibility, and DNSGeo health behavior.
- Edge-agent signing, heartbeat, config sync, metrics, and security-event observability.
- Documentation for private CDN and controlled production deployment use cases.

## Near-Term

- Better first-domain, first-edge, first-cache-rule, and first-WAF-rule paths.
- More examples for origins, cache, WAF, rate limits, SSL, readiness checks, and split deployment.
- More tests around DNS writes, ALIAS/CNAME records, edge sync, SSL, WAF, and cache purge.
- Dashboard diagnostics for DNS, edge, ACME, and purge failures.

## Enterprise And Private Deployment Hardening

- RBAC.
- OIDC/SAML SSO.
- Scoped API keys.
- Stronger tenant isolation.
- Audit export.
- HA control plane documentation.
- Backup and restore automation.
- Policy-as-code.

## Observability

- Prometheus metrics.
- Grafana dashboards.
- Structured audit and security-event export.
- Edge capacity and cache efficiency views.
- Expanded runbooks.

## Security

- Signed release artifacts.
- Edge token rotation workflows.
- API keys with scopes.
- Rate-limit and WAF presets.
- Secret rotation guidance per topology.

## Deployment

- Helm chart and Kubernetes guide.
- Terraform examples.
- Edge autoscaling examples.
- Provider integrations.
- More split deployment examples.

## Community

- Contributor-friendly issues.
- More architecture notes for PHP, Vue, OpenResty/Lua, and PowerDNS.
- Good-first-issue labels when maintainers are ready to triage them.

## Not Planned Right Now

- Managed CDN service operations.
- Hyperscale public CDN parity claims.
- Billing workflows.
- Compliance certification claims.

## Next Steps

- [Enterprise Readiness](./enterprise-readiness.md)
- [Production Hardening](./production-hardening.md)
- [Documentation Home](./index.md)
