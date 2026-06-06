# DNS And PowerDNS

[Back to docs index](index.md)

## DNS Record Model

DNS records are stored in `dns_records` with origin and public DNS fields. `type` and `content` keep the customer origin input. `origin_type` and `origin_content` mirror that origin explicitly. `public_type` and `public_content` hold the record CDNLite publishes to PowerDNS. Records are deleted automatically when their domain is deleted.

## API Workflow

```bash
curl -s -X POST http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}'

curl -s -X PATCH http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 \
  -H 'Content-Type: application/json' \
  -d '{"content":"127.0.0.2","ttl":120}'

curl -s http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records
curl -s -X DELETE http://localhost:8080/api/v1/domains/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222
```

## CLI Workflow

```bash
php core/artisan cdn:dns:add-record --domain_id=11111111-1111-4111-8111-111111111111 --type=A --name=@ --content=127.0.0.1 --proxied=1
php core/artisan cdn:dns:update-record --domain_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222 --content=127.0.0.2 --ttl=120
php core/artisan cdn:dns:list-records --domain_id=11111111-1111-4111-8111-111111111111
php core/artisan cdn:dns:delete-record --domain_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222
```

## Proxied Behavior

`proxied` is persisted for every DNS record and included in config snapshots. Customer zones never receive edge IP pools or customer-specific LUA records.

- `proxied=false`: PowerDNS receives the normal customer record as entered.
- `proxied=true` at the apex/root: PowerDNS receives `ALIAS <policy-target>`.
- `proxied=true` below the apex: PowerDNS receives `CNAME <policy-target>`.
- The default policy target is `geo.edge.<CDNLITE_EDGE_BASE_DOMAIN>`.

## Per-domain routing

Each domain has routing settings available at `GET/PATCH /api/v1/domains/{id}/routing`.

- `geo`: proxied A/AAAA records publish PowerDNS LUA `ifportup` records containing all online, enabled edge addresses. Before an eligible edge exists, the origin record remains published and previews report `no_eligible_edge_ips`; edge registration republishes it automatically.
- `anycast`: proxied records publish the configured anycast IPv4/IPv6 address, or the configured CNAME for subdomains.
- `dns_only`: records are published exactly as entered.

Use `POST /api/v1/domains/{id}/dns/records/{recordId}/preview-routing` to inspect the generated PowerDNS record before changing a DNS record. Routing changes recalculate and republish every record in the domain.
- The origin record remains stored for routing/config purposes, but public DNS points only to stable CDNLite edge hostnames.

## Edge Routing Zone

CDNLite owns one base DNS zone, configured by `CDNLITE_EDGE_BASE_DOMAIN`. With the default `vaheed.net` base and `edge` prefix, the platform manages records such as:

- `edge.vaheed.net`
- `geo.edge.vaheed.net`
- `ir.edge.vaheed.net`
- `eu.edge.vaheed.net`
- `us.edge.vaheed.net`
- `p-<policy-hash>.edge.vaheed.net`

Only this platform zone contains LUA records and health-checked A/AAAA pools. Edge registration and heartbeat update `edge_nodes`, then recompute the platform edge zone only. Customer zones are not rewritten when edge IPs change.

## PowerDNS Sync

Use **Settings → PowerDNS** to enable synchronization and strict failure handling.

PowerDNS settings:

| Variable | Meaning |
|---|---|
| Setting | Purpose |
|---|---|
| API URL | Base API URL. |
| API key | Secret sent as `X-API-Key`; never returned by the API. |
| Server ID | Server path segment, default `localhost`. |
| Zone kind | `NATIVE`, `MASTER`, or `SLAVE`; invalid values fall back to `NATIVE`. |
| Nameservers tab | Authoritative nameservers for created zones. |

Edge DNS settings:

| Variable | Meaning |
|---|---|
| `CDNLITE_EDGE_BASE_DOMAIN` | Platform-owned base zone, default `vaheed.net`. |
| `CDNLITE_EDGE_ZONE_PREFIX` | Edge hostname prefix, default `edge`. |
| `CDNLITE_EDGE_DEFAULT_TARGET` | Default policy label, default `geo`. |
| `CDNLITE_EDGE_TTL` | TTL for platform edge records. |
| `CDNLITE_EDGE_HEALTH_MODE` | `ifportup`, `ifurlup`, or `static`. |
| `CDNLITE_EDGE_HEALTH_PORT` | Health port, default `80`. |
| `CDNLITE_EDGE_HEALTH_URL` | HTTP health path for `ifurlup`. |
| `CDNLITE_EDGE_SELECTOR` | PowerDNS selector, default `pickclosest`. |
| `CDNLITE_EDGE_BACKUP_SELECTOR` | Backup selector, default `empty`. |
| `CDNLITE_EDGE_APEX_MODE` | Apex projection mode, default `ALIAS`. |

PowerDNS must be configured with `enable-lua-records=yes`, a resolver that does not point back to the same PowerDNS instance, and `expand-alias=yes` for ALIAS support.

## Failure Modes

| Mode | Result |
|---|---|
| Missing API URL/key | PowerDNS result `powerdns_missing_config`. |
| API non-2xx | PowerDNS result `powerdns_api_error`. |
| Strict off | Local DB change remains; core logs an error. |
| Strict on | Domain create rolls back/deletes local domain on zone failure; DNS create returns/raises failure. |

TXT content is quoted before sending to PowerDNS if it is not already quoted. Record names are converted to FQDNs relative to the domain domain.
