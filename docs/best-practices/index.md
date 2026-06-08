# Best Practices

## Configuration

- Keep one source of truth in `.env` or a secret manager.
- Use browser-reachable URLs for dashboard `VITE_CDNLITE_CORE_URL` and `VITE_CDNLITE_EDGE_URL`.
- Keep CI on the root `docker-compose.yml`.
- Use the `powerdns` profile for DNS tests instead of live DNS mutation.
- Keep `CDNLITE_CACHE_DEFAULT_TTL=1s` for stable cache e2e assertions.

## Security

- Disable admin and edge bootstrap outside local development.
- Register unique edge tokens per node.
- Rotate tokens on a schedule.
- Avoid `VITE_CDNLITE_API_TOKEN` in public dashboards.
- Restrict PostgreSQL and PowerDNS ports.
- Review audit and security events after every rule change.

## Domains And DNS

- Keep mail, verification, and non-HTTP records DNS-only.
- Proxy only HTTP/HTTPS records intended for the CDN.
- Verify nameserver delegation before activation.
- Use low TTLs during migration and higher TTLs after stability.
- Test Geo DNS and anycast routing with previews before traffic cutover.

## Origins

- Configure primary and backup origins when availability matters.
- Run manual health checks after adding origins.
- Keep origin shield headers secret and rotate if exposed.
- Prefer HTTPS origins when possible.

## Cache

- Start with conservative TTLs.
- Purge by URL or prefix before using `everything`.
- Review cache analytics after rule changes.
- Keep query-string behavior explicit for apps with signed URLs or tracking parameters.

## Rules

- Use log-only WAF rules before blocking broad patterns.
- Keep rate limits scoped to high-risk paths first.
- Use IP block rules for clear abuse; avoid overbroad CIDRs.
- Add security headers through presets, then tighten CSP after testing.

## Operations

- Make one change at a time.
- Capture config snapshot versions before risky edits.
- Run readiness after settings, DNS, SSL, or edge changes.
- Use smoke/e2e scripts before releases.
- Store incident notes and dashboard exports alongside release notes.

## Configuration Review Checklist

| Area | Question |
| --- | --- |
| API auth | Is `CDNLITE_API_TOKEN` set outside local development? |
| Dashboard | Are `VITE_*` URLs reachable from the browser? |
| CORS | Does `CDNLITE_CORS_ALLOWED_ORIGINS` include only expected dashboard origins? |
| SSL | Is `CDNLITE_SSL_SECRET_KEY` long, private, and stable? |
| Edge | Does every edge have a unique ID and token? |
| DNS | Are PowerDNS settings tested after every change? |

## Token Handling

- Generate edge tokens with high entropy.
- Store raw tokens only in secret managers or edge environment files.
- Store token rotation steps in the operations runbook.
- Rotate one edge at a time and confirm heartbeat before rotating the next.
- Never paste tokens into dashboard screenshots or public issue comments.

## DNS Migration Pattern

1. Inventory existing DNS records.
2. Classify records as proxied or DNS-only.
3. Lower TTL at the old provider.
4. Create CDNLite records.
5. Validate records through the API and, when enabled, PowerDNS.
6. Delegate nameservers.
7. Activate the domain.
8. Watch traffic, errors, and cache analytics.
9. Raise TTL only after stability.

## Origin Design

- Use a health-check path that exercises the application, not just the load balancer.
- Keep backup origins warm enough to accept sudden traffic.
- Use clear names for origins so incident notes can say exactly which origin served traffic.
- Document whether the origin expects the original host header or an origin-specific host.

## Cache Safety Table

| Situation | Safer setting |
| --- | --- |
| Login/session pages | Bypass or very short TTL. |
| Static versioned assets | Long TTL plus prefix purge. |
| Search pages | Respect query strings. |
| Marketing pages | Moderate TTL and URL purge. |
| API responses | Cache only after explicit review. |

## Rule Review Questions

- What exact traffic should match?
- What legitimate traffic could also match?
- Is there a log-only or disabled warmup phase?
- How will rollback be performed?
- Which dashboard view proves the rule is working?
- Which metric proves it is not causing harm?

## Daily Checks

- Core `/ready` is healthy.
- Edge nodes have recent heartbeat times.
- Config snapshot age is acceptable.
- Origin health is current.
- Security events are reviewed for new patterns.
- SSL certificates are not close to expiry.
- Cache analytics are not showing unexpected bypass spikes.

## Release Checks

- `docker compose config --quiet`
- PHP lint for changed PHP files.
- Shell syntax checks for changed shell scripts.
- `pytest -q core/tests` for core behavior.
- Dashboard typecheck, unit tests, and build for frontend behavior.
- VitePress docs build for docs changes.
- Smoke/e2e when runtime behavior changes.

## Developer Experience

- Keep OpenAPI examples in sync with route changes.
- Prefer stable error codes over prose-only errors.
- Add request examples for every new workflow.
- Document operational side effects, not just request fields.
- When adding a new dashboard view, document the API route family it depends on.
