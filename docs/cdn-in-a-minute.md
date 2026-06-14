# CDN In A Minute

## Overview

CDNLite is a compact CDN control plane with PostgreSQL, a PHP API, a dashboard, PowerDNS integration, and OpenResty edge POPs. This guide sets up the pieces you need for a small production-shaped deployment: one core server for the API, database, schedulers, and dashboard; a PowerDNS primary for authoritative DNS; and one or more edge POP servers that serve traffic for your domains.

The end state is a working CDN deployment where your dashboard can manage zones, your DNS server can answer for delegated domains, and requests to your edge POP reach the configured origin through CDNLite.

## Prerequisites

Install Docker 24 or newer and Docker Compose v2 on each server. Open firewall ports 80 and 443 on edge POPs, 53 on DNS servers, 8080 and 8082 on the core server if you are not putting them behind a reverse proxy, and 8081 on the PowerDNS primary for API access from trusted networks. You also need a domain name whose NS records you can update at the registrar.

## Step 1 - Core Server

Clone the repository on the control-plane server:

```bash
git clone https://github.com/vaheed/CDNLite.git
cd CDNLite/deploy/core
cp .env.example .env
```

Edit `.env` and replace every `CHANGE_ME` value. At minimum, set `REGISTRY_OWNER`, `POSTGRES_PASSWORD`, `DB_PASSWORD`, `CDNLITE_API_TOKEN`, `CDNLITE_SSL_SECRET_KEY`, `CDNLITE_ORIGIN_SHIELD_SECRET`, `CDNLITE_ACME_CONTACT_EMAIL`, `CDNLITE_CORS_ALLOWED_ORIGINS`, `VITE_CDNLITE_CORE_URL`, `VITE_CDNLITE_EDGE_URL`, `CDNLITE_CDN_ZONE`, `CDNLITE_CDN_PROXY_HOST`, `CDNLITE_NS1_IP`, and `CDNLITE_NS2_IP`.

Start the core stack:

```bash
docker compose up -d
curl -fsS http://CORE_IP:8080/health
```

A JSON response with `"ok":true` means the API process is answering. If the server is behind TLS, use the public API URL you configured instead.

## Step 2 - Create First Admin Account

The dedicated core deployment disables bootstrap users. Create the first dashboard admin manually:

```bash
docker compose exec core php artisan cdn:admin:create --username=admin --password='<STRONG>'
```

Use a long unique password and store it in your normal secrets manager.

## Step 3 - Register An Edge Token

Provision the first POP token on the core server:

```bash
docker compose exec core php artisan cdn:edge:register-token --edge_id=pop-1 --token=<STRONG_RANDOM>
```

The `edge_id` and token go into the edge POP `.env` as `EDGE_ID` and `EDGE_TOKEN`. Reuse neither value for other POPs.

## Step 4 - PowerDNS Primary

On the DNS primary server:

```bash
cd deploy/dnsgeo
cp .env.example .env
```

Set `PDNS_API_KEY` to a long random value and `PDNS_REPLICA_IP` to the secondary DNS server IP if you run one. Then start PowerDNS:

```bash
docker compose up -d
```

The dashboard PowerDNS API URL is `http://POWERDNS_PRIMARY_IP:8081/api/v1`. Restrict that port to trusted hosts.

## Step 5 - Configure PowerDNS In The Dashboard

Open `http://CORE_IP:8082`, log in as the admin user, and go to Settings -> PowerDNS. Enter the primary API URL from Step 4 and the `PDNS_API_KEY` from the primary `.env`, then save and test the connection.

## Step 6 - Deploy An Edge POP

Copy `deploy/edge/` to each POP server. On the POP:

```bash
cd deploy/edge
cp .env.example .env
```

Fill `EDGE_ID`, `EDGE_TOKEN`, `CORE_URL`, `EDGE_HOSTNAME`, and `EDGE_REGION` from Steps 1 and 3. Start the POP and check the proxy:

```bash
docker compose up -d
curl -fsS http://POP_IP/health
```

If the POP uses public ports 80 and 443, keep `EDGE_HOST_PORT=80` and `EDGE_TLS_HOST_PORT=443`.

## Step 7 - Add Your First Site

The current API creates domains at `/api/v1/domains`, then adds origins to that domain. This is the same workflow shown in the examples:

```bash
API=https://api.example.com
TOKEN=CHANGE_ME_LONG_RANDOM_API_TOKEN

curl -s -X POST "$API/api/v1/domains" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name":"Demo","domain":"demo.example.com"}'
```

Copy the returned `data.id` into `DOMAIN_ID`, then add the origin:

```bash
DOMAIN_ID=replace-with-domain-id

curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/origins" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"host":"origin.example.com","scheme":"https","port":443,"role":"primary","enabled":true}'
```

## Step 8 - Verify End-To-End

Send a request to the POP with your CDN hostname:

```bash
curl -H "Host: demo.example.com" http://POP_IP/
```

A `200` response means the edge accepted the hostname, loaded config from core,
and reached the configured origin. If you receive a 404 or 502, check the POP
logs, domain activation state, origin health, and edge heartbeat in the
dashboard:

```bash
docker compose logs -f edge
docker compose exec edge tail -f /var/lib/cdnlite/metrics.ndjson
```

For host-header or TLS/SNI origin issues, verify the origin's `host_header`,
`sni`, `scheme`, `port`, and `preserve_host` settings.

## Next Steps

Read [Security](security.md) and  before sending real traffic. The existing [Operations Runbooks](runbooks/index.md) are also useful for day-two checks.
