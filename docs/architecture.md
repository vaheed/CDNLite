# Architecture

[Back to docs index](index.md)

## System Diagram

```mermaid
flowchart LR
  Client[Client] --> Edge[OpenResty edge :8081]
  Edge --> Origin[Origin]
  Edge --> Metrics[/metrics.ndjson/]
  Agent[edge-agent] -->|signed register heartbeat config usage| Core[PHP core :8080]
  Agent --> Config[/config.json/]
  Core --> DB[(PostgreSQL)]
  Core -->|optional| PDNS[PowerDNS API]
  Config --> Edge
  Metrics --> Agent
```

## Request Flow

```mermaid
flowchart TD
  A[Request] --> B{Path /health?}
  B -->|yes| C[Return edge health]
  B -->|no| D[Normalize Host]
  D --> E[Load config.json]
  E --> F{Host configured?}
  F -->|no| G[502 custom error]
  F -->|yes| H[Pick country or default upstream]
  H --> I{Cache HIT or usable STALE?}
  I -->|yes| J[Serve cached response]
  I -->|no| K[Proxy to origin]
  K --> L[Store cacheable GET/HEAD response]
  J --> M[Add X-CDNLITE headers]
  L --> M
  M --> N[Append metric]
```

## Edge Registration And Config Sync

```mermaid
sequenceDiagram
  participant Operator
  participant Core
  participant Agent
  participant DB
  Operator->>Core: cdn:edge:register-token
  Core->>DB: upsert token hash
  Agent->>Core: POST /api/v1/edge/register signed
  Core->>DB: upsert edge_nodes
  Agent->>Core: GET /api/v1/edge/config signed
  Core->>DB: read sites, DNS, snapshots
  Core-->>Agent: snapshot JSON
  Agent->>Agent: write EDGE_CONFIG_PATH
```

## Usage Ingestion

```mermaid
sequenceDiagram
  participant Edge
  participant Agent
  participant Core
  participant DB
  Edge->>Edge: append metrics.ndjson
  Agent->>Core: POST /api/v1/collector/usage signed
  Core->>DB: insert usage_rollups
  Core->>DB: store idempotency key if present
  Operator->>Core: POST /api/v1/usage/recalculate
  Core->>DB: rebuild aggregates
```

## Failure Paths

| Failure | Behavior |
|---|---|
| Missing config | Edge loads version `0` with empty hosts. |
| Unknown host | Edge returns 502 custom error page. |
| Origin failure | Nginx serves a stale cached response when available; otherwise it maps 500/502/503/504 to custom HTML. |
| Missing edge auth | Core returns `401` and `edge_auth_required`. |
| Replay nonce | Core returns `409` and `edge_auth_replay_detected`. |
| Invalid usage bucket | Core returns `422` and `bucket_must_be_one_of_minute_hour_day`. |
| PowerDNS strict failure | API returns 502 or CLI exits non-zero. |

## Limitations And Assumptions

No dashboard, purge API, TLS automation, production scheduler, or user auth layer is implemented. Config updates are pull-based. Routing is by host, with optional country-based upstream selection from headers. The cache layer has a clean site-level `cache_rules` snapshot placeholder, but advanced purge and rule management are intentionally not implemented yet.
