# Usage And Metrics

[Back to docs index](index.md)

## Edge Metric Fields

OpenResty writes one NDJSON row per completed request:

```json
{"ts":1710000000,"site_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":1,"bytes_in":421,"bytes_out":2048,"status":200}
```

`site_id` comes from the matched config host. `edge_node_id` is `EDGE_ID` or `edge-local-1`.

## Usage Ingest Payload

`POST /api/v1/collector/usage` requires signed edge auth and a JSON object containing `items` as an array. `idempotency_key` is optional and must be a non-empty string when present.

```json
{"idempotency_key":"batch-1","items":[{"ts":1710000000,"site_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":10,"bytes_in":1000,"bytes_out":5000,"status":200}]}
```

Missing fields inside an item are not rejected by the controller; service defaults are used (`ts` now, strings empty, counts/status zero). In normal workflows, send all fields.

Items whose `site_id` is empty or no longer exists are skipped so stale edge config cannot fail an entire batch. The response includes `skipped_unknown_sites` with the skipped item count.

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

With `bucket=minute|hour|day`, summaries read `usage_aggregates` and include the bucket name:

```json
{"data":{"bucket":"hour","requests_count":10,"bytes_in":1000,"bytes_out":5000,"records":1}}
```

## Recalculate Behavior

`POST /api/v1/usage/recalculate` or `cdn:usage:recalculate` deletes existing aggregate rows for the requested scope and rebuilds minute, hour, and day buckets from raw rollups. It returns inserted row counts per bucket.

```json
{"ok":true,"site_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":1,"hour":1,"day":1}}
```
