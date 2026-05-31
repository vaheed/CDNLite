# DNS And PowerDNS

[Back to docs index](index.md)

## DNS Record Model

DNS records are stored in `dns_records` with `id`, `site_id`, `type`, `name`, `content`, `ttl`, `priority`, `proxied`, `status`, `created_at`, and `updated_at`. Records are deleted automatically when their site is deleted.

## API Workflow

```bash
curl -s -X POST http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"127.0.0.1","ttl":300,"proxied":true}'

curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 \
  -H 'Content-Type: application/json' \
  -d '{"content":"127.0.0.2","ttl":120}'

curl -s http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records
curl -s -X DELETE http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222
```

## CLI Workflow

```bash
php core/artisan cdn:dns:add-record --site_id=11111111-1111-4111-8111-111111111111 --type=A --name=@ --content=127.0.0.1 --proxied=1
php core/artisan cdn:dns:update-record --site_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222 --content=127.0.0.2 --ttl=120
php core/artisan cdn:dns:list-records --site_id=11111111-1111-4111-8111-111111111111
php core/artisan cdn:dns:delete-record --site_id=11111111-1111-4111-8111-111111111111 --record_id=22222222-2222-4222-8222-222222222222
```

## Proxied Behavior

`proxied` is persisted for every DNS record and included in config snapshots. Additional PowerDNS behavior exists for proxied `A` records:

- If active online edge nodes with valid IPv4 addresses exist, PowerDNS receives edge IPs instead of the origin content.
- Edge agent registration and heartbeat save the detected public IPv4 address in `edge_nodes.public_ip` automatically when `EDGE_PUBLIC_IP=auto`.
- If edge node regions are two-letter uppercase country codes, core can build a PowerDNS LUA record that returns an edge IP by country.
- If no active edge IPv4 exists, core logs a warning and syncs the record content as a normal A record.
- Proxied non-A records are stored but do not get special PowerDNS routing behavior.

## PowerDNS Sync

Set `POWERDNS_ENABLED=1` to enable sync. Set `POWERDNS_STRICT=1` to make local API/CLI operations fail if PowerDNS sync fails.

PowerDNS settings:

| Variable | Meaning |
|---|---|
| `POWERDNS_API_URL` | Base API URL. |
| `POWERDNS_API_KEY` | Sent as `X-API-Key`. |
| `POWERDNS_SERVER_ID` | Server path segment, default `localhost`. |
| `POWERDNS_ZONE_KIND` | `NATIVE`, `MASTER`, or `SLAVE`; invalid values fall back to `NATIVE`. |
| `POWERDNS_ZONE_NAMESERVERS` | Comma-separated nameservers for created zones. |

## Failure Modes

| Mode | Result |
|---|---|
| Missing API URL/key | PowerDNS result `powerdns_missing_config`. |
| API non-2xx | PowerDNS result `powerdns_api_error`. |
| Strict off | Local DB change remains; core logs an error. |
| Strict on | Site create rolls back/deletes local site on zone failure; DNS create returns/raises failure. |

TXT content is quoted before sending to PowerDNS if it is not already quoted. Record names are converted to FQDNs relative to the site domain.
