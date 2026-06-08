# Operations Runbooks

These runbooks are practical procedures for common operator tasks. They assume the root Docker Compose stack and local defaults unless noted otherwise.

## Before You Touch Production

Record the current state:

```bash
docker compose ps
curl -fsS http://localhost:8080/ready
curl -fsS http://localhost:8081/health
docker compose exec core php artisan cdn:edge:list
docker compose exec core php artisan cdn:readiness:check
```

Capture the active config snapshot:

```bash
curl -s http://localhost:8080/api/v1/config/snapshots \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN"
```

Write down:

- Active snapshot version.
- Edge nodes with recent heartbeat.
- Domains being changed.
- Expected rollback action.
- Who approved the change.

## Add A Domain

1. Create the domain.
2. Add at least one primary origin.
3. Add DNS records.
4. Verify nameserver delegation.
5. Activate the domain.
6. Pull edge config.
7. Send test traffic with a `Host` header.
8. Watch analytics and security events.

Commands:

```bash
docker compose exec core php artisan cdn:domain:create \
  --domain=example.com \
  --name=Example

docker compose exec core php artisan cdn:domain:list
docker compose exec core php artisan cdn:domain:verify-ns --domain_id="$DOMAIN_ID"
docker compose exec core php artisan cdn:domain:activate --domain_id="$DOMAIN_ID"
docker compose exec core php artisan cdn:edge:sync-config
```

Rollback:

- Disable or delete the new domain record if traffic has not been delegated.
- Restore old DNS delegation if registrar changes already happened.
- Roll back the config snapshot if the edge pulled a bad config.

## Rotate An Edge Token

Use this when an edge secret might be exposed or during scheduled rotation.

1. Generate a new token.
2. Register the new token in core.
3. Update the edge agent secret.
4. Restart the edge agent.
5. Confirm heartbeat.
6. Confirm config pull.

```bash
NEW_TOKEN=$(openssl rand -hex 32)

docker compose exec core php artisan cdn:edge:rotate-token \
  --edge_id=edge-prod-1 \
  --token="$NEW_TOKEN"
```

After updating the edge environment:

```bash
docker compose restart edge-agent
docker compose logs --tail=120 edge-agent
docker compose exec core php artisan cdn:edge:show --edge_id=edge-prod-1
```

Rollback:

- Rotate again to a known-good token.
- Do not re-enable a token that may be exposed.

## Roll Back A Bad Config Snapshot

Symptoms:

- Edge starts serving unexpected origins.
- New rules block legitimate traffic.
- DNS/routing changes produce wrong config.
- `edge-sync-status.json` shows a newer bad version.

Procedure:

```bash
curl -s http://localhost:8080/api/v1/config/snapshots \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN"

curl -s -X POST http://localhost:8080/api/v1/config/snapshots/diff \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"from_version":12,"to_version":13}'

curl -s -X POST http://localhost:8080/api/v1/config/snapshots/12/rollback \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN"
```

Then force or wait for edge pull:

```bash
docker compose exec edge-agent sh /opt/cdnlite-agent/pull_config.sh
docker compose exec edge-agent sh -c 'cat "$EDGE_SYNC_STATUS_PATH"'
```

Post-check:

- Confirm edge serves expected origin.
- Confirm readiness warnings clear.
- Keep the bad version number in the incident notes.
- Fix the database-backed state that generated the bad snapshot.

## Investigate Edge 502

Decision tree:

1. Does `curl http://localhost:8081/health` pass?
2. Does the request include the expected `Host` header?
3. Is the host present in `config.json`?
4. Is the domain active and proxied?
5. Is the primary origin healthy?
6. Is a backup origin available?
7. Is a WAF/IP/rate-limit rule blocking the request?

Commands:

```bash
curl -i http://localhost:8081/ \
  -H 'Host: example.com'

docker compose exec edge-agent sh -c 'cat "$EDGE_CONFIG_PATH"'
docker compose logs --tail=150 edge
docker compose logs --tail=150 edge-agent
```

Fix patterns:

| Finding | Action |
| --- | --- |
| Unknown host | Activate domain and pull config. |
| Config missing | Fix edge auth, then pull config. |
| Origin unhealthy | Restore origin or promote backup. |
| Rule block | Disable or narrow the rule. |
| Cache stale | Purge URL/prefix or wait for TTL. |

## Recover Empty Analytics

1. Check edge metric queue.
2. Check edge agent logs.
3. Check collector auth.
4. Recalculate aggregates.
5. Verify dashboard filters.

```bash
docker compose exec edge-agent sh -c 'test -f "$METRIC_PATH" && wc -l "$METRIC_PATH" || true'
docker compose logs --tail=120 edge-agent

curl -s -X POST http://localhost:8080/api/v1/usage/recalculate \
  -H "Authorization: Bearer $CDNLITE_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"bucket":"hour"}'
```

If metrics are queued but not ingested, focus on edge auth and collector responses. If metrics are ingested but dashboard is empty, check domain filters and bucket selection.

## DNS Publishing Incident

Symptoms:

- DNS record API returns integration errors.
- PowerDNS test fails.
- Customer zone is missing.
- Edge DNS records do not match expected anycast settings.

Procedure:

```bash
docker compose --profile powerdns ps
curl -fsS http://localhost:8089/health
docker compose exec core php artisan cdn:settings:test-powerdns
docker compose logs --tail=120 core
docker compose logs --tail=120 powerdns
```

Questions to answer:

- Did the PowerDNS API URL change?
- Did the API key rotate?
- Is strict PowerDNS mode enabled?
- Is the customer zone supposed to be lazily created?
- Are platform edge DNS settings complete?

Rollback:

- Restore previous PowerDNS settings.
- Rebuild customer zones only after the API test passes.
- Avoid live external DNS mutation when the mock can reproduce the issue.

## SSL Issuance Failure

Symptoms:

- ACME status remains pending.
- DNS challenge record is missing.
- Certificate renewal job logs errors.
- Force HTTPS cannot be enabled.

Procedure:

```bash
docker compose logs --tail=160 ssl-scheduler
docker compose exec core php artisan cdn:ssl:list --domain_id="$DOMAIN_ID"
docker compose exec core php artisan cdn:settings:test-powerdns
```

Checklist:

- ACME directory is staging for tests.
- Contact email is set.
- DNS propagation seconds are realistic.
- `_acme-challenge` records are DNS-only.
- `CDNLITE_SSL_SECRET_KEY` did not change.
- Domain is active and proxied when required by the workflow.

Fallback:

- Import a manual certificate.
- Keep force HTTPS disabled until a valid active certificate exists.

## Release Validation

Run these before a release that changes runtime behavior:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd docs && npm ci && npm run docs:build
```

Then run stack tests:

```bash
docker compose up -d --build --wait
./ci/smoke.sh

docker compose --profile powerdns up -d --build
EDGE_AGENT_IDLE=1 CDNLITE_CACHE_DEFAULT_TTL=1s ./ci/e2e.sh
./ci/powerdns_dns_checks.sh
```

Release notes should include:

- Changed behavior.
- API or CLI changes.
- Required environment changes.
- Migration or rollback steps.
- Test commands that passed.
