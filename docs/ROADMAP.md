## 1. Architecture

### 1.1 System model

```text
Dashboard / API / CLI
        |
        v
CDNLite PostgreSQL source of truth
  - zones
  - record intents
  - origins
  - edge nodes
  - edge pools
  - edge policies
  - proxy routes
        |
        +-----------------------------+
        |                             |
        v                             v
DNS Compiler                    Proxy Manifest Compiler
        |                             |
        v                             v
Generated authoritative DNS      Signed edge route manifest
output for PowerDNS              pulled by edge agents
        |                             |
        v                             v
PowerDNS Authoritative           OpenResty edge proxy
with Lua records                 Host/SNI based routing
```

### 1.2 Key decision

PowerDNS is the **authoritative DNS engine**, not the source of truth.

CDNLite owns all intent and policy in its own PostgreSQL tables. PowerDNS only receives generated authoritative output from the CDNLite DNS compiler.

### 1.3 Why this is simpler than a custom DNS answerer

Do **not** build a custom DNS answerer first.

Use production PowerDNS features:

- Generic PostgreSQL or PowerDNS-managed backend tables for authoritative serving.
- PowerDNS HTTP API for deterministic zone/RRset replacement.
- PowerDNS Lua records for GeoDNS, health-aware edge answers, and closest-edge selection.
- PowerDNS ALIAS for apex hostname-like behavior.
- A CDNLite compiler/publisher that makes PowerDNS a generated runtime projection.

This avoids an always-online custom DNS microservice while still keeping PostgreSQL/CDNLite as the source of truth.

---

## 2. Delete The Old DNS System

Remove or rewrite these concepts completely.

### 2.1 Delete old DNS service assumptions

Delete or replace:

```text
core/app/Modules/Dns/Services/DnsService.php
core/app/Modules/Dns/Services/DnsPublishingPlanner.php
core/app/Modules/Dns/Services/CustomerDnsService.php
core/app/Modules/Dns/Services/EdgeDnsService.php
core/app/Modules/Dns/Services/EdgeHealthRecordBuilder.php
core/app/Modules/Dns/Services/PowerDnsRecordBuilder.php
```

Keep only a small `PowerDnsClient` if useful, but it must be infrastructure-only and must not contain business rules.

### 2.2 Delete old table meaning

Remove or stop using:

```text
dns_records.proxied
dns_records.public_type
dns_records.public_content
dns_records.origin_type
dns_records.origin_content
dns_records.edge_target
dns_records.routing_policy
dns_records.canonical_edge_hostname
dns_record_geo_routes
domain_routing_settings
edge_dns_state
```

Do not migrate old data automatically unless a separate one-time import tool is explicitly written. The product itself should not support the old model.

### 2.3 Delete old snapshot behavior

The current config snapshot is too broad. Replace it with two separate generated artifacts:

```text
DNS compiled output
  - goes to PowerDNS only
  - contains authoritative zones and RRsets

Proxy route manifest
  - goes to OpenResty edges only
  - contains active proxied hostnames and origin/security/cache/TLS config
```

DNS data should not be shipped to the edge unless the edge needs it for HTTP routing diagnostics.

---

## 3. New Mental Model

### 3.1 Four separate objects

```text
DNS zone
  The domain CDNLite is authoritative for.

DNS record intent
  What the user wants: DNS-only A/TXT/CNAME/ALIAS, or proxied HTTP hostname.

Edge policy
  Which edge IPs should DNS return for proxied records.

Proxy route
  What OpenResty does when a request reaches an edge with Host/SNI.
```

Never collapse these into one row.

### 3.2 DNS modes

Use exactly these user-facing modes:

```text
DNS only
Proxied
```

Internally:

```text
dns_only_real
dns_only_alias
proxied_http
```

Do not expose PowerDNS Lua to normal users.

---

## 4. Production DNS Strategy

### 4.1 DNS-only real records

These are returned directly:

```text
A
AAAA
CNAME
TXT
MX
NS
SRV
CAA
PTR where appropriate
```

Rules:

```text
- CNAME is allowed only below apex.
- CNAME cannot coexist with any other RRSet at the same owner.
- Apex CNAME is rejected.
- TXT escaping is normalized.
- MX/SRV/NS targets must be hostnames.
```

### 4.2 DNS-only ALIAS

This is the Cloudflare-like flattened CNAME behavior.

User sees:

```text
Name: @
Type: ALIAS
Target: app.example.net
Mode: DNS only
```

Compiled DNS:

```text
example.com. ALIAS app.example.net.
```

PowerDNS expands this into A/AAAA answers.

Rules:

```text
- ALIAS is allowed at apex.
- ALIAS may be allowed on subdomains if admin enables it.
- Do not return a literal apex CNAME.
- Configure PowerDNS resolver and expand-alias.
- Prevent resolver loops.
```

### 4.3 Proxied HTTP records

User sees:

```text
Name: @
Mode: Proxied
Target: origin pool
Routing: country-routing
```

Compiled DNS:

```text
@     ALIAS policy-country-routing.edge.cdn.example.net.
www   CNAME policy-country-routing.edge.cdn.example.net.
api   CNAME policy-country-routing.edge.cdn.example.net.
```

The target hostname is a CDNLite service-zone hostname. It resolves to CDN edge IPs using PowerDNS Lua. It never exposes the origin.

### 4.4 GeoDNS with PowerDNS Lua

Use PowerDNS Lua only in the CDNLite service zone, not as arbitrary customer-provided Lua.

Example service zone:

```text
edge.cdn.example.net.
```

Example generated policy hostnames:

```text
policy-global.edge.cdn.example.net.
policy-country-routing.edge.cdn.example.net.
policy-ir-primary.edge.cdn.example.net.
policy-eu-primary.edge.cdn.example.net.
```

Example generated PowerDNS Lua A record:

```text
policy-country-routing.edge.cdn.example.net. 60 IN LUA A "; if country('IR') then return ifportup(443, {'185.142.95.17','185.142.95.18'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2}) elseif continent('EU') then return ifportup(443, {'203.0.113.10','203.0.113.11'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2}) else return ifportup(443, {'198.51.100.10'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2}) end"
```

Generate separate A and AAAA Lua records because PowerDNS Lua record snippets are tied to the declared output query type.

### 4.5 Anycast

Anycast is optional.

If anycast exists:

```text
policy-global.edge.cdn.example.net. A    <anycast IPv4>
policy-global.edge.cdn.example.net. AAAA <anycast IPv6>
```

If anycast does not exist:

```text
policy-global.edge.cdn.example.net. LUA A    "ifportup(...)"
policy-global.edge.cdn.example.net. LUA AAAA "ifportup(...)"
```

GeoDNS and anycast can coexist:

```text
- default policy returns anycast VIPs
- special countries return regional unicast edge IPs
- unhealthy regional pools fall back to global anycast
```

---

## 5. New Database Schema

Use UUIDs and timestamps. These tables replace the old DNS model.

### 5.1 `dns_zones`

```sql
CREATE TABLE dns_zones (
  id UUID PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL CHECK (status IN ('pending','active','suspended','deleted')),
  ns_set_id UUID NULL,
  soa_serial BIGINT NOT NULL DEFAULT 1,
  soa_refresh INTEGER NOT NULL DEFAULT 3600,
  soa_retry INTEGER NOT NULL DEFAULT 600,
  soa_expire INTEGER NOT NULL DEFAULT 604800,
  soa_minimum INTEGER NOT NULL DEFAULT 60,
  dnssec_mode TEXT NOT NULL DEFAULT 'off',
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.2 `dns_record_intents`

```sql
CREATE TABLE dns_record_intents (
  id UUID PRIMARY KEY,
  zone_id UUID NOT NULL REFERENCES dns_zones(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  fqdn TEXT NOT NULL,
  mode TEXT NOT NULL CHECK (mode IN ('dns_only','proxied')),
  kind TEXT NOT NULL CHECK (kind IN ('real','alias','http_proxy')),
  rrtype TEXT NOT NULL,
  content TEXT NULL,
  priority INTEGER NULL,
  ttl INTEGER NOT NULL,
  origin_pool_id UUID NULL,
  edge_policy_id UUID NULL,
  metadata JSONB NOT NULL DEFAULT '{}',
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX dns_record_intents_zone_fqdn_idx ON dns_record_intents(zone_id, fqdn);
CREATE INDEX dns_record_intents_mode_idx ON dns_record_intents(mode);
CREATE INDEX dns_record_intents_edge_policy_idx ON dns_record_intents(edge_policy_id);
CREATE INDEX dns_record_intents_origin_pool_idx ON dns_record_intents(origin_pool_id);
```

Important: CNAME exclusivity is enforced in the validator/service layer and tested heavily.

### 5.3 `origin_pools`

```sql
CREATE TABLE origin_pools (
  id UUID PRIMARY KEY,
  zone_id UUID NOT NULL REFERENCES dns_zones(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  mode TEXT NOT NULL DEFAULT 'failover'
    CHECK (mode IN ('failover','weighted')),
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.4 `origins`

```sql
CREATE TABLE origins (
  id UUID PRIMARY KEY,
  origin_pool_id UUID NOT NULL REFERENCES origin_pools(id) ON DELETE CASCADE,
  address TEXT NOT NULL,
  scheme TEXT NOT NULL DEFAULT 'https' CHECK (scheme IN ('http','https')),
  port INTEGER NOT NULL,
  host_header TEXT NULL,
  sni TEXT NULL,
  tls_verify BOOLEAN NOT NULL DEFAULT TRUE,
  weight INTEGER NOT NULL DEFAULT 100,
  priority INTEGER NOT NULL DEFAULT 0,
  health_status TEXT NOT NULL DEFAULT 'unknown'
    CHECK (health_status IN ('healthy','unhealthy','unknown')),
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.5 `edge_nodes`

```sql
CREATE TABLE edge_nodes (
  id UUID PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  hostname TEXT NOT NULL,
  site_code TEXT NOT NULL,
  country_code CHAR(2) NULL,
  region TEXT NULL,
  latitude DOUBLE PRECISION NULL,
  longitude DOUBLE PRECISION NULL,
  ipv4 INET[] NOT NULL DEFAULT '{}',
  ipv6 INET[] NOT NULL DEFAULT '{}',
  public_http_ports INTEGER[] NOT NULL DEFAULT '{80}',
  public_https_ports INTEGER[] NOT NULL DEFAULT '{443}',
  status TEXT NOT NULL DEFAULT 'active'
    CHECK (status IN ('active','draining','disabled','deleted')),
  health_status TEXT NOT NULL DEFAULT 'unknown'
    CHECK (health_status IN ('healthy','unhealthy','unknown')),
  last_heartbeat_at TIMESTAMPTZ NULL,
  version BIGINT NOT NULL DEFAULT 1,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

Changing `ipv4`, `ipv6`, `status`, or `health_status` must increment `version` and mark dependent edge policies dirty.

### 5.6 `edge_pools`

```sql
CREATE TABLE edge_pools (
  id UUID PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  mode TEXT NOT NULL DEFAULT 'geo'
    CHECK (mode IN ('geo','anycast','weighted','failover')),
  status TEXT NOT NULL DEFAULT 'active'
    CHECK (status IN ('active','disabled')),
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.7 `edge_pool_members`

```sql
CREATE TABLE edge_pool_members (
  edge_pool_id UUID NOT NULL REFERENCES edge_pools(id) ON DELETE CASCADE,
  edge_node_id UUID NOT NULL REFERENCES edge_nodes(id) ON DELETE CASCADE,
  weight INTEGER NOT NULL DEFAULT 100,
  priority INTEGER NOT NULL DEFAULT 0,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY(edge_pool_id, edge_node_id)
);
```

### 5.8 `edge_policies`

```sql
CREATE TABLE edge_policies (
  id UUID PRIMARY KEY,
  name TEXT NOT NULL UNIQUE,
  mode TEXT NOT NULL CHECK (mode IN ('anycast','geodns','weighted','failover')),
  fallback_edge_pool_id UUID NOT NULL REFERENCES edge_pools(id),
  default_ttl INTEGER NOT NULL DEFAULT 60,
  min_ttl INTEGER NOT NULL DEFAULT 30,
  max_ttl INTEGER NOT NULL DEFAULT 300,
  version BIGINT NOT NULL DEFAULT 1,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.9 `edge_policy_rules`

```sql
CREATE TABLE edge_policy_rules (
  id UUID PRIMARY KEY,
  edge_policy_id UUID NOT NULL REFERENCES edge_policies(id) ON DELETE CASCADE,
  priority INTEGER NOT NULL DEFAULT 100,
  match_type TEXT NOT NULL CHECK (match_type IN ('country','continent','cidr','default')),
  match_value TEXT NULL,
  edge_pool_id UUID NOT NULL REFERENCES edge_pools(id),
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL,
  updated_at TIMESTAMPTZ NOT NULL
);
```

### 5.10 Compiled DNS output tables

These are generated, not user-edited.

```sql
CREATE TABLE dns_compile_runs (
  id UUID PRIMARY KEY,
  status TEXT NOT NULL CHECK (status IN ('running','compiled','published','failed')),
  input_hash TEXT NOT NULL,
  output_hash TEXT NULL,
  error TEXT NULL,
  started_at TIMESTAMPTZ NOT NULL,
  finished_at TIMESTAMPTZ NULL
);

CREATE TABLE dns_compiled_rrsets (
  id UUID PRIMARY KEY,
  compile_run_id UUID NOT NULL REFERENCES dns_compile_runs(id) ON DELETE CASCADE,
  zone_name TEXT NOT NULL,
  name TEXT NOT NULL,
  rrtype TEXT NOT NULL,
  ttl INTEGER NOT NULL,
  records JSONB NOT NULL,
  source_kind TEXT NOT NULL,
  source_id UUID NULL,
  content_hash TEXT NOT NULL,
  created_at TIMESTAMPTZ NOT NULL
);

CREATE UNIQUE INDEX dns_compiled_rrsets_identity_idx
  ON dns_compiled_rrsets(compile_run_id, zone_name, name, rrtype);
```

### 5.11 Proxy route manifest tables

```sql
CREATE TABLE proxy_manifest_runs (
  id UUID PRIMARY KEY,
  status TEXT NOT NULL CHECK (status IN ('running','compiled','active','failed')),
  content_hash TEXT NULL,
  manifest_json JSONB NULL,
  error TEXT NULL,
  started_at TIMESTAMPTZ NOT NULL,
  finished_at TIMESTAMPTZ NULL
);

CREATE TABLE proxy_manifest_state (
  id SMALLINT PRIMARY KEY CHECK (id = 1),
  active_run_id UUID NULL REFERENCES proxy_manifest_runs(id),
  active_version BIGINT NOT NULL DEFAULT 0,
  updated_at TIMESTAMPTZ NOT NULL
);
```

---

## 6. DNS Compiler

### 6.1 Compiler responsibility

The compiler converts source-of-truth intent into legal authoritative RRsets.

Input:

```text
dns_zones
dns_record_intents
edge_nodes
edge_pools
edge_policies
edge_policy_rules
platform nameservers
service zone settings
```

Output:

```text
dns_compiled_rrsets
PowerDNS zones
PowerDNS RRsets
PowerDNS metadata
```

### 6.2 Compiler rules

#### Zone base

Every active zone gets:

```text
SOA
NS
customer DNS-only records
customer proxied records as CNAME/ALIAS to service-zone policy hostnames
```

#### Service zone

The CDNLite service zone gets:

```text
SOA
NS
policy hostnames
Lua A/AAAA records for GeoDNS policies
static A/AAAA records for anycast policies
```

Example:

```text
edge.cdn.example.net. SOA ...
edge.cdn.example.net. NS ns1.cdn.example.net.
policy-country-routing.edge.cdn.example.net. LUA A "..."
policy-country-routing.edge.cdn.example.net. LUA AAAA "..."
policy-global.edge.cdn.example.net. A 203.0.113.10
```

#### DNS-only real

```text
api.example.com. A 192.0.2.10
www.example.com. CNAME app.hosting.net.
```

#### DNS-only alias

```text
example.com. ALIAS app.hosting.net.
```

#### Proxied apex

```text
example.com. ALIAS policy-country-routing.edge.cdn.example.net.
```

#### Proxied subdomain

```text
www.example.com. CNAME policy-country-routing.edge.cdn.example.net.
```

### 6.3 Generated Lua policy structure

The generated Lua must be deterministic and built only from validated edge policies.

For country routing:

```lua
; if country('IR') then
    return ifportup(443, {'185.142.95.17','185.142.95.18'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2})
  elseif continent('EU') then
    return ifportup(443, {'203.0.113.10','203.0.113.11'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2})
  else
    return ifportup(443, {'198.51.100.10'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2})
  end
```

For simple global GeoDNS:

```lua
ifportup(443, {'203.0.113.10','203.0.113.11','198.51.100.10'}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2})
```

For failover:

```lua
ifportup(443, {{'203.0.113.10','203.0.113.11'}, {'198.51.100.10'}}, {selector='pickclosest', timeout=1, interval=10, minimumFailures=2})
```

### 6.4 Lua safety rules

- Do not allow user-written Lua.
- Generate Lua from structured `edge_policies`.
- Escape all strings.
- Limit maximum generated record length.
- Use configuration include records only for service-zone internal records, not customer zones.
- Enable Lua records only for the CDNLite service zone unless absolutely required.
- Keep Lua records out of zone transfers unless secondaries support them.
- Document that PowerDNS Lua is a PowerDNS-specific feature.

---

## 7. PowerDNS Publisher

### 7.1 PowerDNS integration mode

Use PowerDNS HTTP API for publishing zones/RRsets.

The publisher does:

```text
1. Read latest compiled RRsets.
2. Ensure PowerDNS zone exists.
3. Ensure Lua metadata exists for service zone.
4. Apply RRset diffs.
5. Increment zone serial.
6. Verify readback from PowerDNS API.
7. Mark compile run published.
```

### 7.2 RRset replacement rule

Always replace whole RRsets, never write one record at a time.

Payload shape:

```json
{
  "rrsets": [
    {
      "name": "policy-global.edge.cdn.example.net.",
      "type": "LUA",
      "ttl": 60,
      "changetype": "REPLACE",
      "records": [
        {
          "content": "A \"ifportup(443, {'203.0.113.10'}, {selector='pickclosest'})\"",
          "disabled": false
        }
      ]
    }
  ]
}
```

For multi-value A/AAAA:

```json
{
  "rrsets": [
    {
      "name": "policy-anycast.edge.cdn.example.net.",
      "type": "A",
      "ttl": 60,
      "changetype": "REPLACE",
      "records": [
        {"content": "203.0.113.10", "disabled": false},
        {"content": "203.0.113.11", "disabled": false}
      ]
    }
  ]
}
```

### 7.3 No direct writes from API actions

The dashboard/API must never call PowerDNS directly.

Flow:

```text
API writes intent
  -> mark DNS dirty
  -> compiler builds desired output
  -> publisher applies output
  -> verification checks PowerDNS
```

### 7.4 Failure behavior

If publishing fails:

```text
- keep previous PowerDNS state
- mark compile run failed
- show error in dashboard
- retry with backoff
- do not claim record is published
```

This is production-safe because PowerDNS keeps serving the last good state.

---

## 8. Proxy Route Manifest

### 8.1 Purpose

The edge manifest replaces the old broad config snapshot.

It contains only what OpenResty needs to serve HTTP/S traffic.

### 8.2 Manifest shape

```json
{
  "schema": 1,
  "version": 1042,
  "generated_at": 1760000000,
  "routes": {
    "example.com": {
      "zone_id": "uuid",
      "record_intent_id": "uuid",
      "origin_pool": {
        "mode": "failover",
        "origins": [
          {
            "address": "origin.example.com",
            "scheme": "https",
            "port": 443,
            "host_header": "example.com",
            "sni": "origin.example.com",
            "tls_verify": true,
            "priority": 0,
            "weight": 100
          }
        ]
      },
      "cache": {},
      "waf": {},
      "headers": {},
      "tls": {}
    },
    "www.example.com": {
      "zone_id": "uuid",
      "record_intent_id": "uuid",
      "origin_pool": {
        "mode": "failover",
        "origins": []
      }
    }
  }
}
```

### 8.3 Compiler rules

Generate one route per active proxied intent.

```text
proxied @     -> example.com
proxied www   -> www.example.com
proxied api   -> api.example.com
proxied *.cdn -> wildcard route if implemented
```

Do not select only one primary record per zone.

### 8.4 Edge runtime behavior

OpenResty should:

```text
- load manifest atomically
- route by normalized Host/SNI
- select origin from origin pool
- preserve configured Host header behavior
- apply cache/WAF/headers/TLS rules
- expose manifest version in /ready
```

### 8.5 Edge manifest is not DNS

Do not include DNS-only records in the proxy manifest.

DNS-only records are PowerDNS concern only.

---

## 9. API Replacement

Do not add `/api/v2`.

Replace existing DNS APIs with the new semantics.

### 9.1 Zones

```text
GET    /api/v1/zones
POST   /api/v1/zones
GET    /api/v1/zones/{zone}
PATCH  /api/v1/zones/{zone}
DELETE /api/v1/zones/{zone}
```

### 9.2 Records

```text
GET    /api/v1/zones/{zone}/records
POST   /api/v1/zones/{zone}/records
PATCH  /api/v1/zones/{zone}/records/{record}
DELETE /api/v1/zones/{zone}/records/{record}
```

DNS-only A:

```json
{
  "name": "api",
  "mode": "dns_only",
  "kind": "real",
  "rrtype": "A",
  "content": "192.0.2.10",
  "ttl": 300
}
```

DNS-only ALIAS:

```json
{
  "name": "@",
  "mode": "dns_only",
  "kind": "alias",
  "rrtype": "ALIAS",
  "content": "app.hosting.net",
  "ttl": 60
}
```

Proxied record:

```json
{
  "name": "@",
  "mode": "proxied",
  "kind": "http_proxy",
  "rrtype": "PROXIED",
  "origin_pool_id": "uuid",
  "edge_policy_id": "uuid",
  "ttl": 60
}
```

### 9.3 Edge and policy APIs

```text
GET    /api/v1/edge-nodes
POST   /api/v1/edge-nodes
PATCH  /api/v1/edge-nodes/{edge}

GET    /api/v1/edge-pools
POST   /api/v1/edge-pools
PATCH  /api/v1/edge-pools/{pool}

GET    /api/v1/edge-policies
POST   /api/v1/edge-policies
PATCH  /api/v1/edge-policies/{policy}

POST   /api/v1/edge-policies/{policy}/rules
PATCH  /api/v1/edge-policies/{policy}/rules/{rule}
DELETE /api/v1/edge-policies/{policy}/rules/{rule}
```

### 9.4 Simulation APIs

```text
POST /api/v1/dns/simulate
POST /api/v1/proxy/simulate
GET  /api/v1/dns/health
GET  /api/v1/proxy/manifest
```

DNS simulate example:

```json
{
  "qname": "example.com",
  "qtype": "A",
  "resolver_ip": "1.1.1.1",
  "country": "IR"
}
```

Output:

```json
{
  "zone": "example.com",
  "intent": "proxied @",
  "compiled_record": "example.com. ALIAS policy-country-routing.edge.cdn.example.net.",
  "edge_policy": "country-routing",
  "selected_pool": "ir-primary",
  "generated_powerdns_record": "LUA A",
  "answers": ["185.142.95.17"],
  "ttl": 60
}
```

---

## 10. Central Validator

Use one validator for API, CLI, dashboard, imports, tests, and compiler.

### 10.1 Name rules

```text
- normalize zones to lowercase without trailing dot
- accept @ as apex
- accept relative names like www/api
- accept full FQDN only if inside the zone
- reject records outside the zone
- normalize fqdn without trailing dot in source tables
- add trailing dot only in compiled PowerDNS output
```

### 10.2 Record rules

```text
A      => valid IPv4
AAAA   => valid IPv6
CNAME  => non-apex hostname target only
ALIAS  => hostname target
TXT    => safe string
MX     => priority + hostname target
SRV    => priority + weight + port + hostname target
CAA    => flag/tag/value
NS     => admin-only at apex unless delegation feature is implemented
PROXIED => internal only; never a real DNS type
```

### 10.3 CNAME rules

```text
- @ CNAME rejected
- CNAME cannot coexist with any other RRSet at same name
- no other RRSet can coexist with CNAME at same name
```

### 10.4 Proxy rules

```text
- Proxied allowed only for HTTP/S hostnames.
- MX/TXT/NS/SRV/CAA cannot be proxied.
- Proxied record requires origin_pool_id.
- Proxied record requires edge_policy_id.
- Proxied record never stores edge IPs.
- Proxied record never publishes origin hostname/IP.
```

### 10.5 Lua policy rules

```text
- edge policy must have fallback pool
- every referenced edge pool must have at least one enabled node
- generated Lua must have legal IPv4/IPv6 lists
- A and AAAA are compiled separately
- disabled/deleted nodes are excluded
- draining nodes are included only if policy allows emergency fallback
```

---

## 11. Dashboard Replacement

### 11.1 DNS records page

Columns:

```text
Name
Mode
Type / Kind
Target
Routing
TTL
Status
Actions
```

Examples:

```text
@       Proxied    PROXY   origin-pool-main     country-routing   60
www     Proxied    PROXY   origin.example.com   global            60
@       DNS only   ALIAS   app.hosting.net      flattened         60
api     DNS only   A       192.0.2.10           direct            300
@       DNS only   TXT     "v=spf1 ..."         direct            300
```

### 11.2 Add record flow

Step 1:

```text
Mode:
- DNS only
- Proxied
```

If DNS only:

```text
Type:
A, AAAA, CNAME, ALIAS, TXT, MX, NS, SRV, CAA
```

If proxied:

```text
Origin:
- origin hostname
- origin IP
- existing origin pool

Routing:
- global
- GeoDNS
- anycast
- failover
```

### 11.3 Edge policy page

Must manage:

```text
edge nodes
edge pools
edge policy rules
default/fallback pool
generated Lua preview
policy health
```

Show generated Lua preview for admins only.

### 11.4 DNS simulator page

Inputs:

```text
qname
qtype
resolver IP
country override
continent override
```

Output:

```text
intent selected
compiled customer RRSet
service-zone policy record
Lua policy branch
edge pool
edge node/IP candidates
final answer
TTL
warnings
```

---

## 12. CLI Commands

Add or replace:

```bash
php artisan cdn:dns:compile
php artisan cdn:dns:publish
php artisan cdn:dns:publish --dry-run
php artisan cdn:dns:simulate example.com A --country=IR
php artisan cdn:dns:validate-all
php artisan cdn:dns:check-zone example.com
php artisan cdn:edge-policy:compile country-routing
php artisan cdn:edge-policy:validate country-routing
php artisan cdn:edge-ip:update edge-ir-01 --ipv4=185.142.95.18
php artisan cdn:proxy:compile-manifest
php artisan cdn:proxy:manifest-status
```

Production rule:

```text
Every command must return non-zero exit status on invalid config.
```

---

## 13. Health And Readiness

### 13.1 Core readiness

```text
database reachable
source-of-truth schema migrated
service zone configured
PowerDNS API reachable
PowerDNS Lua enabled for service zone
PowerDNS ALIAS resolver configured
latest DNS compile published
latest proxy manifest active
```

### 13.2 DNS readiness

```text
SOA lookup works
NS lookup works
service-zone Lua A lookup works
service-zone Lua AAAA lookup works if IPv6 configured
ALIAS expansion works
test proxied apex resolves
test proxied subdomain resolves
```

### 13.3 Edge readiness

```text
manifest exists
manifest schema accepted
manifest age below threshold
edge can route known host
origin health checks are current
TLS cert loader works
```

---

## 14. Implementation Phases

Every phase must leave the repository testable. No phase is a production shortcut. The first several phases may intentionally break the old application until the replacement is complete.

---

### Phase 0: Remove Old Assumptions

Goal: make the repository stop pretending old DNS is correct.

Tasks:

```text
- inventory every DNS class, route, controller, dashboard page, test, CLI command, and doc
- delete or quarantine old DNS services
- delete old PowerDNS planner behavior
- delete old edge DNS Lua generator
- delete old proxied boolean model
- mark old dashboard DNS pages for replacement
- write failing tests for required new behavior
```

Deliverables:

```text
- deletion checklist
- failing tests:
  - apex CNAME rejected
  - proxied @ does not publish origin
  - proxied www creates proxy route
  - edge IP change does not touch dns_record_intents
```

---

### Phase 1: New Schema And Domain Services

Goal: source-of-truth model exists.

Tasks:

```text
- add new schema tables
- create ZoneService
- create RecordIntentService
- create OriginPoolService
- create EdgeInventoryService
- create EdgePolicyService
- create central DnsIntentValidator
- create audit events
- replace old seed/bootstrap DNS data
```

Deliverables:

```text
- unit tests for validation
- unit tests for edge policy model
- no direct PowerDNS writes from domain services
```

---

### Phase 2: DNS Compiler

Goal: compile source-of-truth intent into legal authoritative DNS output.

Tasks:

```text
- implement DnsCompiler
- implement SOA/NS compiler
- implement DNS-only real compiler
- implement ALIAS compiler
- implement proxied customer record compiler
- implement service-zone policy hostname compiler
- implement LuaPolicyCompiler for GeoDNS
- store compiled RRsets in dns_compiled_rrsets
- add dry-run diff output
```

Deliverables:

```text
- compile customer zones
- compile service zone
- compile deterministic Lua records
- compile output hash stable across no-op runs
```

---

### Phase 3: PowerDNS Publisher

Goal: PowerDNS serves generated output.

Tasks:

```text
- implement PowerDnsClient as infrastructure only
- implement PowerDnsPublisher
- ensure zones
- enable service-zone Lua metadata
- configure resolver and expand-alias
- publish RRset diffs with REPLACE
- delete stale RRsets not in compiled output
- verify readback
- update docker-compose powerdns profile
```

Deliverables:

```text
- PowerDNS answers SOA/NS
- PowerDNS answers DNS-only A/TXT/CNAME
- PowerDNS expands ALIAS
- PowerDNS returns Lua-based GeoDNS answers
```

---

### Phase 4: Proxy Route Manifest

Goal: OpenResty receives a clean route manifest generated from proxied intents.

Tasks:

```text
- implement ProxyManifestCompiler
- generate one route per proxied intent
- remove DNS-only records from edge config
- update edge agent to pull new manifest
- update config_loader.lua to validate manifest schema
- update router.lua to route by manifest routes
- expose manifest version in /ready
```

Deliverables:

```text
- proxied @ route works
- proxied www route works
- Host/SNI routing works
- old generic config snapshot removed or unused
```

---

### Phase 5: GeoDNS With Lua

Goal: production GeoDNS is driven by edge policies and PowerDNS Lua.

Tasks:

```text
- implement country rules
- implement continent rules
- implement default/fallback rules
- compile A and AAAA Lua separately
- support ifportup health-aware answers
- support pickclosest selector
- support anycast static answers
- add generated Lua preview
- add simulator explanation
```

Deliverables:

```text
- IR resolver returns IR edge pool
- EU resolver returns EU edge pool
- unknown resolver returns fallback pool
- unhealthy edge falls back
- edge IP change updates only edge_nodes and service-zone policy output
```

---

### Phase 6: API Replacement

Goal: dashboard/API only speak the new model.

Tasks:

```text
- replace old DNS endpoints
- replace old origin endpoints if needed
- replace old edge policy/routing endpoints
- add DNS simulate endpoint
- add proxy simulate endpoint
- update OpenAPI docs
- remove old proxy toggle semantics
```

Deliverables:

```text
- API can create DNS-only A
- API rejects @ CNAME
- API creates @ ALIAS
- API creates proxied @
- API creates proxied www
- API triggers compile/publish workflow
```

---

### Phase 7: Dashboard Replacement

Goal: operators can manage production DNS/proxy correctly.

Tasks:

```text
- replace raw DNS row UI
- add mode-based DNS create/edit UI
- add ALIAS UX
- add proxied record UX
- add origin pool UI
- add edge node/pool/policy UI
- add DNS simulator UI
- add PowerDNS publish status UI
- remove normal-user Lua exposure
```

Deliverables:

```text
- dashboard can manage DNS-only records
- dashboard can manage proxied records
- dashboard can manage GeoDNS policies
- dashboard shows generated Lua only to admins
- dashboard surfaces publish failures
```

---

### Phase 8: Production Tests

Goal: prove the replacement works.

Unit tests:

```text
- normalize zone names
- reject records outside zone
- reject @ CNAME
- allow @ ALIAS
- reject CNAME coexistence
- reject proxied MX/TXT/NS/SRV/CAA
- compile proxied apex as ALIAS to service policy hostname
- compile proxied subdomain as CNAME to service policy hostname
- compile country GeoDNS Lua
- compile anycast static service records
```

Integration tests:

```text
- PowerDNS API zone ensure
- PowerDNS RRset REPLACE
- PowerDNS Lua enabled
- PowerDNS ALIAS expansion
- PowerDNS answers DNS-only A
- PowerDNS answers DNS-only CNAME
- PowerDNS answers proxied apex A through ALIAS
- PowerDNS answers proxied www A through CNAME -> Lua target
```

E2E tests:

```text
1. create zone test.com
2. create DNS-only A api.test.com -> 192.0.2.10
3. dig api.test.com A returns 192.0.2.10
4. attempt @ CNAME returns validation error
5. create @ ALIAS app.example.net
6. dig test.com A returns flattened answer
7. create edge nodes IR/EU/US
8. create GeoDNS edge policy
9. create proxied @ using the policy
10. dig test.com A with IR resolver fixture returns IR edge
11. dig test.com A with EU resolver fixture returns EU edge
12. update IR edge IP
13. assert dns_record_intents unchanged
14. publish DNS
15. dig returns new IR IP
16. curl edge with Host: test.com returns origin response
```

Scale test:

```text
- seed 10,000 zones
- seed 10,000 proxied records using same edge policy
- change one edge IP
- assert only edge node + compile/publish state changes
- assert customer dns_record_intents unchanged
- assert service-zone policy RRSet changed
- run 1,000 simulated DNS lookups
```

---

### Phase 9: Production Hardening

Goal: safe operations.

Tasks:

```text
- structured logs for compile/publish
- audit events for all DNS/policy/edge changes
- retry/backoff for publisher
- PowerDNS API timeout handling
- alert when latest compile is unpublished
- alert when PowerDNS readback differs
- alert when service-zone Lua is disabled
- alert when ALIAS resolver misconfigured
- runbooks
```

Deliverables:

```text
- operational docs
- restore procedure
- rollback to last compiled DNS run
- rollback to last proxy manifest
- production readiness checklist
```

---

## 15. Exact File/Module Layout

Use names without `v2`.

```text
core/app/Modules/Dns/Domain/
  Zone.php
  RecordIntent.php
  DnsMode.php
  RecordKind.php

core/app/Modules/Dns/Services/
  ZoneService.php
  RecordIntentService.php
  DnsIntentValidator.php
  DnsCompiler.php
  LuaPolicyCompiler.php
  DnsCompileRepository.php
  PowerDnsPublisher.php

core/app/Modules/Dns/Infrastructure/
  PowerDnsClient.php

core/app/Modules/Edge/Services/
  EdgeInventoryService.php
  EdgePoolService.php
  EdgePolicyService.php
  EdgePolicyValidator.php

core/app/Modules/Proxy/Services/
  ProxyManifestCompiler.php
  ProxyManifestRepository.php

edge/openresty/lua/
  config_loader.lua
  router.lua
  origin_selector.lua
```

Do not name folders or classes `V2`.

---

## 16. Acceptance Criteria

The replacement is complete only when all are true:

```text
- old DNS model is removed from production paths
- no backward compatibility code remains
- PostgreSQL CDNLite tables are the source of truth
- PowerDNS is generated authoritative runtime only
- raw apex CNAME is rejected
- ALIAS works for apex flattening
- proxied apex does not create real CNAME
- proxied records never expose origin in DNS
- proxied records never store edge IPs as durable content
- GeoDNS is implemented with generated PowerDNS Lua records
- Lua is generated only from trusted structured edge policies
- edge IP change does not rewrite customer record intents
- proxy manifest contains every active proxied hostname
- OpenResty routes by Host/SNI using the manifest
- DNS-only records are standards-compliant
- PowerDNS readback verification works
- dashboard is mode-based
- CI includes unit, integration, e2e, docs, and scale tests
```

---

## 17. Validation Commands

Final implementation must provide equivalent commands:

```bash
docker compose config --quiet
find core -name '*.php' -print0 | xargs -0 -n1 php -l
pytest -q core/tests
cd dash && npm ci && npm run typecheck && npm test && npm run build
cd docs && npm ci && npm run docs:build
docker compose up -d --build --wait
./ci/smoke.sh
./ci/e2e.sh
./ci/powerdns_dns_checks.sh
./ci/dns_compile_checks.sh
./ci/dns_geo_lua_checks.sh
./ci/proxy_manifest_checks.sh
./ci/dns_scale_10k.sh
```

---

## 18. Implementation Principle

Do not patch the old system.

Replace it with:

```text
intent tables
central validator
DNS compiler
PowerDNS publisher
generated Lua GeoDNS service zone
proxy manifest compiler
OpenResty host/SNI routing
mode-based dashboard
full tests
```

Keep it simple:

```text
No custom DNS answerer process.
No Remote Backend in the first production design.
No per-request database lookup for DNS answers.
No per-domain Lua hardcoding.
No edge IPs stored in customer record intents.
No old compatibility layer.
```

PowerDNS serves DNS. CDNLite owns truth. Lua does GeoDNS. OpenResty does proxying.
