# Sample Config Snapshot

[Back to docs index](../index.md)

A config snapshot is the JSON payload core returns from `GET /api/v1/edge/config` or `cdn:edge:sync-config`. The agent writes it to `/var/lib/cdnlite/config.json`, and OpenResty reads it on each routed request.

## Sample Returned JSON

```json
{
  "version": 1,
  "generated_at": 1710000000,
  "hosts": {
    "demo.local": {
      "site_id": "11111111-1111-4111-8111-111111111111",
      "upstream": "http://core:8080",
      "geo_upstreams": {
        "DEFAULT": "http://core:8080",
        "IR": "http://core:8080"
      },
      "headers": {
        "X-CDNLITE-Site": "11111111-1111-4111-8111-111111111111"
      },
      "dns_records": [
        {
          "id": "22222222-2222-4222-8222-222222222222",
          "site_id": "11111111-1111-4111-8111-111111111111",
          "type": "A",
          "name": "@",
          "content": "127.0.0.1",
          "ttl": 300,
          "priority": null,
          "proxied": true,
          "status": "active",
          "created_at": 1710000000,
          "updated_at": 1710000000
        }
      ]
    }
  },
  "cache_rules": [
    {
      "id": "44444444-4444-4444-8444-444444444444",
      "site_id": "11111111-1111-4111-8111-111111111111",
      "enabled": true,
      "path_prefix": "/api/v1/sites",
      "ttl_seconds": 60,
      "created_at": 1710000000,
      "updated_at": 1710000000,
      "host": "demo.local"
    }
  ]
}
```

## Versioning

Core hashes the `hosts` content. If the content is unchanged, it reuses the existing version and may include `"reused": true`. If `if_version` matches the current snapshot version, core can return:

```json
{"not_modified":true,"version":1}
```

`generated_at` is intentionally excluded from the content hash so no-op syncs do not create new versions.

`cache_rules` includes enabled rules across hosts. At the edge, the longest matching `path_prefix` for the request host is applied.
