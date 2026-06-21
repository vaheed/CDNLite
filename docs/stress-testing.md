# DNS Stress Testing

`ci/stress-dns.sh` proves that edge changes update shared CDN records without
rewriting every customer zone. It uses the normal root `docker-compose.yml`
topology and real local PowerDNS/DNSGeo services.

## Destructive Warning

The runner deletes all Core and PowerDNS data. Run it only on a disposable
fresh installation. Never point it at shared, staging, or production databases.

The script temporarily stops Core writer services, resets the Core schema,
clears PowerDNS zones, seeds the test model, runs the qualification, and restores
the services before exiting.

## Prerequisites

Install Docker with Compose, `curl`, `jq`, and standard GNU shell utilities.
Start from the repository root:

```bash
docker compose up -d --build --wait --wait-timeout 300
docker compose ps
curl -fsS http://127.0.0.1:8080/health
curl -fsS -H "X-API-Key: ${PDNS_API_KEY:-test-key}" \
  http://127.0.0.1:8089/api/v1/servers/localhost
```

Both health requests must succeed before testing.

## Reduced Validation

First run a small dataset to validate the host and test mechanics:

```bash
STRESS_DOMAINS=10 \
STRESS_RECORDS_PER_DOMAIN=20 \
STRESS_EDGE_NODES=6 \
STRESS_FLAP_ITERATIONS=2 \
./ci/stress-dns.sh
```

This reduced run does not qualify the full production load model. It is intended to catch configuration,
resource, and connectivity failures quickly.

## Full Qualification

Run the default full model without overrides:

```bash
./ci/stress-dns.sh
```

The default model contains:

- 10,000 customer domains.
- 1,000 DNS records per domain.
- 10,000,000 logical DNS records.
- 10 edge nodes across at least three regions.
- Proxied apex LUA and subdomain CNAME records.
- Ten edge-health transitions with concurrent customer DNS changes.

Plan substantial time, memory, PostgreSQL storage, and PowerDNS storage. The
GitHub Actions workflow also exposes this run through the manual
`run_dns_stress` input; it is intentionally excluded from every-push CI.

## Configuration

| Variable | Default | Purpose |
| --- | ---: | --- |
| `STRESS_DOMAINS` | `10000` | Number of customer domains. |
| `STRESS_RECORDS_PER_DOMAIN` | `1000` | DNS records generated per domain. |
| `STRESS_EDGE_NODES` | `10` | Healthy multi-region edges. |
| `STRESS_FLAP_ITERATIONS` | `10` | Health transitions during concurrent writes. |
| `STRESS_SMALL_SYNC_LIMIT_SECONDS` | `10` | Maximum accepted edge-only sync time. |
| `CORE_URL` | `http://127.0.0.1:8080` | Host-reachable Core URL. |
| `POWERDNS_PUBLIC_API_URL` | `http://127.0.0.1:8089` | Host-reachable PowerDNS API. |
| `PDNS_API_KEY` | `test-key` | PowerDNS API key used by the local stack. |
| `STRESS_REPORT_JSON` | `ci/reports/dns-stress-report.json` | JSON report path. |
| `STRESS_REPORT_MD` | `ci/reports/dns-stress-report.md` | Markdown report path. |

## Assertions

The qualification fails unless:

- Dataset counts and required PostgreSQL indexes are correct.
- Full reconciliation succeeds against real PowerDNS.
- An edge IP change modifies shared CDN RRsets but zero customer zones.
- The edge-only sync stays below the configured limit.
- Health flapping and concurrent customer writes complete without deadlocks.
- No duplicate or stale desired RRsets remain.
- No failed or stuck DNS sync state remains.
- `/cdn-health` and the PowerDNS API remain responsive.

## Reports

Inspect the generated reports after a successful run:

```bash
cat ci/reports/dns-stress-report.json
cat ci/reports/dns-stress-report.md
```

The JSON report includes dataset size, full-sync duration, edge-only sync
duration, changed RRset count, changed customer-zone count, health-flap count,
Core health latency, and PowerDNS health.

## Troubleshooting

Check service state and logs:

```bash
docker compose ps -a
docker compose logs --no-color --tail=300 \
  core dns-reconciler postgres pdns-auth pdns-postgres pdns-recursor
```

If PowerDNS exits after an MMDB refresh, recreate it and confirm that the
`restart: unless-stopped` policy is present:

```bash
docker compose up -d --force-recreate pdns-auth
docker compose ps pdns-auth
```

If a run is interrupted, start the Core writer services again:

```bash
docker compose up -d core dns-reconciler ssl-scheduler origin-health-scheduler
```

Because the dataset is disposable, the clean recovery path is:

```bash
docker compose down -v
docker compose up -d --build --wait --wait-timeout 300
```
