# CDNLite v1 Production Roadmap — Clean DNSGeo + PowerDNS Reconciler

> Purpose: make CDNLite credible for private-cloud / production use by replacing the current direct DNS API write model with one automatic, verified desired-state reconciler.
>
> Scope: **no backward compatibility, no migrations, no legacy aliases, no old runtime path**. This roadmap targets a clean first-time install only.

---

## 0. Non-negotiable rules

1. **Core is the source of truth.**
   - PostgreSQL stores logical user/domain/edge state.
   - PowerDNS is an external applied target.
   - PowerDNS must always converge to Core state.

2. **No direct PowerDNS writes from CRUD services or controllers.**
   - Controllers change PostgreSQL only.
   - CRUD services change PostgreSQL only.
   - All DNS publishing goes through `DnsReconciler`.

3. **No separate customer DNS sync and edge DNS sync.**
   - One desired-state builder.
   - One reconciler.
   - One verifier.
   - One sync status model.

4. **No backward compatibility.**
   - Remove old env names.
   - Remove old DNS code paths.
   - Remove old mock/profile DNS stack.
   - Remove old apex flattening behavior.
   - Remove old edge DNS hash-skip system.

5. **No migrations for this stage.**
   - Edit `core/database/schema.sql` directly and remove all old migrations
   - Fresh install only.
   - Developers/operators must use `docker compose down -v` when resetting the stack.

6. **Everything syncs automatically.**
   - Scheduled sync.
   - Event-triggered sync.
   - Startup bootstrap sync.
   - Admin force sync.
   - All use the same reconciler.

7. **Every external write is verified.**
   - PATCH PowerDNS.
   - GET PowerDNS actual zone.
   - Compare desired vs actual.
   - Store sync status and errors.

---

## 1. Final target behavior

After this roadmap is complete:

```text
docker compose up -d
```

starts a complete local/dev/e2e stack:

```text
Core API
Dashboard
Core PostgreSQL
Edge/OpenResty services
PowerDNS Authoritative
PowerDNS PostgreSQL backend
PowerDNS Recursor or Unbound
Poweradmin
MMDB updater
DNS reconciler loop
```

No Compose profiles are required.

DNS behavior:

```text
Customer apex proxied record:
customer.com. ALIAS site-<site-id>.cdn.example.net.

Customer subdomain proxied record:
www.customer.com. CNAME site-<site-id>.cdn.example.net.

CDN site target:
site-<site-id>.cdn.example.net. CNAME proxy.cdn.example.net.

Shared edge pool target:
proxy.cdn.example.net. LUA A    "ifportup(...)"
proxy.cdn.example.net. LUA AAAA "ifportup(...)"
```

Important scale behavior:

```text
Changing one edge IP updates only proxy.cdn.<base-domain> LUA A/AAAA records.
It must not rewrite every customer domain.
It must not rewrite every proxied customer record.
```

---

## 2. Final architecture

```text
User / Admin / API
        |
        v
Logical state in PostgreSQL
(domains, dns_records, edge_nodes, proxy settings)
        |
        v
DnsDesiredStateBuilder
(generates desired physical PowerDNS RRsets)
        |
        v
desired_dns_rrsets
        |
        v
DnsReconciler
(reads desired + reads actual PowerDNS + diffs)
        |
        v
PowerDnsClient
(batch PATCH by zone)
        |
        v
DnsSyncVerifier
(reads PowerDNS again and compares)
        |
        v
dns_sync_state + dns_sync_events
```

Only these services may know how to write PowerDNS:

```text
PowerDnsClient
DnsReconciler
DnsSyncVerifier
```

Everything else must call:

```php
DnsReconciler::requestSync(string $reason, array $context = []): void
```

or the CLI command:

```bash
php artisan cdn:dns:sync
```

---

## 3. Canonical environment variables

Use only these names.

```env
CDNLITE_SYNC_INTERVAL_SECONDS=30

CDNLITE_DNS_PROVIDER=powerdns
CDNLITE_POWERDNS_ENABLED=true
CDNLITE_POWERDNS_API_BASE=http://pdns-auth:8081/api/v1/servers/localhost
CDNLITE_POWERDNS_API_KEY=change-this
CDNLITE_POWERDNS_SERVER_ID=localhost
CDNLITE_POWERDNS_VERIFY_AFTER_WRITE=true
CDNLITE_POWERDNS_RETRIES=3
CDNLITE_POWERDNS_RETRY_SLEEP_MS=250
CDNLITE_POWERDNS_TIMEOUT_SECONDS=10
CDNLITE_POWERDNS_CONNECT_TIMEOUT_SECONDS=3

CDNLITE_DNS_BASE_DOMAIN=example.net
CDNLITE_CDN_ZONE=cdn.example.net
CDNLITE_CDN_PROXY_HOST=proxy.cdn.example.net
CDNLITE_APEX_PROXY_MODE=ALIAS
CDNLITE_SUBDOMAIN_PROXY_MODE=CNAME

CDNLITE_EDGE_HEALTH_PORT=443
CDNLITE_EDGE_MIN_FAILURES=2
CDNLITE_EDGE_UNKNOWN_HEALTH_IS_HEALTHY=false

CDNLITE_BUNDLED_DNS_ENABLED=true
CDNLITE_POWERADMIN_ENABLED=true
```

Remove all old aliases and compatibility names, including:

```text
CDNLITE_EDGE_BASE_DOMAIN
CDNLITE_EDGE_ZONE_PREFIX
CDNLITE_EDGE_DEFAULT_TARGET
CDNLITE_EDGE_TTL
CDNLITE_EDGE_APEX_MODE
CDNLITE_EDGE_SELECTOR
CDNLITE_EDGE_BACKUP_SELECTOR
CDNLITE_GEO_*
```

If any old env name remains referenced in code, tests should fail.

---

## 4. Hard cleanup list

Remove or fully replace these old concepts.

### 4.1 Remove direct DNS publishing from `DnsService`

Remove methods or direct PowerDNS behavior from:

```text
core/app/Modules/Dns/Services/DnsService.php
```

Remove/replace:

```text
syncPowerDnsCreate()
syncPowerDnsDelete()
syncReplacementForIdentity()
rebuildCustomerZones() direct PowerDNS writes
rebuildDomain() direct PowerDNS writes
rebuildGeoDomains() direct PowerDNS writes
```

Final `DnsService` responsibility:

```text
validate input
create logical dns_records rows
update logical dns_records rows
delete logical dns_records rows
request DNS sync
return logical/effective record view
```

It must not call PowerDNS.

### 4.2 Remove old edge DNS writer

Remove or replace:

```text
core/app/Modules/Dns/Services/EdgeDnsService.php
```

Remove concepts:

```text
EdgeDnsService::sync()
EdgeDnsService::bootstrap()
EdgeDnsService::lastHash()
EdgeDnsService::saveHash()
EdgeDnsService::buildEdgeRecords()
edge_dns_state table
```

Reason:

```text
Edge DNS and customer DNS must be generated by the same DnsDesiredStateBuilder and applied by the same DnsReconciler.
```

### 4.3 Remove old PowerDNS wrapper

Replace:

```text
core/app/Modules/Dns/Services/PowerDnsService.php
```

with:

```text
core/app/Modules/Dns/Services/PowerDnsClient.php
```

The old wrapper can be deleted after all callers are moved.

### 4.4 Remove old apex flattening

Remove any behavior where a proxied apex is written as Core-managed `A` or `AAAA`.

Final rule:

```text
@ proxied => ALIAS site-<site-id>.cdn.<base-domain>.
```

### 4.5 Remove per-site edge IP DNS generation

Remove behavior where each site stores full edge IP pools.

Final rule:

```text
site-<site-id>.cdn.<base-domain>. => CNAME proxy.cdn.<base-domain>.
proxy.cdn.<base-domain>. => shared LUA A/AAAA edge pool.
```

### 4.6 Remove mock/profile PowerDNS stack

Remove:

```text
docker compose --profile powerdns ...
mock PowerDNS service as normal dev/e2e path
```

Final rule:

```text
docker compose up -d
```

starts real DNSGeo/PowerDNS services.

---

## 5. Clean schema

Edit only:

```text
core/database/schema.sql
```

No migration files are required in this stage.

### 5.1 Add `edge_state`

Use a view unless generation tracking needs a real table.

```sql
CREATE VIEW edge_state AS
SELECT
  id AS edge_id,
  hostname,
  public_ip,
  public_ipv4,
  public_ipv6,
  region,
  country,
  continent,
  anycast_enabled AS anycast,
  CASE
    WHEN is_enabled = true
     AND status = 'online'
     AND health_status = 'healthy'
     AND last_heartbeat_at > EXTRACT(EPOCH FROM NOW()) - 90
    THEN true
    ELSE false
  END AS healthy,
  last_heartbeat_at,
  last_health_check_at,
  updated_at AS state_updated_at
FROM edge_nodes;
```

The desired-state builder must use `edge_state`, not raw edge-node logic duplicated in DNS code.

### 5.2 Add `desired_dns_rrsets`

Generated desired physical DNS state.

```sql
CREATE TABLE desired_dns_rrsets (
  id BIGSERIAL PRIMARY KEY,
  zone_name TEXT NOT NULL,
  rrset_name TEXT NOT NULL,
  rrset_type TEXT NOT NULL,
  ttl INTEGER NOT NULL,
  records_json JSONB NOT NULL,
  owner TEXT NOT NULL DEFAULT 'cdnlite',
  source TEXT NOT NULL,
  generation BIGINT NOT NULL,
  desired_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(zone_name, rrset_name, rrset_type, owner)
);
```

### 5.3 Add `dns_sync_state`

One row per zone.

```sql
CREATE TABLE dns_sync_state (
  zone_name TEXT PRIMARY KEY,
  desired_hash TEXT,
  applied_hash TEXT,
  observed_hash TEXT,
  generation BIGINT,
  status TEXT NOT NULL DEFAULT 'unknown',
  pending_changes INTEGER NOT NULL DEFAULT 0,
  last_attempt_at BIGINT,
  last_success_at BIGINT,
  last_error TEXT,
  last_status_code INTEGER,
  updated_at BIGINT NOT NULL
);
```

Allowed statuses:

```text
unknown
pending
syncing
ok
drifted
failed
disabled
```

### 5.4 Add `dns_sync_events`

Append-only sync history.

```sql
CREATE TABLE dns_sync_events (
  id BIGSERIAL PRIMARY KEY,
  zone_name TEXT NOT NULL,
  rrset_name TEXT,
  rrset_type TEXT,
  action TEXT NOT NULL,
  status TEXT NOT NULL,
  status_code INTEGER,
  error TEXT,
  desired_hash TEXT,
  applied_hash TEXT,
  observed_hash TEXT,
  generation BIGINT,
  created_at BIGINT NOT NULL
);
```

Allowed actions:

```text
build_desired
ensure_zone
replace_rrset
delete_rrset
verify
dry_run
sync_start
sync_finish
sync_failed
```

### 5.5 Remove old schema

Remove old `edge_dns_state` table and any schema only used by the old direct sync path.

Acceptance:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec core php artisan cdn:db:check
```

---

## 6. DNSGeo integration

### 6.1 Vendor DNSGeo

Import DNSGeo into:

```text
infra/dnsgeo/
```

Recommended:

```bash
git subtree add --prefix=infra/dnsgeo https://github.com/vaheed/DNSGeo main --squash
```

Future updates:

```bash
git subtree pull --prefix=infra/dnsgeo https://github.com/vaheed/DNSGeo main --squash
```

### 6.2 Root Compose services

Root `docker-compose.yml` must start these by default:

```text
pdns-postgres
pdns-auth
pdns-recursor or unbound
pdns-mmdb-updater
poweradmin
dns-reconciler
```

No Compose profile.

### 6.3 PowerDNS Authoritative config

Required:

```ini
api=yes
api-key=${CDNLITE_POWERDNS_API_KEY}
webserver=yes
webserver-address=0.0.0.0
webserver-port=8081

launch=gpgsql
gpgsql-host=pdns-postgres
gpgsql-dbname=powerdns
gpgsql-user=powerdns
gpgsql-password=${POWERDNS_DB_PASSWORD}

lua-records=yes
edns-subnet-processing=yes

expand-alias=yes
resolver=pdns-recursor:5300
```

Important:

```text
The ALIAS resolver must not point back to the same authoritative PowerDNS process.
```

### 6.4 PowerDNS API security

Required:

```text
PowerDNS API is internal-only.
PowerDNS API key is never shown in UI.
PowerDNS API key is redacted from logs.
Poweradmin is protected by private network, VPN, or reverse proxy auth.
Users cannot create arbitrary LUA records.
Only Core/admin code can generate LUA records.
```

Acceptance:

```bash
docker compose up -d --build
php artisan cdn:powerdns:doctor
```

Expected:

```text
PowerDNS API reachable from Core
ALIAS expansion enabled
Resolver configured
Lua records enabled
EDNS subnet processing enabled
Poweradmin reachable on internal/dev URL
```

---

## 7. PowerDnsClient

Create:

```text
core/app/Modules/Dns/Services/PowerDnsClient.php
core/app/Modules/Dns/DTO/PowerDnsResult.php
core/app/Modules/Dns/DTO/PowerDnsZone.php
core/app/Modules/Dns/DTO/PowerDnsRrset.php
```

Required methods:

```php
health(): PowerDnsResult;
ensureZone(string $zone): PowerDnsResult;
getZone(string $zone, bool $includeRrsets = true): PowerDnsZone;
getRrsets(string $zone): array;
patchRrsets(string $zone, array $rrsets): PowerDnsResult;
deleteRrsets(string $zone, array $rrsets): PowerDnsResult;
verifyRrsets(string $zone, array $desiredRrsets): PowerDnsResult;
```

Implementation rules:

```text
Use one HTTP implementation.
Set connect timeout.
Set request timeout.
Retry connection errors, HTTP 429, and HTTP 5xx.
Do not retry invalid 4xx payloads forever.
Redact X-API-Key in logs.
Add request/correlation id.
Normalize FQDNs.
Normalize ALIAS target content.
Normalize CNAME target content.
Normalize record ordering before hashing.
Support batch PATCH per zone.
Verify after write by reading PowerDNS zone.
```

PowerDNS RRset patch shape:

```json
{
  "rrsets": [
    {
      "name": "www.customer.com.",
      "type": "CNAME",
      "ttl": 60,
      "changetype": "REPLACE",
      "records": [
        {
          "content": "site-123.cdn.example.net.",
          "disabled": false
        }
      ]
    }
  ]
}
```

Delete shape:

```json
{
  "rrsets": [
    {
      "name": "old.customer.com.",
      "type": "CNAME",
      "changetype": "DELETE"
    }
  ]
}
```

Acceptance:

```bash
php artisan cdn:powerdns:doctor
php artisan cdn:powerdns:ensure-zone cdn.example.net
php artisan cdn:powerdns:get-zone cdn.example.net
```

---

## 8. DnsDesiredStateBuilder

Create:

```text
core/app/Modules/Dns/Services/DnsDesiredStateBuilder.php
core/app/Modules/Dns/Services/DnsNameFactory.php
core/app/Modules/Dns/Services/DnsRrsetNormalizer.php
```

### 8.1 Inputs

Read logical state from:

```text
domains
dns_records
edge_nodes / edge_state
proxy settings
canonical env vars
```

### 8.2 Output

Write generated records to:

```text
desired_dns_rrsets
```

### 8.3 Naming

Use deterministic stable names.

Recommended:

```text
site-<domain-id>-<record-id>.cdn.<base-domain>.
```

Example:

```text
site-42-1001.cdn.example.net.
```

Do not use mutable domain names inside CDN target hostnames unless the system guarantees uniqueness forever.

### 8.4 Proxied apex

Input:

```text
domain: customer.com
record: @
proxied: true
```

Output:

```text
zone: customer.com.
name: customer.com.
type: ALIAS
content: site-42-1001.cdn.example.net.
```

### 8.5 Proxied subdomain

Input:

```text
domain: customer.com
record: www
proxied: true
```

Output:

```text
zone: customer.com.
name: www.customer.com.
type: CNAME
content: site-42-1002.cdn.example.net.
```

### 8.6 CDN site target

For each proxied logical record:

```text
zone: cdn.example.net.
name: site-42-1002.cdn.example.net.
type: CNAME
content: proxy.cdn.example.net.
```

### 8.7 Shared proxy target

From healthy `edge_state`:

```text
zone: cdn.example.net.
name: proxy.cdn.example.net.
type: LUA A
content: ifportup(443, {{...edge IPv4 pools...}}, {selector='pickclosest'})
```

And, when IPv6 exists:

```text
zone: cdn.example.net.
name: proxy.cdn.example.net.
type: LUA AAAA
content: ifportup(443, {{...edge IPv6 pools...}}, {selector='pickclosest'})
```

Ordering rule:

```text
1. anycast IPs first
2. same-region healthy unicast next if region-aware generation is implemented
3. other healthy unicast next
4. stable sort by region, family, IP, edge_id
```

### 8.8 DNS-only records

If proxied is false:

```text
publish exactly the user-defined DNS record after validation
```

Do not allow user-created `LUA` records.

### 8.9 Hashing

Hash normalized RRsets only.

Normalize:

```text
zone lowercase FQDN
name lowercase FQDN
type uppercase
ttl integer
record contents sorted
trailing dot normalized
disabled=false normalized
```

Acceptance tests:

```text
@ proxied generates ALIAS
www proxied generates CNAME
site target generates CNAME
shared proxy generates LUA A
shared proxy generates LUA AAAA when IPv6 edges exist
unhealthy edge is excluded
anycast edge is ordered first
unproxied record is preserved
users cannot create LUA records
```

---

## 9. DnsReconciler

Create:

```text
core/app/Modules/Dns/Services/DnsReconciler.php
core/app/Modules/Dns/Services/DnsSyncLock.php
core/app/Modules/Dns/Services/DnsDiff.php
core/app/Modules/Dns/Services/DnsSyncVerifier.php
```

### 9.1 Public methods

```php
requestSync(string $reason, array $context = []): void;
run(bool $dryRun = false, ?string $zone = null): DnsSyncResult;
verify(?string $zone = null): DnsSyncResult;
```

### 9.2 Locking

Use PostgreSQL advisory lock.

Global lock:

```sql
SELECT pg_try_advisory_lock(hashtext('cdnlite:dns-reconciler'));
```

Optional per-zone lock:

```sql
SELECT pg_try_advisory_lock(hashtext('cdnlite:dns-zone:' || :zone));
```

Rules:

```text
Only one reconciler run at a time.
No overlapping PowerDNS PATCH sequences for the same zone.
If a sync request arrives while syncing, mark pending and run again if desired hash changed.
```

### 9.3 Reconcile algorithm

```text
1. Acquire lock.
2. Write dns_sync_events: sync_start.
3. Build desired rrsets.
4. Store desired_dns_rrsets.
5. Ensure required PowerDNS zones exist.
6. GET actual PowerDNS rrsets.
7. Compare only CDNLite-owned/managed rrsets.
8. Generate replace list.
9. Generate delete-stale list.
10. If dry-run, store/report pending changes only.
11. PATCH PowerDNS per zone.
12. GET actual PowerDNS rrsets again.
13. Verify actual == desired for CDNLite-owned rrsets.
14. Update dns_sync_state.
15. Write dns_sync_events: sync_finish or sync_failed.
16. Release lock.
```

### 9.4 Managed RRset ownership

CDNLite owns:

```text
customer records created by CDNLite logical dns_records
site-*.cdn.<base-domain>.
proxy.cdn.<base-domain>.
```

CDNLite may delete stale managed records.

CDNLite must not delete unknown external/manual records unless they are under a reserved CDNLite namespace.

### 9.5 Diff rules

Replace when:

```text
desired rrset missing in PowerDNS
desired rrset exists but content/ttl differs
```

Delete when:

```text
PowerDNS has CDNLite-owned rrset that is not in desired_dns_rrsets
```

Do nothing when:

```text
desired hash == observed hash
```

But still support verify mode that reads actual state and detects drift.

Acceptance:

```bash
php artisan cdn:dns:dry-run
php artisan cdn:dns:sync
php artisan cdn:dns:verify
php artisan cdn:dns:sync
```

Expected:

```text
first dry-run shows pending changes
first sync applies changes
verify returns ok
second sync applies zero changes
```

---

## 10. Sync triggers

All these must call `DnsReconciler::requestSync(...)`.

```text
DNS record create
DNS record update
DNS record delete
Domain create
Domain delete
Proxy mode change
Edge registered
Edge updated
Edge deleted
Edge heartbeat changes healthy -> unhealthy
Edge heartbeat changes unhealthy -> healthy
Startup bootstrap
Scheduled interval
Admin force sync
```

No trigger may call PowerDNS directly.

---

## 11. CLI commands

Add or rewrite:

```bash
php artisan cdn:powerdns:doctor
php artisan cdn:dns:dry-run
php artisan cdn:dns:sync
php artisan cdn:dns:verify
php artisan cdn:dns:desired --zone=customer.com
php artisan cdn:dns:actual --zone=customer.com
php artisan cdn:dns:events --zone=customer.com
```

Expected command behavior:

```text
doctor:
  checks PowerDNS API, auth, ALIAS, resolver, LUA, EDNS subnet, zones

dry-run:
  builds desired state and shows planned replace/delete without writing PowerDNS

sync:
  runs full reconciler

verify:
  reads PowerDNS and compares with desired state

desired:
  prints desired_dns_rrsets

actual:
  prints PowerDNS actual rrsets

events:
  prints recent dns_sync_events
```

---

## 12. Scheduler

Add one default service:

```yaml
dns-reconciler:
  build:
    context: .
    dockerfile: core/Dockerfile
  command: >
    sh -c 'while true; do
      php artisan cdn:dns:sync --auto || true;
      sleep ${CDNLITE_SYNC_INTERVAL_SECONDS:-30};
    done'
  depends_on:
    - core-postgres
    - pdns-auth
```

The scheduler may run more than once in HA later because the code lock prevents overlap.

Acceptance:

```text
PowerDNS down -> sync status becomes failed
PowerDNS restored -> automatic next sync converges
DNS record changed -> automatic sync applies it
Edge health changed -> automatic sync updates shared proxy
```

---

## 13. Health endpoints

Update:

```text
/cdn-health
/ready
```

`/cdn-health` must include:

```json
{
  "powerdns": {
    "enabled": true,
    "api": "ok",
    "server_id": "localhost",
    "alias_expansion": "ok",
    "resolver": "ok",
    "lua_records": "ok",
    "edns_subnet": "ok"
  },
  "dns_sync": {
    "status": "ok",
    "zones": 3,
    "failed_zones": 0,
    "pending_changes": 0,
    "last_success_at": 1710000000,
    "last_error": null
  }
}
```

`/ready` should fail if:

```text
PostgreSQL unavailable
Core schema missing
PowerDNS required but unreachable
```

`/ready` may be degraded, not failed, if:

```text
PowerDNS sync failed but edge can still serve from last known good config
```

Choose one consistent readiness policy and document it.

---

## 14. Admin UI

Add pages:

```text
Admin → DNS Setup
Admin → DNSGeo Status
Admin → Zone Sync
Admin → Desired RRsets
Admin → Actual PowerDNS RRsets
Admin → DNS Sync Events
```

### DNS Setup page

Show:

```text
PowerDNS enabled
PowerDNS API base URL
Server ID
API key configured: yes/no only
DNS base domain
CDN zone
CDN proxy host
Apex proxy mode: ALIAS
Subdomain proxy mode: CNAME
Bundled DNSGeo enabled
Poweradmin URL
Last successful API health check
```

Buttons:

```text
Test PowerDNS API
Ensure CDN zone
Force sync now
Dry-run sync
View desired rrsets
View last errors
```

### DNSGeo Status page

Show:

```text
PowerDNS auth status
PowerDNS PostgreSQL status
Recursor/Unbound status
MMDB status
EDNS subnet processing enabled
Lua records enabled
ALIAS expansion enabled
Resolver configured
Poweradmin link
```

Warnings:

```text
expand-alias disabled
resolver missing
resolver points to authoritative PowerDNS itself
edns-subnet-processing disabled
PowerDNS API publicly exposed
Poweradmin publicly exposed
no healthy edges
no IPv6 edges
```

### Zone Sync page

Table:

```text
Zone
Status
Pending changes
Last attempt
Last success
Last error
Desired hash
Observed hash
Applied hash
Generation
Actions: sync, verify, desired, actual, events
```

---

## 15. User UI

Update DNS record editor.

For each record show:

```text
Mode: DNS only / Proxied
Expected published record
Actual PowerDNS record
Sync status
Last synced at
Last error
```

For proxied apex:

```text
Your apex will be published as:
customer.com. ALIAS site-42-1001.cdn.example.net.
```

For proxied subdomain:

```text
Your subdomain will be published as:
www.customer.com. CNAME site-42-1002.cdn.example.net.
```

Remove/hide old UI concepts:

```text
Apex A flattening
Apex normal CNAME
Manual edge IP selection for proxied records
Any text saying proxied apex is stored as A/AAAA
Any text saying edge IPs are written to customer records
```

Config snapshot cleanup:

```text
Hide config snapshots from user pages.
Rename admin concept to Edge Runtime Release only if operationally useful.
```

---

## 16. Tests

### 16.1 Unit tests

Add:

```text
PowerDnsClientTest
DnsNameFactoryTest
DnsRrsetNormalizerTest
DnsDesiredStateBuilderTest
DnsDiffTest
DnsSyncVerifierTest
```

Must test:

```text
FQDN normalization
ALIAS target normalization
CNAME target normalization
PowerDNS PATCH payload generation
PowerDNS retry behavior
API key redaction
@ proxied -> ALIAS
subdomain proxied -> CNAME
site CDN target -> CNAME proxy.cdn
shared proxy Lua A generation
shared proxy Lua AAAA generation
edge_state filters unhealthy edges
anycast ordering
stale rrset delete planning
```

### 16.2 Integration tests

Use real PowerDNS from normal Compose.

No profiles.

Test:

```text
create zone through Core
create unproxied A record
verify PowerDNS raw zone contains A
create proxied @ record
verify PowerDNS raw zone contains ALIAS
verify dig A for apex resolves
create proxied www record
verify PowerDNS raw zone contains CNAME
verify dig A for www resolves
update record
delete record
verify stale rrsets are removed
simulate PowerDNS failure
verify sync_state becomes failed
restore PowerDNS
verify next sync converges
```

### 16.3 E2E scripts

Create or update:

```text
ci/smoke-powerdns.sh
ci/e2e-dns.sh
ci/stress-dns.sh
```

`ci/e2e-dns.sh` flow:

```text
1. docker compose down -v
2. docker compose build
3. docker compose up -d
4. wait for Core, Core DB, PowerDNS DB, PowerDNS Auth, Recursor, Poweradmin
5. run php artisan cdn:powerdns:doctor
6. register two edges:
   - edge-eu, IPv4 and optional IPv6
   - edge-us, IPv4 and optional IPv6
7. mark both healthy
8. create customer domain
9. enable proxy for @ and www
10. run php artisan cdn:dns:sync
11. verify raw PowerDNS:
   - @ has ALIAS
   - www has CNAME
   - site target has CNAME
   - proxy target has LUA A/AAAA
12. verify dig:
   - customer apex A resolves
   - www A resolves
   - site target A resolves
   - proxy target A resolves
13. mark one edge unhealthy
14. wait one sync interval
15. verify only proxy.cdn LUA record changed
16. verify customer zone did not mass rewrite
17. verify /cdn-health shows DNS ok
```

### 16.4 Frontend smoke tests

Test admin:

```text
PowerDNS setup page loads
Test API button shows success/failure
Zone sync page shows status
DNSGeo page warns when ALIAS config missing
Force sync works
Dry-run works
```

Test user:

```text
create domain
add @ proxied record
UI shows ALIAS target
add www proxied record
UI shows CNAME target
disable proxy
UI shows DNS-only record
```

---

## 17. Stress tests

### 17.1 Dataset

Generate:

```text
10,000 domains
1,000 records per domain
10,000,000 logical records total
at least 10% proxied
at least 10% apex proxied
at least 10 edge nodes
at least 3 regions
IPv4 and IPv6 pools where available
```

### 17.2 Critical edge IP change test

Flow:

```text
1. load dataset
2. run full sync
3. record changed RRsets
4. change one edge IP
5. run sync
6. assert changed RRsets are limited to:
   - proxy.cdn.<base-domain> LUA A
   - proxy.cdn.<base-domain> LUA AAAA if IPv6 affected
   - sync metadata
7. assert customer zones are not mass-updated
```

Pass condition:

```text
Changing one edge IP must not rewrite 10,000 domains or 10,000,000 records.
```

### 17.3 Health flap test

Flow:

```text
1. start with 10 healthy edges
2. repeatedly mark one edge unhealthy/healthy
3. run automatic sync
4. verify no overlapping sync jobs
5. verify final PowerDNS state equals final Core edge_state
6. verify no stale edge IP remains in proxy.cdn LUA records
```

### 17.4 Metrics to collect

```text
desired-state build duration
diff duration
PowerDNS PATCH count
PowerDNS GET count
verify duration
sync duration p50/p95/p99
database CPU/memory
PowerDNS CPU/memory
dig latency p50/p95/p99
failed sync count
pending changes count
```

Initial acceptable target:

```text
small sync after one edge IP change < 10 seconds
no customer-zone mass rewrite
no overlapping sync jobs
no duplicate RRsets
no stale proxied records after delete
```

Production target:

```text
small sync after one edge IP change < 2 seconds
full sync is resumable, observable, and bounded
writes are batched per affected zone
```

---

## 18. Documentation updates

Update or create:

```text
README.md
docs/architecture.md
docs/security.md
docs/dns.md
docs/admin-dns.md
.env.example
.env.production.example
docker-compose.yml
```

Docs must explain:

```text
fresh install only for this stage
no backward compatibility
no migrations
DNSGeo integration
normal docker compose startup
PowerDNS ALIAS for apex
why ALIAS requires resolver + expand-alias
why subdomains use CNAME
why shared proxy.cdn avoids mass updates
Poweradmin role
PowerDNS API security
EDNS Client Subnet behavior
MMDB updates
how to run e2e/smoke/stress tests
```

---

## 19. IDE implementation order

Use this exact order.

### Commit 1 — remove old runtime choices

```text
remove mock/profile PowerDNS service
add DNSGeo services to root docker-compose.yml
add pdns-auth
add pdns-postgres
add pdns-recursor or unbound
add pdns-mmdb-updater
add poweradmin
add dns-reconciler service
replace env examples with canonical env only
update README quickstart
```

Acceptance:

```bash
docker compose down -v
docker compose build
docker compose up -d
```

### Commit 2 — clean schema

```text
edit core/database/schema.sql
add edge_state view
add desired_dns_rrsets
add dns_sync_state
add dns_sync_events
remove edge_dns_state
remove old schema pieces used only by direct DNS sync
```

Acceptance:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec core php artisan cdn:db:check
```

### Commit 3 — PowerDnsClient

```text
add PowerDnsClient
add getZone/getRrsets
add patchRrsets
add deleteRrsets
add verifyRrsets
add retries/timeouts/redacted logs
move all PowerDNS HTTP logic into this client
delete old PowerDnsService after replacement
```

Acceptance:

```bash
php artisan cdn:powerdns:doctor
php artisan cdn:powerdns:ensure-zone cdn.example.net
php artisan cdn:powerdns:get-zone cdn.example.net
```

### Commit 4 — desired-state builder

```text
add DnsDesiredStateBuilder
add DnsNameFactory
add DnsRrsetNormalizer
generate customer ALIAS/CNAME records
generate site CDN CNAME records
generate shared proxy LUA A/AAAA records
generate DNS-only records
remove old apex flattening
remove per-site edge IP generation
```

Acceptance:

```bash
vendor/bin/phpunit --filter DnsDesiredStateBuilderTest
```

### Commit 5 — reconciler

```text
add DnsReconciler
add DnsSyncLock
add DnsDiff
add DnsSyncVerifier
write desired_dns_rrsets
write dns_sync_state
write dns_sync_events
implement dry-run
implement verify
implement stale managed RRset delete
```

Acceptance:

```bash
php artisan cdn:dns:dry-run
php artisan cdn:dns:sync
php artisan cdn:dns:verify
```

### Commit 6 — route all mutations through reconciler

```text
DnsService create/update/delete changes DB only
DnsService calls DnsReconciler::requestSync
domain changes call requestSync
edge changes call requestSync
edge health transitions call requestSync
remove direct PowerDNS writes from all old services/controllers
delete EdgeDnsService if empty
```

Acceptance:

```bash
grep -R "PowerDnsClient" core/app
```

Expected:

```text
only DnsReconciler, DnsSyncVerifier, PowerDNS CLI/doctor should use PowerDnsClient
```

### Commit 7 — automatic scheduler and health

```text
add dns-reconciler Compose service
update /cdn-health
update /ready
add powerdns and dns_sync health data
```

Acceptance:

```bash
curl http://localhost:8080/cdn-health
```

### Commit 8 — admin UI

```text
add DNS setup page
add DNSGeo status page
add zone sync page
add desired/actual RRset views
add sync events view
add force sync button
add dry-run button
```

Acceptance:

```bash
cd dash && npm run typecheck && npm run build
```

### Commit 9 — user UI

```text
show effective DNS record
show proxied apex as ALIAS
show proxied subdomain as CNAME
show sync status
show last error
remove old apex flattening UI
remove manual edge IP UI for proxied records
```

Acceptance:

```bash
cd dash && npm run typecheck && npm run build
```

### Commit 10 — tests

```text
add unit tests
add real PowerDNS integration tests
add dig smoke tests
add frontend smoke tests
add failure-mode tests
```

Acceptance:

```bash
ci/smoke-powerdns.sh
ci/e2e-dns.sh
```

### Commit 11 — stress proof

```text
add 10k x 1k dataset generator
add edge IP change test
add edge health flap test
add concurrent DNS changes during sync test
collect sync metrics
```

Acceptance:

```bash
ci/stress-dns.sh
```

---

## 20. Final hard cleanup checklist

Before considering this roadmap done, run:

```bash
grep -R "EdgeDnsService" core/app core/tests
grep -R "syncPowerDns" core/app core/tests
grep -R "edge_dns_state" core
grep -R "CDNLITE_EDGE_APEX_MODE" .
grep -R "CDNLITE_GEO_" .
grep -R "profile.*powerdns" .
grep -R "mock.*PowerDNS" .
```

Expected:

```text
no production/runtime references
no old env references
no old direct sync references
no mock/profile DNS path
```

Allowed references only:

```text
roadmap/docs explaining removed old behavior
test assertions that old behavior is absent
```

Also verify:

```bash
grep -R "PowerDnsClient" core/app
```

Expected allowed callers:

```text
DnsReconciler
DnsSyncVerifier
PowerDNS doctor/CLI commands
PowerDNS setup controller if it only tests/reads, not mutates outside reconciler
```

---

## 21. Definition of done

This roadmap is done only when all are true:

```text
docker compose up -d starts the full real stack
no docker compose profile is needed
PowerDNS API doctor passes
ALIAS expansion works
resolver is not authoritative PowerDNS itself
Lua records work
EDNS subnet processing is enabled
Core creates logical DNS records only
all DNS publishing goes through DnsReconciler
PowerDNS actual state is verified after write
dns_sync_state shows ok/failed/drifted status
dns_sync_events records each sync
proxied apex always publishes ALIAS
proxied subdomain always publishes CNAME
site CDN hostname always CNAMEs to shared proxy
shared proxy owns edge IP/LUA changes
edge IP change does not rewrite customer zones
delete removes stale managed PowerDNS RRsets
PowerDNS outage becomes visible as failed sync
PowerDNS recovery automatically converges
admin UI shows setup/status/errors
user UI shows true effective DNS behavior
tests use real PowerDNS, not mocks
e2e verifies raw PowerDNS and dig results
stress test proves no mass rewrite
old DNS code paths are removed
old env names are removed
old schema tables are removed
```

---

## 22. One-sentence target

Replace CDNLite’s old direct PowerDNS push model with a clean desired-state DNS controller: Core stores truth, the reconciler builds and applies verified PowerDNS state, and the shared CDN proxy record prevents customer-zone mass rewrites.


## 23. References

- DNSGeo repository: https://github.com/vaheed/DNSGeo
- PowerDNS ALIAS records: https://doc.powerdns.com/authoritative/guides/alias.html
- PowerDNS Lua records: https://doc.powerdns.com/authoritative/lua-records/
- PowerDNS Lua functions: https://doc.powerdns.com/authoritative/lua-records/functions.html
- PowerDNS HTTP Zone API: https://doc.powerdns.com/authoritative/http-api/zone.html

