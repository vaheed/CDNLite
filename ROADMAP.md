# CDNLite Roadmap

This roadmap describes the direction for CDNLite as a self-hosted private CDN control plane. It is not a release commitment; priorities may change as the project matures.

## Current Focus

- Keep the Docker Compose developer and lab experience reliable.
- Improve DNS reconciliation, PowerDNS visibility, and DNSGeo health-aware routing.
- Keep edge-agent signing, heartbeat, metrics, and security-event flows observable.
- Improve documentation for private CDN, self-hosted CDN, and controlled production deployment use cases.

## Near-Term

- Clearer onboarding for first domain, first edge, first cache rule, and first WAF rule.
- More focused tests around DNS writes, edge sync, WAF/rate-limit behavior, and SSL workflows.
- Better dashboard diagnostics for DNS, edge health, cache purge, and ACME failures.
- More example configurations for origins, cache, WAF, rate limits, redirects, SSL, and split deployment.

## Enterprise And Private Deployment Hardening

- Role-based access control.
- Scoped API keys.
- OIDC and SAML SSO.
- Stronger tenant isolation boundaries.
- Audit export for SIEM and compliance workflows.
- HA control plane documentation.
- Backup and restore automation.
- Policy-as-code for repeatable security and cache policies.

## Observability

- Prometheus metrics.
- Grafana dashboard examples.
- More structured security-event and audit-log exports.
- Edge capacity and cache efficiency views.
- Runbooks for common failure modes.

## Security

- Signed release artifacts.
- Token rotation workflows in the dashboard and CLI.
- Rate-limit and WAF presets.
- Secret rotation guidance per deployment topology.
- Security review checklist for public-facing edge deployments.

## Deployment

- Helm chart and Kubernetes deployment guide.
- Terraform examples for private infrastructure.
- Edge autoscaling examples.
- Documented split core, edge, and DNS topologies.
- Network segmentation examples for enterprise-style deployments.

## Community

- Contributor-friendly issue templates.
- More architecture notes for PHP, Vue, OpenResty/Lua, and PowerDNS areas.
- Good-first-issue labels once maintainers are ready to triage them.
- More examples for hosting providers and internal platform teams.

## Not Planned Right Now

- Becoming a managed CDN service.
- Claiming hyperscale global CDN parity.
- Billing and commercial reseller workflows.
- Compliance certification claims.
- Replacing established public CDN vendors for every production use case.
