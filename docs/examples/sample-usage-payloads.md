# Sample Usage Payloads

[Back to docs index](../index.md)

## Single Row

```json
{"idempotency_key":"usage-1","items":[{"ts":1710000000,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":1,"bytes_in":421,"bytes_out":2048,"status":200}]}
```

Response:

```json
{"ingested":1,"duplicate":false,"idempotency_key":"usage-1"}
```

## Multiple Statuses

```json
{"idempotency_key":"usage-2","items":[{"ts":1710000060,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":25,"bytes_in":9000,"bytes_out":120000,"status":200},{"ts":1710000060,"domain_id":"11111111-1111-4111-8111-111111111111","edge_node_id":"edge-local-1","requests_count":2,"bytes_in":800,"bytes_out":1200,"status":502}]}
```

Response:

```json
{"ingested":2,"duplicate":false,"idempotency_key":"usage-2"}
```

## Duplicate Idempotency Key

Retrying `usage-2` returns:

```json
{"ingested":0,"duplicate":true,"idempotency_key":"usage-2","item_count":2}
```

## Aggregate Summary Examples

After recalculate:

```json
{"ok":true,"domain_id":"11111111-1111-4111-8111-111111111111","inserted":{"minute":2,"hour":2,"day":2}}
```

Minute summary:

```json
{"data":{"bucket":"minute","requests_count":28,"bytes_in":10221,"bytes_out":123248,"records":2}}
```

Raw summary:

```json
{"data":{"requests_count":28,"bytes_in":10221,"bytes_out":123248,"records":3}}
```
