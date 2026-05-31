# Glossary

[Back to docs index](index.md)

| Term | Meaning |
|---|---|
| Core | PHP control plane in `core/`. |
| Edge | OpenResty data plane in `edge/openresty/`. |
| Edge agent | Shell loop in `edge/agent/` that signs calls to core. |
| Site | A domain plus origin settings and proxy state. |
| DNS record | Site-scoped record with type, name, content, TTL, optional priority, and proxied flag. |
| Proxied | DNS flag stored by CDNLite; proxied A records get special PowerDNS edge-IP behavior when sync is enabled. |
| Origin | Upstream service the edge proxies to. |
| Geo origin | Country-keyed origin override stored in `geo_origins`. |
| Config snapshot | Versioned JSON payload mapping hostnames to upstream and DNS data. |
| Edge token | Secret used as bearer token and HMAC input for edge-authenticated endpoints. |
| Nonce | Unique per-edge request value stored temporarily to prevent replay. |
| HMAC | SHA-256 keyed signature over method, path, timestamp, nonce, and body hash. |
| Usage ingest | Signed API call that inserts traffic rows into `usage_rollups`. |
| Summary bucket | Rebuilt aggregate scope: `minute`, `hour`, or `day`. |
| PowerDNS | Optional external DNS API target for zone and record sync. |
