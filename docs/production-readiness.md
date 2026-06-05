# Production Readiness

[Back to docs index](index.md)

CDNLite is safe for local development and controlled test environments. It is not yet a hardened internet-facing control plane.

## Current Readiness Status

- Local/dev: supported.
- Internet-exposed control plane: not recommended unless API auth is enabled and secrets are rotated.
- Edge data plane: suitable for small controlled deployments with signed edge auth.

## Required Secrets

Set strong random values before any non-local deployment:

- `APP_KEY`
- `CDNLITE_API_TOKEN`
- `CDNLITE_EDGE_SHARED_SECRET`
- `CDNLITE_EDGE_INIT_TOKEN`
- `CDNLITE_PDNS_API_KEY` (when PowerDNS sync is enabled)

Do not keep default/example values in production.

## Control-Plane API Auth

Control-plane auth accepts either a static bearer token from `CDNLITE_API_TOKEN` or a dashboard admin session token created by `/api/v1/admin/login`. Static token auth is enabled by setting `CDNLITE_API_TOKEN`. Admin users are created with `php artisan cdn:admin:create`.

```http
Authorization: Bearer <token>
```

If `CDNLITE_API_TOKEN` is unset and no admin users exist, control-plane API routes are unauthenticated for local development. That is not safe for internet exposure. Create an admin user or set `CDNLITE_API_TOKEN` before exposing core.

## PowerDNS Risk Notes

When PowerDNS sync is enabled, API writes can propagate to DNS automatically.

- Use a scoped PowerDNS API key.
- Keep strict mode enabled only when you are ready to fail writes on DNS sync errors.
- Validate zone ownership and expected record targets before enabling proxied DNS behavior.

## TLS Status

CDNLite can issue end-user certificates with ACME DNS-01 when PowerDNS is enabled and the customer zone is managed by CDNLite.

- Use the production ACME directory only after validating against staging.
- Set `CDNLITE_ACME_DNS_PROPAGATION_SECONDS` high enough for your authoritative DNS path.
- Renewal scheduling and background retry queueing are not implemented yet; operators should reissue before expiry or run an external scheduler.

Manual certificate import and external TLS termination remain supported fallback paths.

## Cache/Purge Status

Current cache behavior:

- Basic OpenResty cache is implemented.
- `X-CDNLITE-Cache` header is emitted.
- Cache bypass behavior exists for non-cacheable requests.
- Stale-on-error behavior exists for origin failures.

Current gaps:

- No first-class control-plane purge API yet.
- No full domain-level cache policy model yet.

## Minimum Internet-Exposure Checklist

Before exposing the control plane publicly:

- Set all required secrets to strong random values.
- Set `CDNLITE_API_TOKEN` and enforce bearer auth for control-plane operations.
- Restrict core API access by network policy/firewall.
- Restrict PowerDNS API key scope and network path.
- Enable centralized logs and monitor edge auth failures.
