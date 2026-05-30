#!/usr/bin/env bash
set -euo pipefail

# Create site
site=$(curl -fsS -X POST http://localhost:8080/api/v1/sites \
  -H 'Content-Type: application/json' \
  -d '{"name":"demo2","domain":"demo2.local","origin_host":"core","origin_port":8080,"proxy_enabled":true}')

echo "$site" | grep -q 'demo2.local'

# Create DNS record
rec=$(curl -fsS -X POST http://localhost:8080/api/v1/sites/1/dns/records \
  -H 'Content-Type: application/json' \
  -d '{"type":"A","name":"@","content":"1.1.1.1","ttl":300,"proxied":true}')
echo "$rec" | grep -q '"type":"A"'

# List DNS records
dns_list=$(curl -fsS http://localhost:8080/api/v1/sites/1/dns/records)
echo "$dns_list" | grep -q '1.1.1.1'

# Edge proxy request succeeds when enabled
code=$(curl -s -o /tmp/e2e_enabled.txt -w '%{http_code}' http://localhost:8081/api/v1/sites -H 'Host: demo.local')
if [[ "$code" -lt 200 || "$code" -ge 400 ]]; then
  echo "e2e: expected successful proxy status, got $code"
  exit 1
fi

# Disable proxy and verify edge no longer routes this host
curl -fsS -X POST http://localhost:8080/api/v1/sites/1/proxy/disable >/dev/null
code_disabled=$(curl -s -o /tmp/e2e_disabled.txt -w '%{http_code}' http://localhost:8081/api/v1/sites -H 'Host: demo.local')
if [[ "$code_disabled" -ne 502 ]]; then
  echo "e2e: expected 502 when proxy disabled, got $code_disabled"
  exit 1
fi

# Re-enable proxy
curl -fsS -X POST http://localhost:8080/api/v1/sites/1/proxy/enable >/dev/null

# Let agent push metrics
sleep 12

nodes=$(curl -fsS http://localhost:8080/api/v1/edge/nodes)
usage=$(curl -fsS http://localhost:8080/api/v1/usage/summary)

echo "$nodes" | grep -q 'edge-local-1'
echo "$usage" | grep -q 'requests_count'

echo "e2e: ok"
