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
- Site cache settings API (`GET/PUT /api/v1/sites/{site_id}/cache/settings`) for default cache policy controls.
- Cache purge API (`POST /cache/purge`, `GET /cache/purge-requests`).
- Redirects v2 (priority, match type, preserve query, import/export/test endpoints).
- Page rules v1 API (`create/list/update/delete/test`).
- SSL metadata APIs (`GET /ssl/certificates`, `POST /ssl/check`).
- Manual SSL certificate import API (`POST /ssl/manual-certificate`) with edge TLS runtime support.
- Edge caching with `X-CDNLITE-Cache` visibility.
- Edge stale-cache serving in selected origin-failure cases.
- Edge registration, heartbeat, config pull, and usage ingest with signed edge auth.
- Usage summary and aggregate rebuild endpoints.

## Partially Available

- Control-plane API auth: available via one bearer token (`CDNLITE_API_TOKEN`), optional unless configured.
- Control-plane API auth: available via one bearer token (`CDNLITE_API_TOKEN`), optional unless configured.
- SSL serving path: manual certificate import + SNI serving exists for v1, but key management/rotation hardening is still in progress.

## Planned (Roadmap)

- WAF and rate-limit model expansion for practical website protection.
- Small server-rendered dashboard for non-CLI operation.

## Not In Scope For Early Versions

- Full rules expression engine.
- Enterprise RBAC/billing stack.
- Anycast/global POP marketing promises.
- Workers-like edge compute runtime.
