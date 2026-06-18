# Troubleshooting

## Quick Diagnostics

```bash
docker compose ps
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8080/ready
curl -fsS http://localhost:8081/health
docker compose logs --tail=120 core
docker compose logs --tail=120 edge
docker compose logs --tail=120 edge-agent
```

If an edge 5xx page shows `openresty/<version>` or `nginx/<version>`, or includes
a `Server` response header, rebuild and restart the edge image. The maintained
configuration removes server identity from HTTP, HTTPS, proxied, and generated
error responses; a visible signature usually means a stale edge container is
still running.

Use this order during incidents:

1. Confirm containers are running.
2. Check core `/ready`.
3. Check edge `/health`.
4. Check config snapshot and edge sync status.
5. Check origin health.
6. Check security events for blocks/challenges.
7. Check metrics queues and collector ingest.

## Common Issues

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| Dashboard cannot reach core | `VITE_CDNLITE_CORE_URL` points at an internal Compose hostname or CORS blocks origin. | Set a browser-reachable URL, rebuild dashboard, and include dashboard origin in `CDNLITE_CORS_ALLOWED_ORIGINS`. |
| Login fails with known credentials | Bootstrap disabled or admin password changed. | Create a user with `cdn:admin:create` or verify bootstrap variables before first boot. |
| `/ready` reports `api_token` warn | `CDNLITE_API_TOKEN` is empty in local mode. | Accept for local dev, set a strong token for production. |
| `/ready` reports schema failure | Canonical schema bootstrap failed or the database is not disposable. | Inspect Core/PostgreSQL logs, then rebuild a fresh stack with `docker compose down -v && docker compose up -d --build`. |
| Edge returns unknown host page | Host is not in `config.json` or edge has stale config. | Activate the domain, rebuild snapshot, and confirm edge agent pulled config. |
| Edge agent auth fails | Edge token mismatch, bad timestamp, reused nonce, or signature mismatch. | Re-register/rotate token, check system clock, and run `edge/agent/doctor.sh`. |
| Dashboard reports `edge_not_healthy` after startup | The edge has not completed a successful signed heartbeat in the last 90 seconds. | Check `edge-agent` logs and run the heartbeat script; HTTP failures now make the script fail instead of being treated as success. |
| Metrics or security-event push reports a missing `.payload` file | Two agent/manual push attempts overlapped while sharing the same queue. | Update to the queue-locking agent script; concurrent attempts now leave the active sender in control. |
| DNS publishing fails | PowerDNS URL/key/server ID wrong or DNSGeo is unhealthy. | Run `docker compose ps`, inspect `pdns-auth`, and run `cdn:settings:test-powerdns`. |
| SSL issuance stuck at `Queued` / `5%` | `ssl-scheduler` is not running or cannot reach Core/PostgreSQL/ACME, so queued automatic or manual jobs are not being claimed. | Start `ssl-scheduler`, check its logs, and keep `CDNLITE_SSL_SCHEDULER_INTERVAL_SECONDS` low enough for interactive requests. |
| Wildcard certificate issued but subdomains still show the default edge cert | Edge config was not refreshed or the edge agent has not pulled the latest `ssl_certificates` payload. | Rebuild the config snapshot, run the edge config pull, and confirm `*.domain.com` is present in the active edge config. |
| SSL issuance stuck during validation | DNS-01 challenge not published or ACME propagation too short. | Check ACME settings, DNS records, and increase `CDNLITE_ACME_DNS_PROPAGATION_SECONDS`. |
| Cache assertions are flaky in tests | Default TTL too long for e2e timing. | Use `CDNLITE_CACHE_DEFAULT_TTL=1s` in e2e. |
| Usage analytics are empty | Edge metrics queue not pushed or domain filter mismatch. | Check `METRIC_PATH`, agent logs, collector endpoint, and domain names. |
| Config snapshot rollback appears ignored | Edge has not pulled the active version yet. | Run the edge agent config pull or wait for the polling loop, then inspect `edge-sync-status.json`. |
| ACME renewal fails after restart | `CDNLITE_SSL_SECRET_KEY` changed or DNS settings changed. | Restore the original secret if possible, test PowerDNS, and retry staging issuance first. |
| API clients get 404 after docs change | Client generated from an old spec or wrong base URL. | Regenerate from `docs/public/api/openapi.yaml` and confirm server URL. |
| PowerDNS works locally but not in CI | DNSGeo health dependency or canonical env differs. | Use `docker compose config --quiet` and verify `CDNLITE_POWERDNS_API_BASE`. |

## Log Locations

| Logs | Location |
| --- | --- |
| Core API | `docker compose logs core` |
| Dashboard Nginx | `docker compose logs dashboard` |
| Edge OpenResty | `docker compose logs edge` and `edge/logs/` |
| Edge agent | `docker compose logs edge-agent` |
| PostgreSQL | `docker compose logs postgres` |
| CI reports | `ci/reports/` |

## Config Files To Inspect

```bash
ls -la edge/config
cat edge/config/edge-sync-status.json
docker compose exec edge sh -c 'test -f /var/lib/cdnlite/config.json && wc -c /var/lib/cdnlite/config.json'
```

Useful config checks:

```bash
docker compose exec edge-agent sh -c 'cat "$EDGE_SYNC_STATUS_PATH"'
docker compose exec edge-agent sh -c 'test -f "$EDGE_CONFIG_PATH" && head -c 500 "$EDGE_CONFIG_PATH"'
docker compose exec core php artisan cdn:edge:list
docker compose exec core php artisan cdn:readiness:check
```

If `config.json` is missing or empty, focus on edge auth and `/api/v1/edge/config`. If config exists but traffic fails, focus on host matching, DNS, origin health, and edge logs. If the edge returns `no_healthy_origin`, check the enabled backend addresses, their host, port, host header, TLS/SNI, firewall, and health status.

## Debugging API Calls

Use the API token when configured:

```bash
curl -s http://localhost:8080/api/v1/domains \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN"
```

For validation errors, look for `error`, `field`, and `status` keys in the JSON response.

Common validation errors:

| Error | Meaning |
| --- | --- |
| `invalid_json` | Request body was not parseable JSON. |
| `invalid_json_object_expected` | JSON body was valid but not an object. |
| `domain_already_exists` | Another domain entry already uses the hostname. |
| `domain_not_found` | Domain ID is wrong or deleted. |
| `record_not_found` | DNS record ID is wrong or deleted. |
| `bucket_must_be_one_of_minute_hour_day` | Analytics bucket must be `minute`, `hour`, or `day`. |

## Debugging Edge Auth

Run the agent doctor:

```bash
docker compose exec edge-agent sh /opt/cdnlite-agent/doctor.sh
```

Check these values:

- `EDGE_ID` matches the registered edge.
- `EDGE_TOKEN` matches the token in core.
- Container clock is reasonably current.
- Request path in the HMAC canonical string excludes the query string.
- Request body hash matches the exact raw body.

Fast edge-auth triage:

```bash
docker compose exec core php artisan cdn:edge:show --edge_id=edge-local-1
docker compose exec core php artisan cdn:edge:register-token --edge_id=edge-local-1 --token=edge-dev-token
docker compose exec edge-agent sh /opt/cdnlite-agent/heartbeat.sh
docker compose logs --tail=80 edge-agent
```

Replay errors are usually caused by scripts reusing the same nonce while retrying. Generate the nonce inside the retry loop.

## Debugging DNS And PowerDNS

```bash
docker compose ps pdns-auth pdns-recursor pdns-postgres
curl -fsS http://localhost:8089/health
docker compose exec core php artisan cdn:settings:test-powerdns
docker compose logs --tail=120 powerdns
```

If DNS publishing fails, separate three questions:

- Does core have the right settings?
- Is the PowerDNS API reachable from core?
- Is the public or mock DNS state what the domain workflow expects?

## Debugging Cache

```bash
curl -i http://localhost:8081/path \
  -H 'Host: example.test'

curl -i http://localhost:8081/path \
  -H 'Host: example.test' \
  -H 'Cache-Control: no-cache'
```

Cache bypass is expected for non-`GET`/`HEAD` requests, `Authorization` headers, and explicit request-side no-cache/no-store headers. With domain cache enabled and no cache rules, ordinary `GET`/`HEAD` responses use the default edge TTL. Once cache rules exist for a host, matching paths use their rule TTL and non-matching paths bypass cache. Origin `X-Accel-Expires: 0` is ignored by the edge cache.

## Debugging Analytics

```bash
docker compose exec edge-agent sh -c 'test -f "$METRIC_PATH" && wc -l "$METRIC_PATH" || true'
docker compose logs --tail=120 edge-agent
curl -s http://localhost:8080/api/v1/usage/summary \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN"
```

If raw metrics exist but analytics are empty, check collector auth and recalculate aggregates:

```bash
curl -s -X POST http://localhost:8080/api/v1/usage/recalculate \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"bucket":"hour"}'
```

## Resetting Local Development

This deletes the local PostgreSQL volume:

```bash
docker compose down -v
docker compose up -d --build
```

Do not run this in production.
