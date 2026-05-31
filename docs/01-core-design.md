# Core Design

## Runtime Stack
- Language: PHP
- DB: PostgreSQL
- Entrypoint: `core/public_index.php`
- CLI Entrypoint: `core/artisan`

## Core Responsibilities
- Site and DNS lifecycle
- Edge identity and control-plane auth
- Config snapshot build/version lifecycle
- Usage ingest, summary, and aggregate rebuild
- PowerDNS synchronization (zone auto-create, DNS sync, proxied edge-IP publishing)

## Data Model (Implemented)
- `sites`
- `dns_records`
- `edge_nodes`
- `edge_tokens`
- `edge_request_nonces`
- `usage_rollups`
- `usage_ingest_keys`
- `usage_aggregates`
- `config_state`
- `config_snapshots`

Schema source of truth:
- `core/database/schema.sql`

## Config Snapshot Model
- Snapshot content is deterministic by host map payload.
- Content hash prevents unnecessary version increments.
- `if_version` allows no-change polling response.

## Authentication Model
Edge control/collector endpoints enforce:
- bearer token validation per edge id
- timestamp skew validation
- nonce uniqueness (replay detection)
- request signature validation

## CLI Contract
`core/artisan` exposes operational commands for sites, dns, edge, and usage paths.
See full reference in `docs/05-cli-reference.md`.

## Error Handling Model
- Validation returns `422` with explicit error code.
- Missing resources return `404`.
- Edge auth/replay failures return auth-specific errors (`401`, `409`).
- Upstream PowerDNS sync failures return `502` with `powerdns_api_error` (strict-mode behavior applies).

## Extensibility Direction
Future extension modules (phase 4):
- redirects
- cache rules
- WAF hooks
- rate limiting hooks
