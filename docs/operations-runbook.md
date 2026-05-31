# Operations Runbook

[Back to docs index](index.md)

## Start, Stop, And Logs

```bash
docker compose up -d --build
docker compose ps
docker compose logs -f core
docker compose logs -f edge
docker compose down -v
```

## Health

```bash
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
docker compose exec postgres pg_isready -h 127.0.0.1 -p 5432 -U cdnlite -d cdnlite
```

## Database Connectivity

```bash
docker compose exec postgres psql -U cdnlite -d cdnlite -c 'select count(*) from sites;'
docker compose exec core php -r "require '/app/app/Support/bootstrap.php'; App\Support\Database::pdo(); echo 'ok';"
```

## Register An Edge

```bash
docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
docker compose exec edge-agent sh -lc '/agent/register.sh'
docker compose exec core php artisan cdn:edge:list
```

`cdn:edge:list` should show the detected `public_ip`. If `EDGE_PUBLIC_IP=auto`, heartbeat keeps this value current.

## Sync Config

```bash
docker compose exec core php artisan cdn:edge:sync-config
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
docker compose exec edge-agent sh -lc 'cat "$EDGE_CONFIG_PATH"'
```

## Validate Proxy Routing

```bash
curl -i -H 'Host: demo.local' http://localhost:8081/health
curl -i -H 'Host: unknown.local' http://localhost:8081/api/v1/sites
```

Unknown hosts should return 502.

## Validate DNS Records

```bash
curl -s http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records | jq
curl -s -X PATCH http://localhost:8080/api/v1/sites/11111111-1111-4111-8111-111111111111/dns/records/22222222-2222-4222-8222-222222222222 -H 'Content-Type: application/json' -d '{"ttl":120}' | jq
php core/artisan cdn:dns:list-records --site_id=11111111-1111-4111-8111-111111111111
```

## Ingest Sample Usage And Rebuild Summaries

```bash
php core/artisan cdn:usage:ingest --site_id=11111111-1111-4111-8111-111111111111 --edge_node_id=edge-local-1 --requests_count=10 --bytes_in=1000 --bytes_out=5000 --status=200 --idempotency_key=ops-1
php core/artisan cdn:usage:recalculate --site_id=11111111-1111-4111-8111-111111111111
php core/artisan cdn:usage:summary --site_id=11111111-1111-4111-8111-111111111111 --bucket=minute
```

## Incident Playbooks

| Incident | First checks | Common fix |
|---|---|---|
| Core unhealthy | `docker compose logs core`, DB readiness | Start PostgreSQL; fix DB env. |
| Edge 502 for known host | Config file, host spelling, origin reachability | Pull config; enable proxy; fix origin. |
| Edge auth 401 | Token registration, clock, signature path/body | Re-register token; sync clock; regenerate signature. |
| Replay 409 | Nonce reuse | Generate a fresh nonce per request. |
| Empty usage summary | Raw rollups, recalculate state | Push metrics; run recalculate for bucket summaries. |
| PowerDNS failures | Core logs, mock/API health, API key | Fix `POWERDNS_API_URL` and `POWERDNS_API_KEY`; disable strict for local work. |
