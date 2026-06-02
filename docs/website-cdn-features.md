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
- ACME DNS-01 certificate issuance via PowerDNS (`POST /ssl/acme/issue`).
- SSL metadata APIs (`GET /ssl/certificates`, `POST /ssl/request`, `POST /ssl/check`).
- Manual SSL certificate import API (`POST /ssl/manual-certificate`) with edge TLS runtime support.
- Edge caching with `X-CDNLITE-Cache` visibility.
- Edge stale-cache serving in selected origin-failure cases.
- Edge registration, heartbeat, config pull, and usage ingest with signed edge auth.
- Usage summary and aggregate rebuild endpoints.

## Partially Available

- Control-plane API auth: available via static bearer token (`CDNLITE_API_TOKEN`) or dashboard admin sessions.
- Static Vue admin dashboard for non-CLI operation.
- ACME issuance works synchronously with DNS-01 and PowerDNS. Renewal scheduling, alternate ACME providers/challenge types, key rotation hardening, and retry queueing are still in progress.

## Planned (Roadmap)

- WAF and rate-limit model expansion for practical website protection.

## Not In Scope For Early Versions

- Full rules expression engine.
- Enterprise RBAC/billing stack.
- Anycast/global POP marketing promises.
- Workers-like edge compute runtime.
