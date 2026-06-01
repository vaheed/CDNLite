# Website CDN Features

[Back to docs index](index.md)

This page shows what CDNLite website-CDN capabilities work today and what is planned.

## Available Now

- Site lifecycle management (`create/list/update/delete`).
- DNS record CRUD per site (with optional PowerDNS sync).
- Edge host-based proxying from config snapshots.
- Redirect rules (basic path-to-target URL rules).
- Site-level rate limit rule (basic model).
- WAF custom rules (basic model).
- Cache rules by path prefix and TTL.
- Edge caching with `X-CDNLITE-Cache` visibility.
- Edge stale-cache serving in selected origin-failure cases.
- Edge registration, heartbeat, config pull, and usage ingest with signed edge auth.
- Usage summary and aggregate rebuild endpoints.

## Partially Available

- Control-plane API auth: available via one bearer token (`CDNLITE_API_TOKEN`), optional unless configured.
- Caching controls: basic cache rules exist, but richer policy controls are pending.

## Planned (Roadmap)

- First-class cache purge API (soft purge/versioned namespace approach).
- Site cache settings (default TTL and cache policy controls).
- Redirects v2 (priority, richer matching, import/export, test endpoint).
- Page rules v1 (friendly URL pattern actions).
- SSL certificate metadata visibility and lifecycle checks.
- WAF and rate-limit model expansion for practical website protection.
- Small server-rendered dashboard for non-CLI operation.

## Not In Scope For Early Versions

- Full rules expression engine.
- Enterprise RBAC/billing stack.
- Anycast/global POP marketing promises.
- Workers-like edge compute runtime.
