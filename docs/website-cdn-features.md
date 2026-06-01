# Website CDN Features

[Back to docs index](index.md)

This page summarizes what CDNLite supports today for website CDN use, plus the near-term roadmap.

## Works Today

- Site management: create, list, update, delete.
- DNS records: create, list, update, delete per site.
- Edge proxying: host-based routing to origin from edge config snapshots.
- Redirect rules: CRUD for path-based redirects (status 301/302/307/308).
- Rate limiting: set/get/disable per-site limit rule.
- WAF rules: CRUD for custom rule entries.
- Cache rules: CRUD for path-prefix cache TTL rules.
- Edge agent sync: register, heartbeat, signed config pull, usage push.
- Usage summaries: query minute/hour/day aggregates and rebuild.

## Partially Implemented

- Edge caching exists with `X-CDNLITE-Cache` response signals and stale-on-error handling.
- Cache controls are rule-driven but still basic for production-grade policy needs.

## Planned Next (Simple-First)

- Control-plane bearer API auth for non-edge admin endpoints.
- Stronger payload validation across sites, DNS, and traffic rules.
- Cache purge APIs (likely soft-purge versioning first).
- Site-level cache defaults (not only path rules).
- Redirect priority and broader matching modes.
- SSL certificate metadata visibility (status/expiry tracking).
- Lightweight page-rules abstraction for common website actions.

## Explicitly Not First

- Kubernetes-only deployment model.
- Full enterprise RBAC/multi-account authorization model.
- Complex edge compute/workers model.
- Billing and monetization systems.
