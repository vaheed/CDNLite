# Use Cases

## Learn CDN Internals

Run the local Compose stack, add a domain, point a test Host header at the edge, and watch how config snapshots affect OpenResty behavior.

Useful views:

- `Domains`
- `Config Snapshots`
- `Usage Analytics`
- `Security Events`

## Local CDN Lab

Use `origin-http` and `origin-tls` from Compose to test routing, TLS, cache, redirects, and multiple independent origin targets without touching real infrastructure.

Validation:

```bash
./ci/smoke.sh
./ci/e2e.sh
```

## DNS Publishing Practice

Run the PowerDNS profile and validate customer zones, platform edge DNS, anycast values, and Geo DNS settings through the mock.

```bash
docker compose up -d --build
./ci/powerdns_dns_checks.sh
```

## Small Private Edge

Deploy core, dashboard, and PostgreSQL to a trusted private host, then deploy one or more edge containers with unique `EDGE_ID` and `EDGE_TOKEN` values.

Minimum production checklist:

- External TLS.
- External dashboard auth.
- Strong `CDNLITE_API_TOKEN`.
- Bootstrap disabled.
- Per-edge token rotation process.
- Database backups.

## Security Rule Testing

Create WAF, IP, rate-limit, and header rules on a test domain, then send controlled traffic to validate block/challenge/log behavior.

Watch:

- Domain security events.
- Global security summary.
- Edge logs.
- Audit log entries for rule changes.

## Certificate Workflow Testing

Use staging ACME directory values, the bundled PowerDNS stack or a controlled DNS provider, and a test domain. Validate DNS-01 challenge publishing before using production ACME.

## Dashboard QA

Use dashboard typechecking, unit tests, and a production build before manually
testing browser workflows.

```bash
cd dash
npm run typecheck
npm test
npm run build
```

## Learn CDN Internals: Exercise Plan

1. Create a fake domain such as `example.test`.
2. Add an origin pointing to the local origin mock.
3. Add a proxied DNS record.
4. Activate with override in the lab.
5. Pull edge config.
6. Send traffic with `curl -H 'Host: example.test'`.
7. Inspect `edge/config/config.json`, edge logs, config snapshots, and usage analytics.

What to observe:

- Unknown hosts fail before origin selection.
- Config snapshots change only after control-plane state changes.
- Edge metrics are queued locally before the agent pushes them.
- Cache headers explain most surprising edge responses.

## Local CDN Lab Experiments

| Experiment | What it teaches |
| --- | --- |
| Disable the origin | Difference between origin failure, stale cache, and unknown host. |
| Add a redirect | How rule evaluation changes the response before proxying. |
| Set a short cache TTL | How cache hit ratio changes during repeated requests. |
| Add a WAF log rule | How security events flow from edge to dashboard. |
| Roll back a config snapshot | How edge config is recovered without database rollback. |

## Multi-Origin Migration

Use CDNLite origins to test a new backend while the current one remains available.

1. Add the current backend as an origin.
2. Add the new backend as another origin for the same host.
3. Run health checks on both.
4. Send staging traffic with a dedicated test hostname.
5. Watch `X-CDNLITE-Origin`, origin health, error rate, and cache hit ratio.
6. Keep both origins enabled until the migration is stable.

Rollback is simple: disable the new origin and rebuild/pull config.

## Incident Response Drill

Practice this before production:

1. Break an origin health check intentionally.
2. Confirm readiness warnings appear.
3. Confirm the dashboard and API show the failed origin.
4. Restore the origin.
5. Re-run health checks.
6. Write down exact commands and response fields that were useful.

The goal is to make the first real incident boring.

## Certificate Workflow Checklist

- ACME contact email is valid.
- DNS provider settings are tested.
- `_acme-challenge` records are DNS-only.
- Propagation wait is realistic for the provider.
- Renewal scheduler is running.
- Manual certificate import is tested as a fallback.

## Dashboard QA Checklist

- Login/logout and session refresh behavior.
- Domain creation and validation errors.
- Wide tables on narrow screens.
- Empty states for every major view.
- API failure alerts and field errors.
- Theme-aware charts and readable tooltips.
