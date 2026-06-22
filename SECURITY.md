# Security Policy

CDNLite is a self-hosted private CDN control plane and edge platform. It includes security primitives such as signed edge sync, per-edge tokens, replay protection, API token authentication, audit logs, WAF rules, and rate limits, but production deployments still require careful hardening.

## Supported Versions

CDNLite is pre-1.0. Security fixes are expected to land on the default branch until release branches exist. Do not assume older commits or forks receive backported fixes unless a maintained release line is explicitly documented.

## Reporting A Vulnerability

Do not publish exploit details, secrets, tokens, private hostnames, customer data, or sensitive logs in a public issue.

No private disclosure email is currently documented in this repository. Open a minimal GitHub issue without exploit details and request a private disclosure channel.

Please include only:

- A short affected-area summary.
- The version, commit, or deployment mode affected.
- Whether the issue appears remotely exploitable.
- A safe contact path for follow-up if GitHub does not provide one.

## Responsible Disclosure Expectations

- Give maintainers reasonable time to investigate before public disclosure.
- Avoid accessing data that is not yours.
- Do not run destructive tests against systems you do not own.
- Do not include weaponized payloads in public reports.

## Local Defaults Warning

Default local credentials such as `admin` / `admin`, local API tokens, local edge tokens, and example secrets are for development only. Replace them before any shared, internet-facing, or production-like deployment.

## Production Hardening Checklist

- Put core API and dashboard behind TLS.
- Put the dashboard behind external authentication until native SSO/RBAC is implemented.
- Rotate `CDNLITE_API_TOKEN`, edge tokens, database passwords, PowerDNS API keys, ACME credentials, and signing secrets.
- Disable or replace bootstrap credentials.
- Restrict PostgreSQL, PowerDNS, Recursor, DNSGeo, and internal API access to trusted networks.
- Back up PostgreSQL and test restores.
- Enable log collection and monitor health endpoints, edge heartbeats, PowerDNS sync status, WAF events, and audit logs.
- Keep host OS, Docker, OpenResty, PHP dependencies, npm dependencies, and PowerDNS components patched.
- Review WAF and rate-limit rules before exposing critical workloads.

## Secret Handling

- Never commit `.env`, private keys, certificates, tokens, database dumps, or production logs.
- Use a secrets manager or deployment-specific secret injection for production.
- Treat edge tokens and API tokens as credentials with infrastructure access.
- Rotate secrets after employee departure, suspected compromise, or accidental exposure.

## Edge Token Rotation

Each edge should have its own token. Rotate tokens one edge at a time so you can verify heartbeat, config polling, metrics ingest, and security-event ingest before moving to the next edge.

## API Token Guidance

Use strong random API tokens, keep them out of shell history where possible, and scope network access around services that need automation. Native scoped API keys are on the roadmap.

## TLS Guidance

Use TLS for dashboard, API, and public edge traffic. For split deployments, use TLS or a trusted private network between edge nodes, core services, and DNS components.

## Dashboard External Auth

Until native OIDC/SAML SSO and enterprise RBAC are implemented, place the dashboard behind a trusted external authentication layer such as an identity-aware proxy, VPN, or private access gateway.

## PowerDNS API Exposure

The PowerDNS API can change DNS records. Do not expose it directly to the public internet. Bind it to trusted networks, rotate the API key, and monitor zone update activity.
