# DNSGeo and PowerDNS

## Desired-State Reconciliation

Core generates all durable customer and edge rrsets into `desired_dns_rrsets`. Event-driven
changes and the `dns-reconciler` scheduler both run `php artisan cdn:dns:reconcile`.
A PostgreSQL advisory lock prevents overlapping runs. Each run compares desired state with
the live PowerDNS zone, sends one batch PATCH per changed zone, verifies the write, and
deletes rrsets that were previously owned by CDNLite but are no longer desired.

Use `CDNLITE_SYNC_INTERVAL_SECONDS` to set the scheduled convergence interval (default:
`30`). `php artisan cdn:powerdns:dry-run` previews desired rrsets, while
`php artisan cdn:powerdns:force-sync` forces a full replacement pass.

The root `docker-compose.yml` starts the project DNS stack by default:

- `pdns-postgres`: PostgreSQL backend dedicated to DNS data.
- `pdns-db-init`: idempotent PowerDNS and Poweradmin schema initialization.
- `pdns-mmdb-updater`: GeoIP MMDB downloader and freshness loop.
- `pdns-recursor`: recursive resolver used only for authoritative ALIAS expansion.
- `pdns-auth`: PowerDNS Authoritative 5.x with PostgreSQL, Lua records, EDNS Client Subnet, and ALIAS expansion.
- `poweradmin`: operator UI backed by the same DNS database.

Start the product with:

```bash
docker compose up -d --build
```

The API and Poweradmin ports bind to `127.0.0.1` by default. Do not publish the PowerDNS API directly to the internet. Put Poweradmin behind authenticated HTTPS or a VPN before remote use.

The authoritative service uses `restart: unless-stopped`. The MMDB watcher
intentionally terminates PowerDNS after an atomic database replacement so
Docker restarts it and remaps the new file.

## ALIAS Resolution

PowerDNS Authoritative is rendered with:

```ini
expand-alias=yes
resolver=pdns-recursor:5300
```

The resolver is a separate process. It forwards the configured `CDNLITE_DNS_BASE_DOMAIN` and `CDNLITE_CDN_ZONE` to `pdns-auth`; it never points recursive traffic back to the authoritative server as a general resolver.

DNSSEC signing is not enabled by the bundled fresh-install defaults. Operators enabling DNSSEC must validate ALIAS synthesis and parent DS publication before production rollout.

## Configuration

The bundled service is provisioned with:

```env
PDNS_API_KEY=change-me
CDNLITE_DNS_BASE_DOMAIN=example.net
CDNLITE_CDN_ZONE=cdn.example.net
CDNLITE_CDN_PROXY_HOST=proxy.cdn.example.net
```

## Shared Edge Pool

Core reads the fresh-install `edge_state` view as the single DNS routing source.
An address is eligible only when its edge is enabled for DNS, online, healthy,
and has a heartbeat newer than 90 seconds. Anycast addresses are ordered before
regional unicast addresses. Core keeps edge ordering from `edge_state`, writes
each target IP with the edge country and continent into the PowerDNS Lua record,
and uses the first eligible edge IP as the final fallback answer.

The CDN zone contains one shared pair of Lua rrsets:

```text
proxy.cdn.example.net. LUA A    <healthy IPv4 pool>
proxy.cdn.example.net. LUA AAAA <healthy IPv6 pool>
```

Stable `site-<domain-id>.cdn.example.net` CNAMEs point to that shared host.
Proxied customer apex records are always published as `ALIAS` to that stable
site target. Proxied subdomains are always published as `CNAME`. CDNLite has no
apex address-flattening mode and never copies edge IPs into customer zones.
Changing an edge IP or health state therefore changes only the shared proxy
rrsets; customer zones and site CNAMEs are not rewritten. Core records distinct
edge-state hashes in `edge_state_generations` for inspection and test assertions.

For DNS-only `A` and `AAAA` records, content must remain an IPv4 or IPv6
address respectively. For proxied `A` and `AAAA` records, the dashboard content
field is the private default origin and accepts either an IP address or a
hostname. Country origin overrides use the same IP-or-hostname rule and do not
change the public ALIAS/CNAME records.

Core operational credentials remain database-backed platform settings. Configure its API URL as `http://pdns-auth:8081`, server ID as `localhost`, and use the same API key through the admin settings API or UI.

The local defaults publish authoritative DNS on port `5353`, the API on loopback port `8089`, and Poweradmin on loopback port `8084`. Production deployments should normally publish authoritative DNS on TCP/UDP 53.

Lua records and `edns-subnet-processing=yes` are enabled. The MMDB updater uses DB-IP City Lite through jsDelivr by default; set `CDNLITE_MMDB_DOWNLOAD_URL` and an expected SHA-256 when pinning an internal artifact.

`infra/dnsgeo/geo/lua-bootstrap.yml` contains only
`geoip-bootstrap.invalid`, a reserved non-routable zone required to initialize
the GeoIP backend. CDNLite does not seed customer/example zones or Lua records.
Core owns every real zone and record through the PowerDNS API.

## DNS Acceptance Tests

`ci/dns_e2e.sh` uses the normal root Compose topology and fails unless Core
writes the expected raw ALIAS, CNAME, DNS-only, site-target, and shared Lua
records. It also compares `dig` answer sets for the apex, site target, and
proxy host; verifies edge health changes only update the shared CDN record;
checks stale deletion; and proves failed writes are visible and recoverable.

The script inspects the running authoritative configuration for
`expand-alias=yes` and a separate resolver. Disabling either requirement fails
CI before the DNS assertions run.

Core records each write attempt and verified outcome in `dns_sync_events`, and
stores the current per-zone result in `dns_sync_state`. A failed PATCH or
read-back verification leaves the zone in `failed` state with its status code
and error. Inspect or operate the integration with:

```bash
docker compose exec core php artisan cdn:powerdns:doctor
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
curl -fsS http://localhost:8080/cdn-health
```

## PostgreSQL Replication

The primary image configures WAL streaming, TLS, SCRAM authentication, a
dedicated `replicator` role, and client certificates. The optional
`infra/dnsgeo/docker/postgres-replica` image bootstraps a read-only standby
with `pg_basebackup`. It is not a default root Compose service; production
operators can place replicas according to their topology while local and CI
remain deterministic.

Set `PDNS_REPLICATION_PASSWORD` for fresh initialization even when no replica
is started. SQL initialization creates schemas and service roles only; it
creates no customer, base, sample, or seed zones.

## Validation

```bash
docker compose ps
curl -fsS -H "X-API-Key: $PDNS_API_KEY" \
  http://127.0.0.1:8089/api/v1/servers/localhost
dig @127.0.0.1 -p 5353 example.net SOA
./ci/powerdns_dns_checks.sh
```

The CI check creates an isolated zone through the real API, writes an rrset,
resolves it through the authoritative listener with `dig`, verifies bad-key
rejection, and removes the zone.

The reconciler writes each changed RRset independently. A rejected RRset is
reported in DNS sync health and events, while unrelated RRsets and zones
continue reconciling. Exact duplicate customer records are rejected by the API
with `dns_record_duplicate`; different values for a shared name and type are
combined into a multi-value RRset.

Customer record types are limited to `A`, `AAAA`, `CNAME`, `TXT`, `MX`, `CAA`,
`NS`, and `SRV`. Names may be entered as `@`, relative labels, or an FQDN
inside the customer zone; stored names are canonicalized to `@` or a relative
name. TTLs must be 60-86400 seconds and MX priorities must be 0-65535.

## Production Stress Qualification

`ci/stress-dns.sh` is the destructive production-scale proof. It uses the root
Compose topology and defaults to 10,000 customer zones with 1,000 records each.
The runner verifies dataset counts and query indexes, performs a full sync,
changes one edge IP, and proves no customer zone serial changed from that edge
event. It also exercises repeated health transitions, concurrent customer DNS
updates, advisory-lock behavior, stale/duplicate desired-state checks,
`/cdn-health` responsiveness, and final PowerDNS health.

Results are written to `ci/reports/dns-stress-report.json` and
`ci/reports/dns-stress-report.md`. The run destroys Core and PowerDNS data and
must never target a shared or production database.

Follow [DNS Stress Testing](stress-testing.md) for prerequisites, reduced and
full qualification commands, configuration, pass criteria, and troubleshooting.
Operators can create and edit records before registrar delegation is complete.
Those records remain stored but are not published until the domain's expected
nameservers are verified. The `nameserver-scheduler` rechecks every domain daily
by default and automatically withdraws records and edge configuration when
delegation no longer matches. Set
`CDNLITE_NAMESERVER_CHECK_INTERVAL_SECONDS` to change that interval.

Adding another proxied A/AAAA target at a hostname that already has a proxied
record does not create a second public CNAME or ALIAS. CDNLite keeps the existing
record and adds the new target as an enabled backup origin.

At the zone apex, the PowerDNS `ALIAS` used for proxying may coexist with normal
apex records such as `MX`, `TXT`, and `CAA`. A real `CNAME` remains exclusive.
