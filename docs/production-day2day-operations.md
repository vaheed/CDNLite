# Production Day-2-Day Operations

[Back to docs index](index.md)

This runbook is for routine production operations of CDNLite after initial deployment. It focuses on safe daily workflows, verification steps, and incident triage.

## Scope And Assumptions

- Control plane: `core/`
- Data plane: `edge/openresty/`
- Agent: `edge/agent/`
- Runtime: Docker Compose service names `core`, `edge`, `edge-agent`, `postgres`
- Commands here assume execution from repo root.

## Daily Health Checks

Run these checks at least once per shift:

```bash
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
docker compose exec postgres pg_isready -h 127.0.0.1 -p 5432 -U cdnlite -d cdnlite
docker compose ps
```

Healthy expectation:

- Core health returns HTTP 200 with `{"ok":true}`
- Edge health returns HTTP 200 with `{"ok":true}`
- Postgres is `accepting connections`
- Containers are `Up` (not restarting)

## Daily Log Review

```bash
docker compose logs --since=1h core
docker compose logs --since=1h edge
docker compose logs --since=1h edge-agent
```

Look for:

- `uncaught_exception` in core logs
- Repeated `http_request_failed` on edge-auth endpoints
- Agent pull/push failures repeated every loop
- Repeated edge 502/504 bursts

## Edge Registration And Heartbeat Validation

```bash
docker compose exec core php artisan cdn:edge:list
```

Check for each expected edge:

- `status` is `online`
- `last_heartbeat` is recent
- `public_ip` is populated and expected

If missing/stale:

```bash
docker compose exec edge-agent sh -lc '/agent/register.sh'
docker compose exec edge-agent sh -lc '/agent/heartbeat.sh'
docker compose exec core php artisan cdn:edge:list
```

## Config Snapshot And Propagation Checks

Core snapshot:

```bash
docker compose exec core php artisan cdn:edge:sync-config
```

Force edge refresh:

```bash
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
docker compose exec edge-agent sh -lc 'cat "$EDGE_CONFIG_PATH" | head -c 500'
```

Routing sanity:

```bash
curl -i -H 'Host: example.com' http://localhost:8081/health
curl -i -H 'Host: unknown.example' http://localhost:8081/api/v1/sites
```

Expected:

- Known host routes upstream
- Unknown host returns `502`

## Edge Offline Mode

Edge nodes continue serving with the last-known-good config when core is temporarily unreachable.

- Startup: the agent first pulls from core; if that fails, it restores from `EDGE_CONFIG_CACHE_PATH` (default: same as `EDGE_CONFIG_PATH`).
- Runtime: config polling continues; temporary pull failures do not clear active config.
- Recovery: when core is reachable again and a newer snapshot exists, the edge applies it automatically and refreshes cache.
- Readiness: `/ready` depends on a valid active config, not current core availability. It returns `200` when a valid config is loaded and `503` only when no valid config is available.
- Stale safety: set `EDGE_CONFIG_MAX_STALE_SECONDS` to emit readiness warning (`status.warning=config_stale`) if sync age exceeds policy while still serving traffic.
- Sync status: `/ready` includes current version, last successful sync time, source (`remote` or `cache`), and core reachability signal.

## Safe Change Procedure: Site Config

When changing origin host/port, geo upstreams, proxy enablement, DNS, or cache rules:

1. Apply change in core (API or CLI).
2. Verify core response is successful.
3. Pull config on edge-agent.
4. Verify edge behavior with at least one canary request.
5. Monitor logs for 5-15 minutes.

Example:

```bash
# 1) apply
curl -sS -X PATCH "http://localhost:8080/api/v1/sites/<site_id>" \
  -H 'Content-Type: application/json' \
  -d '{"origin_host":"core","origin_port":8080}'

# 3) pull config
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'

# 4) verify
curl -i -H "Host: <site-domain>" "http://localhost:8081/health"
```

## Safe Change Procedure: Cache Rules

For cache-rule changes, always verify `X-CDNLITE-Cache` behavior after config pull.

Checks:

1. First GET on matching path should be `MISS`.
2. Second GET should be `HIT`.
3. Request with `Authorization` should be `BYPASS`.
4. Request with `Cache-Control: no-cache` or `no-store` should be `BYPASS`.
5. Non-matching path should be `BYPASS`.

Example:

```bash
curl -i -H "Host: <site-domain>" "http://localhost:8081/api/v1/sites?probe=1" | grep -i X-CDNLITE-Cache
curl -i -H "Host: <site-domain>" "http://localhost:8081/api/v1/sites?probe=1" | grep -i X-CDNLITE-Cache
curl -i -H "Host: <site-domain>" -H "Authorization: Bearer token" "http://localhost:8081/api/v1/sites?probe=1" | grep -i X-CDNLITE-Cache
curl -i -H "Host: <site-domain>" -H "Cache-Control: no-cache" "http://localhost:8081/api/v1/sites?probe=1" | grep -i X-CDNLITE-Cache
```

## Deployment Checklist

Before deploy:

- `find core -name '*.php' -print0 | xargs -0 -n1 php -l`
- `sh -n edge/agent/*.sh`
- `bash -n ci/*.sh`
- `pytest -q core/tests`
- `./ci/smoke.sh`
- `./ci/e2e.sh`

During deploy:

1. Deploy core and edge images.
2. Verify container health.
3. Ensure edge token exists for each edge.
4. Pull config explicitly once after rollout.
5. Run canary traffic checks (`Host` routing + cache header checks).

After deploy:

- Watch core/edge/agent logs for 15-30 minutes.
- Confirm no spike in 5xx responses.

## Rollback Procedure

Use rollback when any of these occur:

- Sustained 5xx increase after deploy
- Edge auth failures across edges
- Routing regressions for known hosts
- Cache behavior regression

Rollback steps:

1. Revert to previous container images.
2. Restart affected services.
3. Pull config from edge-agent.
4. Re-run health and canary checks.

```bash
docker compose up -d --build core edge edge-agent
docker compose exec edge-agent sh -lc '/agent/pull_config.sh'
```

If issue is site-config specific instead of image-specific, revert the site change and pull config again.

## Incident Triage Quick Map

| Symptom | First checks | Likely cause | First action |
|---|---|---|---|
| Edge returns 502 for known host | `edge-agent` pull success, snapshot host exists | stale/missing config or bad origin | pull config, validate origin host/port |
| Edge auth 401 on agent calls | token registration, timestamp skew, signature path | token mismatch or signing issue | re-register token, validate env vars |
| Replay 409 | recent nonce reuse | duplicate nonce | ensure unique nonce generation |
| Cache always BYPASS | snapshot cache rule exists, path match, request headers | no matching rule or bypass headers | verify rules, pull config, retest |
| No usage in summaries | metrics file, ingest logs, recalculate | push/ingest/rebuild gap | run `/agent/push_metrics.sh`, run recalculate |
| DNS sync errors | core logs + PowerDNS connectivity/key | PowerDNS unavailable or auth error | fix URL/key/strict mode settings |

## Backup And Restore Basics

Minimum daily backup:

- PostgreSQL logical dump
- Runtime `.env` and secret inventory

Example:

```bash
docker compose exec postgres pg_dump -U cdnlite -d cdnlite > backup-cdnlite.sql
```

Restore drill (non-production environment):

```bash
cat backup-cdnlite.sql | docker compose exec -T postgres psql -U cdnlite -d cdnlite
```

Run at least monthly restore verification.

## Weekly Maintenance

- Rotate edge tokens for at least one edge and validate register/heartbeat.
- Review top warnings/errors in logs.
- Run full `ci/smoke.sh` and `ci/e2e.sh` against current production-like config.
- Confirm stale cache behavior still works for upstream failure scenarios.

## Escalation Guidelines

Escalate immediately when:

- Core is down more than 5 minutes.
- More than 20% of known hosts return 5xx for more than 5 minutes.
- All edge heartbeats stop updating.
- Data integrity issue is suspected (missing/duplicated site or DNS records).

When escalating, include:

- Exact UTC timestamp window
- Failing endpoint(s) and host header used
- Last successful and first failing deployment/change reference
- Relevant logs from core, edge, and edge-agent
