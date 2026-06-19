# Examples

These examples are copy-friendly starting points. Replace IDs, domains, tokens, and IPs with your own values.

## API Domain Workflow

```bash
API=http://localhost:8080
TOKEN=replace-with-token

curl -s -X POST "$API/api/v1/domains" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Example","domain":"example.test"}'
```

```bash
DOMAIN_ID=replace-with-domain-id

curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/dns/records" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"A","name":"www","content":"203.0.113.10","ttl":300,"proxied":true}'
```

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/origins" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"host":"origin.example.test","scheme":"http","port":80,"role":"origin","enabled":true}'
```

## CLI Domain Workflow

```bash
docker compose exec core php artisan cdn:domain:create \
  --domain=example.test \
  --name=Example

docker compose exec core php artisan cdn:domain:list
docker compose exec core php artisan cdn:domain:verify-ns --domain_id="$DOMAIN_ID"
docker compose exec core php artisan cdn:domain:activate --domain_id="$DOMAIN_ID" --override=1
```

## Cache Workflow

```bash
curl -s -X PUT "$API/api/v1/domains/$DOMAIN_ID/cache/settings" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"default_ttl":"60s","query_string_mode":"ignore"}'
```

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/cache/purge" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"scope":"prefix","value":"https://example.test/assets/"}'
```

## Redirect Workflow

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/redirects" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"source_path":"/old","target_url":"https://example.test/new","status_code":301,"enabled":true}'
```

## WAF Example

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/waf-rules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Block admin probes","match_type":"path_contains","pattern":"/wp-admin","action":"block","enabled":true}'
```

## Header Preset Example

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/headers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"HSTS","header_name":"Strict-Transport-Security","header_value":"max-age=31536000; includeSubDomains","enabled":true}'
```

## Edge Token Workflow

```bash
docker compose exec core php artisan cdn:edge:register-token \
  --edge_id=edge-local-1 \
  --token=edge-dev-token
```

## PowerDNS Workflow

```bash
docker compose up -d --build
docker compose exec core php artisan cdn:powerdns:doctor
docker compose exec core php artisan cdn:powerdns:dry-run
docker compose exec core php artisan cdn:powerdns:force-sync
./ci/powerdns_dns_checks.sh
```

## Analytics Recalculation

```bash
curl -s -X POST "$API/api/v1/usage/recalculate" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"bucket":"hour"}'
```

## Verify And Activate A Lab Domain

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/verify-nameservers" \
  -H "Authorization: Bearer $TOKEN"

curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/activate" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"override":true}'
```

Local lab tip: `override:true` is useful when you are testing fake domains such as `example.test`. Do not use it as the normal production activation path.

## DNS Migration Example

Use this when moving a real website into CDNLite without breaking mail or verification records.

```bash
# HTTP traffic through the CDN.
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/dns/records" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"A","name":"@","content":"203.0.113.10","ttl":120,"proxied":true}'

curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/dns/records" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"CNAME","name":"www","content":"example.com","ttl":120,"proxied":true}'

# Mail and verification stay DNS-only.
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/dns/records" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type":"TXT","name":"@","content":"v=spf1 include:_spf.example.net ~all","ttl":300,"proxied":false}'
```

The apex input keeps its origin type, but public DNS publishes it as `ALIAS`
to `site-<domain-id>.cdn.<zone>`. The proxied `www` record publishes `CNAME`
to that same stable site target.

Cutover checklist:

- Lower TTL before migration.
- Create records in CDNLite.
- Verify nameserver delegation.
- Activate the domain.
- Send test traffic with a `Host` header.
- Watch edge logs and analytics before raising TTL.

## Test Edge Routing With Host Header

```bash
curl -i http://localhost:8081/ \
  -H 'Host: example.test'
```

Expected headers may include:

```text
X-CDNLITE-Cache: MISS
X-CDNLITE-Origin: primary
```

Repeat the request to check cache behavior. A second request may become `HIT` when the response is cacheable and no bypass headers are present.

## Cache Debugging Example

```bash
curl -i http://localhost:8081/assets/app.css \
  -H 'Host: example.test'

curl -i http://localhost:8081/assets/app.css \
  -H 'Host: example.test' \
  -H 'Cache-Control: no-cache'
```

If the no-cache request bypasses cache but a normal request does not, the edge is behaving as expected.

## Redirect Test Example

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/redirects/test" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"path":"/old","scheme":"https","host":"example.test"}'
```

## Safer WAF Rollout

Start in observe mode when the backend supports logging actions:

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/waf-rules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Observe admin probes","match_type":"path_contains","pattern":"/wp-admin","action":"log","enabled":true}'
```

Switch from `log` to `block` only after the security event stream shows the rule is not catching legitimate traffic.

## Security Header Set

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/headers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Frame protection","header_name":"X-Frame-Options","header_value":"DENY","enabled":true}'

curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/headers" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"MIME sniffing protection","header_name":"X-Content-Type-Options","header_value":"nosniff","enabled":true}'
```

## IP Access Example

Block a noisy subnet:

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/ip-rules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Block noisy subnet","cidr":"198.51.100.0/24","action":"block","enabled":true}'
```

Allow a private monitoring subnet:

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/ip-rules" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Allow monitors","cidr":"203.0.113.0/28","action":"allow","enabled":true}'
```

## Rate Limit Example

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/rate-limits" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Login protection","path":"/login","threshold":60,"window_seconds":60,"action":"block","enabled":true}'
```

Start with a generous threshold, then tune from security events and application logs.

## SSL Request Example

```bash
JOB_ID="$(
  curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/ssl/request" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"hostnames":["example.test","*.example.test"]}' \
    | jq -r '.data.job_id'
)"

curl -s "$API/api/v1/domains/$DOMAIN_ID/ssl/jobs/$JOB_ID" \
  -H "Authorization: Bearer $TOKEN" \
  | jq

curl -s "$API/api/v1/domains/$DOMAIN_ID/ssl/acme-status" \
  -H "Authorization: Bearer $TOKEN"
```

Use staging ACME first. The request endpoint queues a scheduler job; keep `ssl-scheduler` running and poll the job until it reaches `issued` or `failed`.

## Signed Edge Config Pull Sketch

```bash
EDGE_ID=edge-local-1
EDGE_TOKEN=edge-dev-token
METHOD=GET
PATH_ONLY=/api/v1/edge/config
BODY_HASH=$(printf '' | openssl dgst -sha256 -binary | xxd -p -c 256)
TS=$(date +%s)
NONCE=$(openssl rand -hex 16)
CANONICAL=$(printf "%s\n%s\n%s\n%s\n%s" "$METHOD" "$PATH_ONLY" "$TS" "$NONCE" "$BODY_HASH")
KEY=$(printf '%s' "$EDGE_TOKEN" | openssl dgst -sha256 -binary | xxd -p -c 256)
SIG=$(printf '%s' "$CANONICAL" | openssl dgst -sha256 -mac HMAC -macopt "hexkey:$KEY" -binary | xxd -p -c 256)

curl -s "$API$PATH_ONLY" \
  -H "Authorization: Bearer $EDGE_TOKEN" \
  -H "X-CDNLITE-Edge-Id: $EDGE_ID" \
  -H "X-CDNLITE-Timestamp: $TS" \
  -H "X-CDNLITE-Nonce: $NONCE" \
  -H "X-CDNLITE-Signature: $SIG"
```

The edge agent scripts already do this; the example is for developers writing custom edge tooling.

## Config Snapshot Rollback

```bash
curl -s "$API/api/v1/config/snapshots" \
  -H "Authorization: Bearer $TOKEN"

curl -s -X POST "$API/api/v1/config/snapshots/diff" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"from_version":12,"to_version":13}'

curl -s -X POST "$API/api/v1/config/snapshots/12/rollback" \
  -H "Authorization: Bearer $TOKEN"
```

Rollback is a control-plane action. Edge nodes still need to pull the active snapshot.

## OpenAPI Client Generation

The OpenAPI document is available at [OpenAPI YAML](../api/openapi.yaml).

Example with `openapi-generator-cli`:

```bash
openapi-generator-cli generate \
  -i docs/public/api/openapi.yaml \
  -g typescript-fetch \
  -o /tmp/cdnlite-client
```

Generated clients are useful for control-plane endpoints. Keep edge HMAC signing as a custom helper so you can hash the exact raw body that will be sent.
Verification activates the domain automatically. Records created before this
step remain saved and begin publishing once delegation is verified.
