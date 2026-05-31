# Operations Runbook

## Daily Checks
- `GET /health` on core and edge
- Edge node list freshness (`/api/v1/edge/nodes`)
- Usage ingest activity (`/api/v1/usage/summary`)

## Core Commands
```bash
php core/artisan cdn:edge:list
php core/artisan cdn:edge:sync-config
php core/artisan cdn:usage:summary
php core/artisan cdn:usage:recalculate
```

## Incident: Edge Not Routing Host
1. Confirm site exists and `proxy_enabled=true`.
2. Confirm edge config version is up to date.
3. Check edge logs and `/var/lib/cdnlite/config.json`.
4. Validate origin reachability from edge container.

## Incident: Proxied DNS Not Updating After Edge IP Change
1. Confirm edge node `public_ip` and `status=online` via `GET /api/v1/edge/nodes`.
2. Confirm target DNS record is `type=A` and `proxied=true`.
3. Check core logs for refresh events/errors:
```bash
docker compose logs -f core
```
4. Verify PowerDNS zone rrsets directly:
```bash
docker compose exec -T core sh -lc 'curl -sk -H "X-API-Key: $POWERDNS_API_KEY" "$POWERDNS_API_URL/api/v1/servers/$POWERDNS_SERVER_ID/zones/<zone>."'
```
5. If strict mode is enabled (`POWERDNS_STRICT=1`), resolve upstream PowerDNS errors before retrying edge register/heartbeat.

## Incident: Edge Auth Failures
1. Validate token exists for edge id.
2. Validate timestamp skew on edge node.
3. Confirm nonce uniqueness per request.
4. Validate signature generation canonical string.

## Incident: Usage Missing
1. Check edge metrics file growth.
2. Check edge-agent `push_metrics.sh` execution.
3. Check collector API auth headers.
4. Verify usage summary endpoint reflects updates.

## Postgres Maintenance
- Monitor disk space and volume growth.
- Schedule logical backups.
- Test restore procedure periodically.
