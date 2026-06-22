---
title: Quickstart
description: Start CDNLite locally with Docker Compose, check core and edge health, open the dashboard, and configure a first private CDN domain.
---

# Quickstart

This quickstart starts the normal CDNLite Docker Compose topology and points you to the first private CDN workflows: domain, origin, cache rule, WAF rule, SSL, and edge registration.

## Start The Stack

```bash
cp .env.example .env
docker compose up -d --build
curl -fsS http://localhost:8080/health
curl -fsS http://localhost:8081/health
```

Open the dashboard at `http://localhost:8082`.

Local bootstrap credentials are `admin` / `admin`. They are for local development only.

## Check Readiness

```bash
docker compose exec core php artisan cdn:readiness:check
docker compose exec core php artisan cdn:db:status
docker compose exec core php artisan cdn:edge:list
```

## Add A Domain

Use the dashboard domain flow or the API to create a domain, review expected nameservers, and run nameserver verification.

```bash
curl -s -X POST "$API/api/v1/domains/$DOMAIN_ID/nameservers/verify" \
  -H "Authorization: Bearer $TOKEN"
```

For more examples, see [Examples](./examples/).

## Add An Origin

Add a primary origin for your domain, then run health checks from the dashboard or scheduler. Use a backup origin for controlled failover testing.

## Enable Cache

Start with a narrow cache rule for static assets such as images, CSS, JavaScript, and fonts. Then test purge behavior before caching HTML or API responses.

## Create A WAF Rule

Create a simple WAF rule in the dashboard and verify that security events appear in activity views. Keep initial policies conservative so legitimate traffic is not blocked unexpectedly.

## Register An Edge Node

The bundled local stack includes edge services for development. For separate edge hosts, configure the edge agent with its per-edge token, register it with the core API, and confirm heartbeat/config polling.

```bash
sh -n edge/agent/register.sh
sh -n edge/agent/heartbeat.sh
sh -n edge/agent/pull_config.sh
```

## Issue SSL

Use ACME DNS-01 for certificate issuance when DNS is managed by the CDNLite PowerDNS workflow, or import certificates manually for controlled environments.

## Next Steps

- [CDN in a Minute](./cdn-in-a-minute.md)
- [Production Hardening](./production-hardening.md)
- [DNS and Nameservers](./dns.md)
- [Security Model](./security.md)
