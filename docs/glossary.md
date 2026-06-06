# Glossary

[Back to docs index](index.md)

| Term | Meaning |
|---|---|
| Core | PHP control plane in `core/`. |
| Edge | OpenResty data plane in `edge/openresty/`. |
| Edge agent | Shell loop in `edge/agent/` that signs calls to core. |
| Domain | A domain plus origin settings and proxy state. |
| DNS record | Domain-scoped record with origin type/content, public type/content, TTL, optional priority, and proxied flag. |
| Proxied | DNS flag stored by CDNLite; proxied subdomains publish canonical CNAME targets, while standard proxied apex records publish flattened healthy edge A/AAAA answers. |
| Origin | Upstream service the edge proxies to. |
| Geo origin | Country-keyed origin override stored on an individual DNS record. |
| Config snapshot | Versioned JSON payload mapping hostnames to upstream and DNS data. |
| Edge token | Secret used as bearer token and HMAC input for edge-authenticated endpoints. |
| Nonce | Unique per-edge request value stored temporarily to prevent replay. |
| HMAC | SHA-256 keyed signature over method, path, timestamp, nonce, and body hash. |
| Usage ingest | Signed API call that inserts traffic rows into `usage_rollups`. |
| Summary bucket | Rebuilt aggregate scope: `minute`, `hour`, or `day`. |
| PowerDNS | Optional external DNS API target for zone and record sync. |
