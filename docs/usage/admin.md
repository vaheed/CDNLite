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
docker compose exec core php artisan cdn:settings:set --group=powerdns --key=api_url --value=http://powerdns:8081
docker compose exec core php artisan cdn:settings:test-powerdns
```

Settings changes should be followed by readiness checks and, where relevant, config snapshot rebuilds.

## Operate Config Snapshots

1. Open `Config Snapshots`.
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
| Event Viewer | Diagnostic reports and runtime event details. |

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
