# Admin Guide

This page covers administrative workflows, roles, permissions, and operational ownership.

## Roles And Permissions

CDNLite currently has a simple admin model, not enterprise RBAC.

| Actor | Access |
| --- | --- |
| Dashboard admin session | Can use dashboard-backed admin endpoints after `/api/v1/admin/login`. |
| API bearer token | Can call protected control-plane `/api/v1/*` endpoints when `CDNLITE_API_TOKEN` is configured. |
| Edge node | Can call edge registration, heartbeat, config, usage ingest, and security-event ingest after edge token registration and HMAC signing. |
| Anonymous local caller | Can call protected API routes only when `CDNLITE_API_TOKEN` is empty. This is local development behavior. |

Production guidance: put the dashboard and API behind external authentication, set `CDNLITE_API_TOKEN`, and disable bootstrap credentials.

## Create Admins

```bash
docker compose exec core php artisan cdn:admin:create \
  --username=operator \
  --password='replace-with-a-long-password' \
  --display_name='Operations User'
```

Turn off bootstrap after creating durable users:

```text
CDNLITE_BOOTSTRAP_ADMIN_USER=0
```

## Register Edge Tokens

```bash
docker compose exec core php artisan cdn:edge:register-token \
  --edge_id=edge-prod-1 \
  --token='replace-with-random-edge-secret'
```

Rotate a token:

```bash
docker compose exec core php artisan cdn:edge:rotate-token \
  --edge_id=edge-prod-1 \
  --token='new-random-edge-secret'
```

Then update the edge agent environment and restart that edge.

## Configure Platform Settings

Use the dashboard `Settings` page or CLI:

```bash
docker compose exec core php artisan cdn:settings:get
docker compose exec core php artisan cdn:settings:set --group=powerdns --key=api_url --value=http://pdns-auth:8081
docker compose exec core php artisan cdn:settings:test-powerdns
```

Settings changes should be followed by readiness checks and, where relevant, config snapshot rebuilds.

## Operate DNS

Open `DNS Operations` to inspect the PowerDNS API connection, API-key presence,
CDN zone, shared proxy hostname, mandatory apex `ALIAS` mode, and bundled
DNSGeo capabilities. The page lists convergence and errors per zone and shows
the complete desired RRset preview. Static proxy anycast IPv4/IPv6 values are
managed from `Settings` under Edge DNS / Anycast; when set, the shared proxy
host publishes plain A/AAAA records for those families instead of DNSGeo Lua.

Use `Dry run` to calculate desired records without writing PowerDNS. Use
`Force sync now` to run the advisory-locked reconciler immediately. Poweradmin
opens from the configured `CDNLITE_POWERADMIN_URL`.

Raw config snapshots are no longer exposed in dashboard navigation. They remain
an internal edge runtime mechanism. The readiness timestamp advances after a
successful edge config pull or manual rebuild. A stale warning links to `Edge
Network`, where operators can inspect heartbeat and identity state, then run
`docker compose exec core php artisan cdn:edge:sync-config` when a manual
rebuild is needed.
2. Review current and historical versions.
3. Diff two versions before rollback.
4. Roll back only when the older version is known good.
5. Rebuild after database-backed settings change if edge config needs immediate refresh.

CLI:

```bash
docker compose exec core php artisan cdn:edge:sync-config
```

## Monitor Operations

| View | What to watch |
| --- | --- |
| Overview | Readiness, stale snapshots, edge heartbeat warnings, origin failures. |
| Edge Network | Edge identity, region, public IP, health, DNS data. |
| Security Events | Blocks, challenges, rate-limit hits, WAF matches. |
| Audit Log | Settings, domain, rule, and config changes. |
| Event Viewer | Audit, security, DNS sync, and job lifecycle event details. |
| Job Queue | Central SSL/system job status across every domain. |

## Backup And Recovery

- Back up PostgreSQL volumes or logical dumps regularly.
- Keep `.env` secrets in a secure secret store.
- Preserve edge tokens, API tokens, and SSL secret keys across redeployments.
- Test `docker compose down -v` only in local/dev because it destroys the database volume.
- Use config snapshot rollback for bad edge config, not database rollback.

## Change Management

1. Make one operational change at a time.
2. Capture the before state from config snapshots or audit log.
3. Apply the change.
4. Run readiness checks.
5. Confirm edge pulls the new config.
6. Watch analytics and security events for unexpected traffic changes.

## Admin Diagrams

```text
Admin login
Browser -> Dashboard -> POST /api/v1/admin/login -> Admin session token
Browser -> Dashboard -> Protected API calls -> Core -> PostgreSQL
```

```text
Edge onboarding
Operator -> cdn:edge:register-token -> PostgreSQL
Edge agent -> signed /api/v1/edge/register -> Core
Edge agent -> signed /api/v1/edge/heartbeat -> Core
Edge agent -> signed /api/v1/edge/config -> config.json
```
# Domain Activity

Every domain detail page includes an **Activity** tab. It provides two
independently paginated streams:

- Security events: WAF, rate-limit, and Geo decisions.
- Change log: administrative and automated audit entries.

Both streams are scoped by domain ID and support free-text detail search, date
range filtering, page-size selection, and raw JSON inspection. Use this view
instead of manually correlating the global Security Events and Audit Log pages.

Global collection pages use consistent pagination controls with selectable
10/25/50/100-row page sizes. The Event Viewer supports search, severity, type,
domain, and date filters without issuing one request per customer domain.
