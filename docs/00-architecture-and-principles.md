# Architecture and Principles

## Purpose
CDNLite provides a compact CDN control/data-plane split with operational simplicity as the primary design goal.

## Core Principles
- Keep modules explicit and readable.
- Keep API-first and CLI-first workflows for all key operations.
- Keep controllers thin and business logic in services.
- Keep edge fail-safe with last-known-good config behavior.
- Avoid heavyweight distributed patterns in v1.

## High-Level Architecture
- Control Plane (`core/`):
  - API endpoints for sites, DNS, edge registration/heartbeat/config, collector usage
  - CLI commands for all core operational paths
  - PostgreSQL as system-of-record
- Data Plane (`edge/openresty/`):
  - OpenResty Lua routing/proxy logic
  - local config load and host routing
  - metrics emission per request
- Edge Agent (`edge/agent/`):
  - register
  - heartbeat
  - pull config
  - push metrics

## Module Boundaries
Under `core/app/Modules/`:
- `Sites`
- `Dns`
- `Edge`
- `Proxy`
- `Collector`

## Runtime Request Flow
1. Client request arrives at edge.
2. Edge loads local config and matches `Host`.
3. If host exists and proxy enabled, edge forwards to origin.
4. If host not routable/upstream fails, edge returns status/error page.
5. Edge metrics are appended locally and pushed by agent to core collector.

## Control-Plane Flow
1. Edge authenticates with token + replay-protection headers.
2. Edge registers and heartbeats.
3. Edge polls config snapshots with optional `if_version`.
4. Core returns deterministic snapshot payload or not-modified response.

## Non-Goals (Current Scope)
- Kubernetes orchestration
- Multi-service decomposition
- Kafka/event bus
- Full WAF/cache-rule/rate-limit engine (phase 4+)
