# CLI Reference

Entrypoint:
```bash
php core/artisan <command> [--options]
```

## Sites
- `cdn:site:create --name= --domain= --origin_host= --origin_port= [--geo_origins_json='{"US":{"scheme":"http","host":"us-origin","port":8080},"DEFAULT":{"scheme":"http","host":"core","port":8080}}']`
- `cdn:site:list`
- `cdn:site:update --id=... [--name=...] [--domain=...] [--geo_origins_json='{"DE":{"scheme":"http","host":"de-origin","port":8080}}'] ...`
- `cdn:site:delete --id=...`

## DNS
- `cdn:dns:add-record --site_id= --type= --name= --content= [--ttl=300] [--proxied=1]`
- `cdn:dns:list-records --site_id=`
- `cdn:dns:delete-record --site_id= --record_id=`

## Edge
- `cdn:edge:list`
- `cdn:edge:register-token --edge_id= --token=`
- `cdn:edge:rotate-token --edge_id=`
- `cdn:edge:sync-config [--if_version=<n>]`

## Usage
- `cdn:usage:ingest --site_id= --edge_node_id= --requests_count= --bytes_in= --bytes_out= --status= [--ts=] [--idempotency_key=]`
- `cdn:usage:summary [--site_id=] [--bucket=minute|hour|day]`
- `cdn:usage:recalculate [--site_id=]`

## Output Format
Commands return JSON payloads to STDOUT.

## Notes
- Use CLI for automation-friendly workflows.
- If `POWERDNS_ENABLED=1`, `cdn:dns:add-record` and `cdn:dns:delete-record` also sync changes to PowerDNS.
- Command list:
```bash
php core/artisan list
```
