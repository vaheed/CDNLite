# Lite CDN Architecture and Principles

## Scope
This project implements a lightweight CDN core with:
- Site/domain management
- PowerDNS record management
- Proxy mode toggle (proxied vs DNS-only)
- Edge node registration + heartbeat
- Edge config snapshot generation
- OpenResty/Lua proxy routing
- Usage metric ingestion + query

Out of scope for v1:
- WAF, redirects, cache rules, rate limiting, bot protection, billing/invoicing

## Design principles
- Database is source of truth
- API-first and CLI-first for all core operations
- Thin controllers; business logic in services
- Small modules inside one Laravel app (no microservices)
- Edge pulls versioned config snapshots and keeps last-known-good locally
- Keep operations simple for a small team

## High-level components
1. Laravel Core
- Auth + users (existing billing system assumed external)
- Modules: Sites, Dns, Edge, Proxy, Collector, Core
- PowerDNS client only inside core
- Config snapshot builder

2. Edge Node
- OpenResty for request handling
- Lua modules for routing, config loading, proxying, metrics
- Lightweight agent for register/heartbeat/config pull/metrics push
- Local persisted config cache

3. PowerDNS
- Authoritative DNS management via API

## Data flow
1. User adds site + origin in Core.
2. Core creates/updates DNS zone/records in PowerDNS.
3. If `proxied=true`, DNS points to edge pool IPs; else points to origin.
4. Edge agent registers node and heartbeats periodically.
5. Edge agent pulls config snapshot version N.
6. OpenResty routes requests using local snapshot and proxies to origin.
7. Edge reports aggregated usage metrics to collector API.
8. Core exposes usage via API and Artisan commands.
