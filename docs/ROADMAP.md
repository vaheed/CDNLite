# CDNLite DNSGeo + PowerDNS ALIAS Roadmap

---

## Progress

Last updated: 2026-06-13

Status legend:

```text
DONE       implemented and validated for the stated scope
PARTIAL    implementation has started but phase acceptance is not complete
PENDING    not started
BLOCKED    cannot proceed until the documented dependency is resolved
```

### Phase status

| Phase | Status | Current progress |
| --- | --- | --- |
| Phase 0 - DNSGeo import and no-profile Compose | PENDING | Root Compose still uses the Python PowerDNS mock behind a `powerdns` profile. |
| Phase 1 - real and verified PowerDNS writes | PARTIAL | Existing real HTTP writes now have bounded retries, exponential backoff, request IDs, hostname normalization, and optional zone read-back verification. Sync state/events, health details, doctor/dry-run/force-sync commands, and real PowerDNS integration validation remain. |
| Phase 2 - desired-state reconciler | PENDING | Core still performs immediate writes from multiple services. |
| Phase 3 - edge state and shared proxy record | PENDING | Existing edge DNS behavior has not been converted to the roadmap shared proxy model. |
| Phase 4 - apex ALIAS and subdomain CNAME | PARTIAL | Existing planner supports ALIAS/CNAME concepts, but the full stable site target and shared proxy model is not implemented or proven against real PowerDNS. |
| Phase 5 - admin and user UI | PENDING | Roadmap-specific DNS status and effective-record UI is not implemented. |
| Phase 6 - tests/e2e/smoke | PARTIAL | Core contract coverage exists for the hardened client; real DNSGeo/PowerDNS, dig, failure-mode, and frontend smoke coverage remain. |
| Phase 7 - production stress and scale proof | PENDING | The 10,000-domain and 10,000,000-record load model has not been run. |

### Completed increments

#### 2026-06-13 - PowerDNS client hardening

Completed:

```text
- retry connection failures, HTTP 429, and HTTP 5xx with bounded exponential backoff
- add a correlation request ID to PowerDNS requests
- normalize ALIAS, CNAME, MX, NS, and PTR hostname content as FQDNs
- optionally verify replacements and deletions by reading the zone back
- add settings and environment controls for verification, retries, delay, and timeout
- add focused Core contract tests
- document retry and verification behavior
```

Validation:

```text
- PHP syntax lint passed
- focused PowerDNS/settings/readiness tests: 11 passed
- complete Core test suite: 123 passed
- git diff --check passed
```

Not yet validated:

```text
- root Compose smoke/e2e
- real PowerDNS or DNSGeo API writes
- ALIAS expansion through a recursive resolver
- production stress tests
```

Next priority:

```text
Replace the profiled Python mock in the normal root Compose topology with the
project DNSGeo/PowerDNS stack, including PostgreSQL, resolver, ALIAS support,
health checks, and real API/dig validation.
```

---

## 0. Final target behavior

When CDNLite is fixed, this must be true:

1. PowerDNS records are really created, updated, deleted, verified, and visible in PowerDNS.
2. Core remains the source of truth; PowerDNS always converges to Core state.
3. `@` apex domains can be proxied using PowerDNS `ALIAS`.
4. Proxied subdomains use `CNAME` to a stable CDN hostname.
5. Edge IP changes do **not** require updating every customer domain/record.
6. DNSGeo is integrated into the CDNLite project as the PowerDNS + GeoDNS + Poweradmin stack.
7. No `docker compose --profile` is required.
8. Admin pages make PowerDNS setup, sync status, zones, records, DNSGeo, and failures easy to see.
9. User pages make proxy DNS behavior simple: proxy on/off, apex ALIAS, subdomain CNAME, validation status.
10. Frontend and backend behavior are aligned.
11. CI, e2e, smoke, and production stress tests prove all DNS paths work.
12. Final stress test covers **10k domains with 1k records each** and verifies that changing one edge IP does not trigger mass updates for proxied customer zones.

---

## 1. Correct DNS model

### 1.1 Do not write edge IPs into every proxied customer record

The scalable model is:

```text
Customer zone: example.com.

@       ALIAS   site-123.cdn.example.net.
www     CNAME   site-123.cdn.example.net.
api     CNAME   site-123.cdn.example.net.
```

CDN zone:

```text
site-123.cdn.example.net.   CNAME   proxy.cdn.example.net.
proxy.cdn.example.net.      LUA A    ifportup(... pickclosest edge pools ...)
proxy.cdn.example.net.      LUA AAAA ifportup(... pickclosest edge pools ...)
```

This gives three benefits:

1. Customer apex uses PowerDNS `ALIAS`, so Core does not flatten apex into IPs.
2. Customer subdomains are normal `CNAME` records.
3. Edge IP changes only update the shared `proxy.cdn.example.net` Lua records, not all customer domains.

### 1.2 Why this is better than per-site Lua records

A per-site Lua record like this:

```text
site-123.cdn.example.net. LUA A "ifportup(443, {{edge ips}}, {selector='pickclosest'})"
```

works, but if every site stores the full edge IP list, then changing one edge IP requires updating every site Lua record. That fails the production stress requirement.

Use this instead:

```text
site-123.cdn.example.net. CNAME proxy.cdn.example.net.
proxy.cdn.example.net.    LUA A/AAAA with all healthy edge pools
```

Only `proxy.cdn.example.net` changes when edge IPs change.

### 1.3 Optional advanced mode

Later, if site-specific DNS health checks are required, add an advanced per-site target mode:

```text
site-123.cdn.example.net. LUA A "ifurlup('https://site-specific-health-url', ...)"
```

But this must be optional because it creates more PowerDNS writes and can hurt large-scale performance.

Default production mode should be **shared edge-pool Lua**.

---

## 2. PowerDNS ALIAS requirements for apex

PowerDNS `ALIAS` needs PowerDNS authoritative to expand the alias when an A/AAAA query arrives.

Required PowerDNS config:

```ini
expand-alias=yes
resolver=<real recursive resolver, not PowerDNS authoritative itself>
```

Important rule:

```text
Do not point resolver to the same authoritative PowerDNS process.
```

Otherwise ALIAS expansion can loop or fail.

For CDNLite + DNSGeo, use one of these options:

### Option A — recommended for production

Run a small local recursive resolver container beside PowerDNS, for example Unbound or PowerDNS Recursor:

```ini
resolver=recursor:5300
expand-alias=yes
```

The recursor must be able to resolve `cdn.<base-domain>` by querying the authoritative DNSGeo/PowerDNS server normally.

### Option B — acceptable for simple setups

Use a trusted external recursive resolver:

```ini
resolver=1.1.1.1:53
expand-alias=yes
```

This is easier, but production control is weaker.

### Acceptance test for ALIAS

For a proxied apex:

```bash
dig @127.0.0.1 example.com A +short
dig @127.0.0.1 example.com AAAA +short
dig @127.0.0.1 site-123.cdn.example.net A +short
dig @127.0.0.1 proxy.cdn.example.net A +short
```

Expected:

```text
example.com A/AAAA resolves through ALIAS to the same effective edge answer as site-123.cdn.example.net.
```

PowerDNS raw zone API should show:

```text
example.com. ALIAS site-123.cdn.example.net.
```

It should not show Core-written apex A/AAAA records for proxied apex unless ALIAS is explicitly disabled as a fallback.

---

## 3. Integrate DNSGeo into CDNLite without Compose profiles

### 3.1 Import strategy

Bring `vaheed/DNSGeo` into CDNLite as one of these:

Preferred:

```text
infra/dnsgeo/
```

Alternative:

```text
services/dnsgeo/
```

Use a Git subtree or vendored copy so CI can run without fetching another repository at runtime.

Recommended command:

```bash
git subtree add --prefix=infra/dnsgeo https://github.com/vaheed/DNSGeo main --squash
```

Future updates:

```bash
git subtree pull --prefix=infra/dnsgeo https://github.com/vaheed/DNSGeo main --squash
```

### 3.2 Compose rule

Do not use:

```bash
docker compose --profile powerdns up -d
```

Use normal startup:

```bash
docker compose build
docker compose up -d
```

The root `docker-compose.yml` in CDNLite should include these services by default for local/dev/e2e:

```text
pdns-postgres-primary
pdns-db-init
pdns-mmdb-updater
pdns-auth
poweradmin
pdns-recursor or unbound
```

For production, allow operators to disable bundled DNS only with an env var or separate override file, not with a profile.

Example env:

```env
CDNLITE_BUNDLED_DNS_ENABLED=true
```

If false, Core uses an external PowerDNS API endpoint.

### 3.3 Required DNSGeo features to keep

From DNSGeo, keep:

```text
PowerDNS Authoritative 5.x
PostgreSQL backend
Lua records
GeoIP/MMDB support
edns-subnet-processing=yes
Poweradmin
MMDB updater
example API scripts adapted into CDNLite tests
```

Also add ALIAS support to DNSGeo config:

```ini
expand-alias=yes
resolver=recursor:5300
```

If DNSGeo does not already include a recursor service, add one.

---

## 4. Environment variables

### 4.1 New canonical env vars

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

### 4.2 Deprecated aliases to keep stable behavior

Keep these as compatibility aliases with warnings:

```env
CDNLITE_EDGE_TTL                    # derive from CDNLITE_SYNC_INTERVAL_SECONDS * 2
CDNLITE_EDGE_HEALTH_INTERVAL        # derive from CDNLITE_SYNC_INTERVAL_SECONDS
CDNLITE_EDGE_APEX_MODE              # alias for CDNLITE_APEX_PROXY_MODE
CDNLITE_EDGE_SELECTOR               # map to Lua selector config
CDNLITE_EDGE_BACKUP_SELECTOR        # map to Lua fallback config
CDNLITE_GEO_*                       # map to DNSGeo/CDN zone config where possible
```

Rules:

```text
If new env is present, it wins.
If only old env is present, use it and show deprecation warning in /cdn-health and admin setup page.
Never allow old and new env to silently conflict.
```

---

## 5. Database changes

### 5.1 `edge_state`

Create a single view/table used by DNS, admin UI, edge runtime config, and tests.

```sql
CREATE VIEW edge_state AS
SELECT
  id AS edge_id,
  public_ip AS ip,
  CASE
    WHEN public_ip LIKE '%:%' THEN 'AAAA'
    ELSE 'A'
  END AS ip_family,
  region,
  anycast_enabled AS anycast,
  CASE
    WHEN is_enabled = true
     AND status = 'online'
     AND health_status = 'healthy'
     AND last_heartbeat_at > EXTRACT(EPOCH FROM NOW()) - 90
    THEN true
    ELSE false
  END AS healthy,
  last_health_check_at AS last_check_at,
  updated_at AS state_updated_at
FROM edge_nodes;
```

Add a real table instead if generation tracking is required:

```sql
CREATE TABLE edge_state_generations (
  id BIGSERIAL PRIMARY KEY,
  state_hash TEXT NOT NULL UNIQUE,
  created_at BIGINT NOT NULL
);
```

### 5.2 `desired_dns_rrsets`

Generated desired state, not user-edited.

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
  generation_id BIGINT,
  desired_hash TEXT NOT NULL,
  created_at BIGINT NOT NULL,
  updated_at BIGINT NOT NULL,
  UNIQUE(zone_name, rrset_name, rrset_type, owner)
);
```

### 5.3 `dns_sync_state`

```sql
CREATE TABLE dns_sync_state (
  id BIGSERIAL PRIMARY KEY,
  zone_name TEXT NOT NULL UNIQUE,
  desired_hash TEXT,
  applied_hash TEXT,
  generation_id BIGINT,
  status TEXT NOT NULL DEFAULT 'unknown',
  last_attempt_at BIGINT,
  last_success_at BIGINT,
  last_error TEXT,
  last_status_code INTEGER,
  pending_changes INTEGER NOT NULL DEFAULT 0,
  in_progress BOOLEAN NOT NULL DEFAULT FALSE,
  updated_at BIGINT NOT NULL
);
```

### 5.4 `dns_sync_events`

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
  generation_id BIGINT,
  created_at BIGINT NOT NULL
);
```

---

## 6. Backend services to implement/change

### 6.1 `PowerDnsClient`

Replace raw/weak HTTP calls with a real client.

Required methods:

```php
health(): PowerDnsHealthResult;
ensureZone(string $zone): PowerDnsResult;
getZone(string $zone, bool $includeRrsets = true): PowerDnsZone;
patchRrsets(string $zone, array $rrsets): PowerDnsResult;
verifyRrsets(string $zone, array $desiredRrsets): PowerDnsVerifyResult;
deleteRrsets(string $zone, array $rrsets): PowerDnsResult;
```

Requirements:

```text
- real HTTP client or cURL wrapper
- connect timeout
- request timeout
- retries for connection errors, HTTP 429, and HTTP 5xx
- no infinite retry for invalid 4xx payloads
- redact API key in logs
- add request/correlation id
- normalize FQDNs
- normalize ALIAS target content
- normalize CNAME target content
- support batch PATCH per zone
- verify after write by reading PowerDNS zone
```

### 6.2 `DnsDesiredStateBuilder`

Build all physical PowerDNS rrsets from Core logical state.

For every proxied site:

```text
customer apex @             => ALIAS site-id.cdn.<base-domain>.
customer subdomain          => CNAME site-id.cdn.<base-domain>.
site-id.cdn.<base-domain>.  => CNAME proxy.cdn.<base-domain>.
```

For CDN shared proxy:

```text
proxy.cdn.<base-domain>. LUA A    ifportup(443, edge IPv4 pools, {selector='pickclosest'})
proxy.cdn.<base-domain>. LUA AAAA ifportup(443, edge IPv6 pools, {selector='pickclosest'})
```

For unproxied records:

```text
publish exactly what user configured, subject to normal DNS validation
```

### 6.3 `DnsReconciler`

One reconciler must handle all sync triggers:

```text
- scheduled tick every CDNLITE_SYNC_INTERVAL_SECONDS
- DNS record create/update/delete
- domain/site proxy mode change
- edge register/update/delete
- edge heartbeat health transition
- admin forced sync
- bootstrap after startup
```

Important:

```text
All triggers call the same reconciler.
No separate edge DNS sync path.
No separate customer DNS sync path.
No hidden PowerDNS write path from UI controllers.
```

### 6.4 `DnsSyncLock`

Add a DB advisory lock or Redis lock.

Rules:

```text
- only one reconciler run at a time
- event-triggered sync can request a run while scheduled sync is active
- queued run executes after current run if desired_hash changed
- never run two overlapping PowerDNS PATCH sequences for the same zone
```

### 6.5 `DnsSyncVerifier`

After PowerDNS PATCH:

```text
1. GET zone from PowerDNS
2. compare every CDNLite-owned rrset
3. run dig checks in e2e/smoke, not in every production request
4. write dns_sync_state
5. write dns_sync_events
```

---

## 7. Config snapshot decision

Current config snapshot is confusing and should not stay as a user-facing feature unless it becomes operationally useful.

### 7.1 Rename concept

Replace “Config Snapshot” with:

```text
Edge Runtime Release
```

### 7.2 What an Edge Runtime Release must show

Admin page:

```text
Release ID
Created time
Created by: auto/manual/deploy
Included domains count
Included routes count
Included WAF/cache/routing rules count
Edge state generation
DNS desired generation
Checksum/hash
Which edges pulled it
Which edges are stale
Diff from previous release
Rollback button
```

User page:

```text
Do not show raw config snapshots.
Show only: “Your site is active on X/Y edges” and “Last edge config deployed at ...”.
```

### 7.3 If this cannot be implemented now

Hide config snapshots from user pages.

Keep only admin/debug API:

```bash
php artisan cdn:edge:release:list
php artisan cdn:edge:release:show <id>
php artisan cdn:edge:release:diff <old> <new>
```

---

## 8. Admin UI requirements

Add an admin DNS setup/status section.

### 8.1 PowerDNS setup page

Fields:

```text
PowerDNS enabled
PowerDNS API base URL
Server ID
API key configured: yes/no, never show key
DNS base domain
CDN zone
CDN proxy host
Apex proxy mode: ALIAS
Bundled DNSGeo enabled: yes/no
Poweradmin URL
Last successful API health check
```

Buttons:

```text
Test PowerDNS API
Ensure base zone
Ensure CDN zone
Force sync now
Dry-run sync
View desired rrsets
View last errors
```

### 8.2 Zone sync page

Table:

```text
Zone
Status
Pending changes
Last attempt
Last success
Last error
Desired hash
Applied hash
Generation
Actions: sync, verify, view desired, view actual
```

### 8.3 DNSGeo page

Show:

```text
PowerDNS auth status
PostgreSQL status
MMDB status
EDNS subnet processing enabled
Lua records enabled
ALIAS expansion enabled
Resolver configured
Poweradmin link
```

Warn if:

```text
expand-alias is disabled
resolver is missing
resolver points to authoritative PowerDNS itself
edns-subnet-processing is disabled
PowerDNS API is publicly exposed
```

---

## 9. User UI requirements

### 9.1 DNS record editor

For each record, show:

```text
Proxy toggle: DNS only / Proxied
Effective DNS result
PowerDNS sync status
Last synced at
Last error if failed
```

### 9.2 Apex proxied domain UX

When user proxies `@`, show:

```text
Your apex will be published as:
@ ALIAS site-123.cdn.example.net.
```

Do not show fake A/AAAA values as if Core owns them.

### 9.3 Subdomain proxied UX

When user proxies `www`, show:

```text
www CNAME site-123.cdn.example.net.
```

### 9.4 Validation page

Show:

```text
Expected record
Actual PowerDNS record
Actual public DNS answer if check is available
Status: ok / pending / failed
```

---

## 10. Frontend alignment checklist

Frontend must match backend exactly.

Remove/hide old options that no longer match backend behavior:

```text
Apex mode: A flattening
Apex mode: normal CNAME
Manual edge IP selection for proxied records
Any UI that says proxied apex is stored as A/AAAA
```

Add/update frontend labels:

```text
Apex proxied = PowerDNS ALIAS
Subdomain proxied = CNAME to CDN
CDN target = site-id.cdn.<base-domain>
Shared edge pool = proxy.cdn.<base-domain>
```

Admin errors must show actionable messages:

```text
PowerDNS API unreachable
PowerDNS API key invalid
PowerDNS zone missing
ALIAS expansion disabled
Resolver missing
Lua records disabled
EDNS subnet processing disabled
MMDB missing
No healthy edges
No IPv6 edges
```

---

## 11. PowerDNS record examples

Assume:

```text
Base domain: example.net
CDN zone: cdn.example.net
Customer domain: customer.com
Site ID: site-123
Shared proxy host: proxy.cdn.example.net
```

### Customer zone

```text
customer.com.      ALIAS   site-123.cdn.example.net.
www.customer.com.  CNAME   site-123.cdn.example.net.
api.customer.com.  CNAME   site-123.cdn.example.net.
```

### CDN zone

```text
site-123.cdn.example.net.  CNAME  proxy.cdn.example.net.

proxy.cdn.example.net. LUA A    "ifportup(443, {{'203.0.113.10','203.0.113.20'}, {'198.51.100.10'}}, {selector='pickclosest'})"
proxy.cdn.example.net. LUA AAAA "ifportup(443, {{'2001:db8::10','2001:db8::20'}, {'2001:db8:1::10'}}, {selector='pickclosest'})"
```

### Anycast priority

If anycast is enabled:

```text
proxy.cdn.example.net. LUA A "ifportup(443, {{'192.0.2.10'}, {'203.0.113.10','203.0.113.20'}, {'198.51.100.10'}}, {selector='pickclosest'})"
```

Ordering rule:

```text
1. anycast shared IPs first
2. same-region healthy unicast next
3. other healthy unicast next
4. stable sort by region, family, IP, edge_id
```

---

## 12. Tests

### 12.1 Unit tests

Add tests for:

```text
PowerDnsClient payload normalization
PowerDnsClient retry behavior
ALIAS rrset generation for @ apex
CNAME rrset generation for subdomains
CDN zone site CNAME generation
shared proxy Lua A generation
shared proxy Lua AAAA generation
edge_state filtering
anycast ordering
stale rrset deletion
sync status update
config env alias/deprecation behavior
```

### 12.2 Integration tests

Use a real PowerDNS/DNSGeo container stack from the normal project Compose setup.

Do not use profiles.

Test:

```text
create zone through Core
create unproxied A record
verify PowerDNS raw zone contains A
create proxied @ record
verify PowerDNS raw zone contains ALIAS
verify dig A for apex returns synthesized edge IP
create proxied www record
verify PowerDNS raw zone contains CNAME
verify dig A for www follows CNAME to edge IP
update record
delete record
verify stale rrsets are removed
```

### 12.3 E2E smoke test script

Create or extend:

```bash
ci/e2e.sh
ci/smoke-powerdns.sh
ci/stress-dns.sh
```

Required e2e flow:

```text
1. Start normal docker compose stack.
2. Wait for Core, PostgreSQL, DNSGeo PostgreSQL, PowerDNS, MMDB updater, Poweradmin.
3. Run PowerDNS doctor.
4. Register two edges:
   - edge-eu, region=eu, IPv4 + optional IPv6
   - edge-us, region=us, IPv4 + optional IPv6
5. Mark both healthy.
6. Create customer domain.
7. Enable proxy for @ and www.
8. Force DNS sync.
9. Verify PowerDNS raw zone:
   - @ has ALIAS to site-id.cdn.<base-domain>
   - www has CNAME to site-id.cdn.<base-domain>
   - site-id.cdn has CNAME to proxy.cdn.<base-domain>
   - proxy.cdn has LUA A/AAAA
10. Verify dig:
   - apex A resolves
   - www A resolves
   - site-id CDN target resolves
   - proxy CDN target resolves
11. Mark one edge unhealthy.
12. Wait one sync interval.
13. Verify proxy.cdn Lua record changed or effective answer excludes unhealthy edge.
14. Verify customer zone did not need mass rewrite.
15. Verify /cdn-health shows powerdns status ok.
```

### 12.4 ALIAS-specific test

```text
Assert @ is ALIAS in PowerDNS raw zone.
Assert @ is not Core-written A/AAAA for proxied apex.
Assert dig A @ returns same answer set as resolving site-id.cdn target at that moment.
Assert ALIAS fails visibly if resolver/expand-alias is disabled.
```

### 12.5 Frontend smoke tests

Use Playwright or existing frontend test framework.

Test admin:

```text
PowerDNS setup page loads
Test API button shows success/failure
Zone sync page shows status
DNSGeo page warns when ALIAS config missing
Force sync button works
```

Test user:

```text
Create domain
Add @ proxied record
UI shows ALIAS target
Add www proxied record
UI shows CNAME target
Disable proxy
UI shows normal DNS-only record
```

---

## 13. Production stress tests

### 13.1 Dataset

Generate:

```text
10,000 domains
1,000 records per domain
10,000,000 logical records total
At least 10% proxied
At least 10% apex proxied
At least 10 edge nodes
At least 3 regions
IPv4 and IPv6 pools where available
```

### 13.2 Critical edge IP change test

Test case:

```text
1. Load dataset.
2. Run full sync.
3. Record number of PowerDNS rrsets changed.
4. Change one edge IP.
5. Run sync.
6. Assert changed rrsets are limited to shared CDN proxy records and required metadata.
7. Assert customer zones are not mass-updated.
```

Expected result:

```text
Changing one edge IP should not rewrite 10,000 domains or 10,000,000 records.
Only proxy.cdn.<base-domain> A/AAAA Lua rrsets should change in normal shared-proxy mode.
```

### 13.3 Performance metrics to collect

```text
Full desired-state build time
Diff time
PowerDNS PATCH count
PowerDNS GET/verify count
Database CPU
Database memory
PowerDNS CPU
PowerDNS memory
Queue lag
Sync duration p50/p95/p99
API error count
Dig latency p50/p95/p99
```

### 13.4 Pass/fail targets

Initial acceptable targets:

```text
Small sync after one edge IP change: < 10 seconds
No customer-zone mass rewrite after edge IP change
PowerDNS sync status remains ok
No overlapping sync jobs
No duplicate rrsets
No stale proxied records after delete
```

Production target after optimization:

```text
Small sync after edge IP change: < 2 seconds
Full sync of 10M logical records: bounded, resumable, observable
PowerDNS API writes batched per affected zone
```

---

## 14. Implementation phases

## Phase 0 — DNSGeo import and no-profile Compose

Goal: DNSGeo runs inside CDNLite with normal `docker compose up -d`.

Tasks:

```text
- import DNSGeo into infra/dnsgeo or services/dnsgeo
- merge required services into root docker-compose.yml
- add PowerDNS authoritative service
- add PostgreSQL backend service
- add MMDB updater service
- add Poweradmin service
- add recursor/unbound service for ALIAS expansion
- enable Lua records
- enable GeoIP/MMDB
- enable edns-subnet-processing=yes
- enable expand-alias=yes
- configure resolver=recursor:5300 or equivalent
- add healthchecks for every DNS service
```

Acceptance:

```bash
docker compose build
docker compose up -d
./scripts/check.sh
php artisan cdn:powerdns:doctor
```

No `--profile` anywhere.

---

## Phase 1 — make PowerDNS writes real and verified

Goal: records truly appear in PowerDNS.

Tasks:

```text
- implement PowerDnsClient
- implement ensureZone
- implement batch rrset PATCH
- implement verify-after-write
- implement dns_sync_state
- implement dns_sync_events
- add /cdn-health powerdns section
- add artisan doctor/dry-run/force-sync commands
```

Acceptance:

```text
Create/update/delete in Core is visible in PowerDNS raw zone API.
Failed PowerDNS writes show failed status.
No mock-only success path.
```

---

## Phase 2 — desired-state reconciler

Goal: one sync path for everything.

Tasks:

```text
- implement DnsDesiredStateBuilder
- implement DnsReconciler
- add sync lock
- route all DNS mutations through reconciler
- remove direct PowerDNS writes from controllers/services
- add stale rrset delete for CDNLite-owned records
```

Acceptance:

```text
Scheduled sync and event sync produce the same desired state.
No double writes.
No stale records after delete.
```

---

## Phase 3 — edge_state and shared proxy CDN record

Goal: edge routing has one source of truth and one shared proxy target.

Tasks:

```text
- add edge_state view/table
- add healthy edge filtering
- add anycast boolean support
- build proxy.cdn.<base-domain> Lua A from edge_state IPv4
- build proxy.cdn.<base-domain> Lua AAAA from edge_state IPv6
- prioritize anycast IPs
- use stable ordering
```

Acceptance:

```text
Edge health change updates only shared CDN proxy records.
PowerDNS Lua answers route to healthy edges.
Unhealthy edge does not remain in generated Lua pool.
```

---

## Phase 4 — apex ALIAS and subdomain CNAME

Goal: proxied customer records use stable names, not edge IPs.

Tasks:

```text
- implement @ apex ALIAS generation
- implement subdomain CNAME generation
- implement site-id.cdn CNAME generation
- remove/default-disable apex A/AAAA flattener
- keep old env alias CDNLITE_EDGE_APEX_MODE with warning
- validate that @ cannot be normal CNAME
```

Acceptance:

```text
@ proxied => ALIAS site-id.cdn.<base-domain>.
www proxied => CNAME site-id.cdn.<base-domain>.
site-id.cdn => CNAME proxy.cdn.<base-domain>.
No edge IPs written into customer proxied records.
```

---

## Phase 5 — admin and user UI

Goal: easy setup and visible status.

Tasks:

```text
- add admin PowerDNS setup page
- add admin DNSGeo status page
- add zone sync status page
- add force sync/dry run buttons
- add user DNS effective-record display
- show ALIAS for apex proxied domains
- show CNAME for subdomain proxied domains
- show sync errors per domain/record
- hide or rename config snapshots
```

Acceptance:

```text
Admin can set up and debug DNS without reading logs.
User can understand exactly what DNS record CDNLite publishes.
Frontend never claims apex is A/AAAA when backend publishes ALIAS.
```

---

## Phase 6 — tests/e2e/smoke

Goal: every important DNS path is covered.

Tasks:

```text
- add unit tests
- add integration tests with real PowerDNS
- add e2e with normal docker compose, no profile
- add dig verification
- add raw PowerDNS zone verification
- add frontend smoke tests
- add failure-mode tests
```

Acceptance:

```text
CI fails if PowerDNS does not really contain the expected records.
CI fails if ALIAS expansion is disabled.
CI fails if proxied apex becomes A/AAAA instead of ALIAS.
CI fails if frontend displays wrong effective DNS behavior.
```

---

## Phase 7 — production stress and scale proof

Goal: prove the design does not rewrite the world.

Tasks:

```text
- generate 10k domains x 1k records
- run full sync
- change one edge IP
- measure changed rrsets
- verify only shared CDN proxy Lua records changed
- measure sync time and PowerDNS load
- run repeated edge health flapping test
- run concurrent user DNS changes during edge updates
```

Acceptance:

```text
Edge IP change does not rewrite all customer domains.
No sync lock deadlocks.
No stale PowerDNS rrsets.
No missing ALIAS/CNAME records.
No frontend/backend mismatch.
PowerDNS remains healthy under stress.
```

---

## 15. Documentation updates

Update:

```text
docs/architecture.md
docs/security.md
docs/dns.md or create it
docs/admin-dns.md or create it
.env.example
.env.production.example
docker-compose.yml
README.md
AGENTS.md checklist compliance notes if needed
```

Documentation must explain:

```text
- DNSGeo integration
- no docker compose profile
- PowerDNS ALIAS for apex
- why ALIAS requires resolver + expand-alias
- why subdomains use CNAME
- why shared proxy.cdn avoids mass updates
- Poweradmin role
- PowerDNS API security
- EDNS Client Subnet behavior
- MMDB updates
- how to run e2e/smoke/stress tests
```

---

## 16. Security requirements

PowerDNS API must not be public.

Required:

```text
- bind API to internal network where possible
- allowlist Core container/IP only
- redact API key from logs and UI
- rotate API key documentation
- show warning if API is publicly reachable
- restrict Poweradmin access
- document HTTPS/VPN/reverse proxy requirements
```

Lua records are powerful. Only Core/admin should create them.

User-created DNS records must not allow arbitrary Lua content.

---

## 17. Definition of done

This roadmap is done only when all are true:

```text
- normal docker compose starts Core + DNSGeo stack without profiles
- PowerDNS API doctor passes
- PowerDNS raw zone shows expected ALIAS/CNAME/LUA records
- dig proves apex ALIAS resolves
- user proxied apex uses ALIAS, not A/AAAA flattening
- user proxied subdomain uses CNAME
- shared proxy.cdn Lua record is generated from edge_state
- edge IP change updates only shared CDN proxy records
- /cdn-health shows sync status
- admin UI shows DNSGeo/PowerDNS health and sync details
- user UI shows effective DNS behavior
- config snapshot is either hidden or replaced by Edge Runtime Release
- tests cover create/update/delete/sync/verify/failure
- e2e uses real PowerDNS/DNSGeo, not mocks
- stress test with 10k domains x 1k records passes scale criteria
```

---

## 18. References

- DNSGeo repository: https://github.com/vaheed/DNSGeo
- PowerDNS ALIAS records: https://doc.powerdns.com/authoritative/guides/alias.html
- PowerDNS Lua records: https://doc.powerdns.com/authoritative/lua-records/
- PowerDNS Lua functions: https://doc.powerdns.com/authoritative/lua-records/functions.html
- PowerDNS HTTP Zone API: https://doc.powerdns.com/authoritative/http-api/zone.html
