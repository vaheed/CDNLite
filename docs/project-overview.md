# Project Overview

[Back to docs index](index.md)

CDNLite is a compact CDN control-plane and edge-runtime example. It solves the basic platform loop: define domains and DNS records, publish edge configuration, route traffic at the edge, collect request metrics, and summarize usage.

## Control Plane Vs Data Plane

The control plane is `core/`. It is authoritative for domains, DNS records, edge nodes, tokens, config versions, usage rollups, and aggregate summaries. It exposes HTTP APIs and CLI commands.

The data plane is `edge/openresty/`. It does not connect to PostgreSQL. It reads `/var/lib/cdnlite/config.json`, matches the request `Host`, selects an upstream, proxies the request, and writes metrics.

## Repository Roles

| Path | Role |
|---|---|
| `core/` | PHP API, CLI, database schema, services, controllers. |
| `edge/openresty/` | Nginx config and Lua modules for routing, proxying, errors, and metrics. |
| `edge/agent/` | Shell scripts that authenticate to core and sync edge state. |
| `core/database/schema.sql` | PostgreSQL tables. |
| `docker-compose.yml` | Local stack. |
| `ci/` | Smoke/e2e scripts and PowerDNS mock. |

## PostgreSQL State

PostgreSQL stores `domains`, `dns_records`, `edge_nodes`, `edge_tokens`, replay nonces, raw usage rollups, idempotency keys, aggregate buckets, config version state, and stored config snapshots.

## Config Sync

Core builds a snapshot from all proxy-enabled domains. Each host entry contains `domain_id`, default upstream, optional geo upstreams, headers, and DNS records. Snapshot top-level `cache_rules` contains enabled per-domain cache rules with `host`, `path_prefix`, and `ttl_seconds`. Snapshots are versioned by deterministic content hash. Unchanged content reuses a previous version.

## Usage Ingest

OpenResty appends one NDJSON metric per request. The agent converts the file into `{ "items": [...] }` and posts it to `/api/v1/collector/usage` with edge auth. Core stores rows in `usage_rollups`; recalculate commands rebuild minute/hour/day rows in `usage_aggregates`.
