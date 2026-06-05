# Usage And Metrics

[Back to docs index](index.md)

## Edge Metric Fields

OpenResty writes one NDJSON row per completed request:

```json
{"ts":1710000000,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":1,"bytes_in":421,"bytes_out":2048,"status":200}
```

`domain_id` comes from the matched config host. `edge_node_id` is `EDGE_ID` or `edge-local-1`.

## Usage Ingest Payload

`POST /api/v1/collector/usage` requires signed edge auth and a JSON object containing `items` as an array. `idempotency_key` is optional and must be a non-empty string when present.

```json
{"idempotency_key":"batch-1","items":[{"ts":1710000000,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":10,"bytes_in":1000,"bytes_out":5000,"status":200}]}
```

Missing fields inside an item are not rejected by the controller; service defaults are used (`ts` now, strings empty, counts/status zero). Cache rows now default to `cache_status = UNKNOWN` when the edge does not send a status. In normal workflows, send all fields.

Items whose `domain_id` is empty or no longer exists are skipped so stale edge config cannot fail an entire batch. The response includes `skipped_unknown_domains` with the skipped item count.

## Idempotency

If a key is new, rows are inserted and the key is stored with item count. Retrying the same key returns:

```json
{"ingested":0,"duplicate":true,"idempotency_key":"batch-1","item_count":1}
```

The implementation does not compare duplicate payload contents; the key alone controls deduplication.

## Summaries

Without `bucket`, summaries read raw `usage_rollups`:

```json
{"data":{"requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

With `bucket=minute|hour|day`, summaries read `usage_aggregates`, include the bucket name, and return ordered graph points:

```json
{"data":{"bucket":"hour","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1,"points":[{"bucket_ts":1710000000,"requests_count":10,"bytes_in":1000,"bytes_out":5000}]}}
```

Points combine all aggregate dimensions for each timestamp, so status, cache status, and edge rows do not create duplicate graph labels.

## Recalculate Behavior

`POST /api/v1/usage/recalculate` or `cdn:usage:recalculate` deletes existing aggregate rows for the requested scope and rebuilds minute, hour, and day buckets from raw rollups. It returns inserted row counts per bucket.

```json
{"ok":true,"domain_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1}}
```

## Cache Analytics

`GET /api/v1/analytics/cache` returns cache-status breakdown rows and summary totals for all domains, and accepts `?domain_id=...` to scope the result to one domain. The domain-scoped route `/api/v1/domains/{id}/analytics/cache` returns the same payload for one domain.

```json
{
  "data": {
    "rows": [
      {"cache_status":"HIT","count":7,"bytes_out":70},
      {"cache_status":"MISS","count":3,"bytes_out":30},
      {"cache_status":"BYPASS","count":2,"bytes_out":20}
    ],
    "total_requests": 12,
    "bytes_out": 120,
    "hit": 7,
    "miss": 3,
    "expired": 0,
    "stale": 0,
    "bypass": 2,
    "unknown": 0,
    "hit_ratio": 0.7
  }
}
```

The dashboard hit ratio uses `HIT / (HIT + MISS + EXPIRED + STALE)` so bypass traffic stays out of the denominator.
