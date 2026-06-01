# Edge Runtime

[Back to docs index](index.md)

The edge runtime is OpenResty from `edge/Dockerfile`, configured by `edge/openresty/nginx.conf` and Lua modules in `edge/openresty/lua/`.

## Nginx Behavior

- Listens on port `8081`.
- `/health` returns `{"ok":true}` directly from Nginx and does not require a configured host.
- Other paths run `router.handle()` in `access_by_lua_block`.
- `proxy_pass` uses `$target_upstream` set by Lua.
- `proxy_intercept_errors on` maps 500, 502, 503, and 504 to the custom error page.
- `proxy_cache_path` stores cached objects under `/var/cache/cdnlite` in the `cdnlite_cache` zone.
- `proxy_cache_key` includes scheme, host, request URI, `Accept-Encoding`, `X-CDNLITE-Country`, and `CF-IPCountry`.
- Cache lookup/storage is enabled only when a matching enabled cache rule exists for the host and request path prefix.
- Cache rule matching uses longest `path_prefix` match from snapshot `cache_rules` entries for that host.
- GET and HEAD responses with status 200, 301, or 302 are cached using the matched rule `ttl_seconds`.
- Requests with `Authorization` or `Cache-Control: no-cache` / `no-store` bypass cache and are not stored.
- Stale cached responses can be served for upstream errors, timeouts, and upstream 500, 502, 503, or 504 responses. `proxy_cache_lock` is enabled to reduce duplicate origin fetches on cache misses.
- Access logs go to `/var/log/openresty/access.log`; error logs go to `/var/log/openresty/error.log`.

## Host-Based Routing

`router.lua` lowercases the request host and strips any port. It loads `/var/lib/cdnlite/config.json`, looks up `hosts[host]`, chooses an upstream, and calls `proxy.forward(site)`. Missing host or unknown host returns 502.

## Geo Upstreams

The router checks `X-CDNLITE-Country` first, then `CF-IPCountry`. If a matching country exists in `geo_upstreams`, that upstream is used. Otherwise `DEFAULT` is used when present; otherwise the site `upstream` is used.

## Proxy Headers

Nginx forwards:

- `Host: $host`
- `X-Forwarded-For: $proxy_add_x_forwarded_for`
- `X-Forwarded-Proto: $scheme`

Lua sets response headers `X-CDNLITE: 1`, `X-CDNLITE-Edge: openresty`, `X-CDNLITE-Site`, and `X-CDNLITE-Request-Id` when proxying.
Nginx adds `X-CDNLITE-Cache` with the upstream cache status, such as `MISS`, `HIT`, `BYPASS`, or `STALE`.

## Lua Modules

| Module | Purpose |
|---|---|
| `config_loader.lua` | Reads and decodes `/var/lib/cdnlite/config.json`; enforces `schema_version=1`; falls back to version 0 empty hosts. |
| `router.lua` | Host lookup, geo upstream selection, and request-id context setup. |
| `proxy.lua` | Sets `$target_upstream`, cache bypass variables, per-rule cache TTL, and edge/site headers. |
| `metrics.lua` | Adds `X-CDNLITE` and appends NDJSON metrics on log phase, including `request_id`. |
| `error_page.lua` | Renders custom HTML error responses. |

## Metrics Lifecycle

`metrics.on_log()` writes JSON lines to `/var/lib/cdnlite/metrics.ndjson` with `ts`, `site_id`, `edge_node_id`, `requests_count`, `bytes_in`, `bytes_out`, `status`, and `request_id`. The agent batches and truncates this file after a successful usage push.

## Upstream Failures

If the origin is unreachable or returns an intercepted 5xx, the edge serves a stale cached response when one is available. Without a cached response, it returns a custom HTML page with request ID, edge location, timestamp, client IP, and host. Unknown configured host failures are surfaced as 502.
