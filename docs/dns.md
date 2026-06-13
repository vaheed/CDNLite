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
regional unicast addresses, and both groups use stable IP ordering.

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

Core operational credentials remain database-backed platform settings. Configure its API URL as `http://pdns-auth:8081`, server ID as `localhost`, and use the same API key through the admin settings API or UI.

The local defaults publish authoritative DNS on port `5353`, the API on loopback port `8089`, and Poweradmin on loopback port `8084`. Production deployments should normally publish authoritative DNS on TCP/UDP 53.

Lua records and `edns-subnet-processing=yes` are enabled. The MMDB updater uses DB-IP City Lite through jsDelivr by default; set `CDNLITE_MMDB_DOWNLOAD_URL` and an expected SHA-256 when pinning an internal artifact.

`infra/dnsgeo/geo/lua-bootstrap.yml` contains only
`geoip-bootstrap.invalid`, a reserved non-routable zone required to initialize
the GeoIP backend. CDNLite does not seed customer/example zones or Lua records.
Core owns every real zone and record through the PowerDNS API.

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
