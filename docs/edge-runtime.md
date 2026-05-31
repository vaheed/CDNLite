# Edge Runtime

[Back to docs index](index.md)

The edge runtime is OpenResty from `edge/Dockerfile`, configured by `edge/openresty/nginx.conf` and Lua modules in `edge/openresty/lua/`.

## Nginx Behavior

- Listens on port `8081`.
- `/health` returns `{"ok":true}` directly from Nginx and does not require a configured host.
- Other paths run `router.handle()` in `access_by_lua_block`.
- `proxy_pass` uses `$target_upstream` set by Lua.
- `proxy_intercept_errors on` maps 500, 502, 503, and 504 to the custom error page.
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

Lua sets response headers `X-CDNLITE: 1`, `X-CDNLITE-Edge: openresty`, and `X-CDNLITE-Site` when proxying.

## Lua Modules

| Module | Purpose |
|---|---|
| `config_loader.lua` | Reads and decodes `/var/lib/cdnlite/config.json`; falls back to version 0 empty hosts. |
| `router.lua` | Host lookup and geo upstream selection. |
| `proxy.lua` | Sets `$target_upstream` and edge/site headers. |
| `metrics.lua` | Adds `X-CDNLITE` and appends NDJSON metrics on log phase. |
| `error_page.lua` | Renders custom HTML error responses. |
| `init.lua` | Loads config state; currently not wired from `nginx.conf`. |

## Metrics Lifecycle

`metrics.on_log()` writes JSON lines to `/var/lib/cdnlite/metrics.ndjson` with `ts`, `site_id`, `edge_node_id`, `requests_count`, `bytes_in`, `bytes_out`, and `status`. The agent batches and truncates this file after a successful usage push.

## Upstream Failures

If the origin is unreachable or returns an intercepted 5xx, the edge returns a custom HTML page with request ID, edge location, timestamp, client IP, and host. Unknown configured host failures are surfaced as 502.
